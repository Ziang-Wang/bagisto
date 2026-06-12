<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Core\Models\Channel;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Models\Product;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Migrates historical orders from the legacy WooCommerce store (HPOS tables).
 *
 * Historical orders are inserted directly into the Bagisto tables inside a
 * transaction rather than going through OrderRepository: re-playing the
 * checkout flow would mutate live stock, fire events and recompute totals,
 * which is exactly what we do NOT want when back-filling past sales. Totals,
 * line items, addresses and the payment record are copied verbatim.
 *
 * Idempotent through the `woo_import_maps` table (entity = order).
 */
class OrderMigrator
{
    /**
     * Map WooCommerce order statuses onto Bagisto's allowed order statuses.
     */
    const STATUS_MAP = [
        'wc-completed' => 'completed',
        'wc-processing' => 'processing',
        'wc-on-hold' => 'pending',
        'wc-pending' => 'pending',
        'wc-cancelled' => 'canceled',
        'wc-refunded' => 'closed',
        'wc-failed' => 'canceled',
        'trash' => 'canceled',
    ];

    /**
     * Map WooCommerce payment gateway codes onto a Bagisto payment method that
     * is actually registered in config('payment_methods'). Unknown gateways
     * fall back to "moneytransfer". This matters because the admin order view
     * resolves the method through that config, and an unregistered code makes
     * `app(null)` blow up.
     */
    const PAYMENT_MAP = [
        'stripe' => 'moneytransfer',
        'ppcp-gateway' => 'moneytransfer',
        'paypal' => 'paypal_standard',
        'cod' => 'cashondelivery',
        'bacs' => 'moneytransfer',
        'cheque' => 'moneytransfer',
        'offline' => 'moneytransfer',
    ];

    protected string $channelName;

    protected int $channelId;

    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping
    ) {
        $channel = core()->getDefaultChannel();

        $this->channelId = (int) ($channel->id ?? config('woo-importer.defaults.channel_id', 1));
        $this->channelName = (string) ($channel->name ?? 'Default');
    }

    /**
     * Run the order migration.
     */
    public function migrate(Command $console): void
    {
        try {
            $orders = $this->woo->table('wc_orders')
                ->where('type', 'shop_order')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            $console->warn('  WooCommerce order tables not found — skipping orders.');

            return;
        }

        if ($orders->isEmpty()) {
            $console->warn('  No orders found in the legacy store.');

            return;
        }

        $bar = $console->getOutput()->createProgressBar($orders->count());
        $bar->start();

        $migrated = $skipped = 0;

        foreach ($orders as $wooOrder) {
            if ($this->mapping->get(Mapping::ENTITY_ORDER, $wooOrder->id)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            try {
                DB::transaction(function () use ($wooOrder) {
                    $this->migrateOrder($wooOrder);
                });

                $migrated++;
            } catch (\Throwable $e) {
                $console->newLine();
                $console->error("  ✗ Order #{$wooOrder->id}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $console->newLine();
        $console->info("  Orders migrated: {$migrated} — skipped (already imported): {$skipped}");
    }

    /**
     * Migrate a single order and all of its related records.
     */
    protected function migrateOrder(object $wooOrder): void
    {
        $addresses = $this->woo->table('wc_order_addresses')
            ->where('order_id', $wooOrder->id)
            ->get()
            ->keyBy('address_type');

        $billing = $addresses->get('billing');
        $shipping = $addresses->get('shipping') ?: $billing;

        $items = $this->orderItems($wooOrder->id);

        $itemCount = count($items);
        $qtyOrdered = array_sum(array_column($items, 'qty_ordered'));
        $subTotal = array_sum(array_column($items, 'total'));

        $grandTotal = (float) $wooOrder->total_amount;
        $taxAmount = (float) ($wooOrder->tax_amount ?? 0);
        $shippingAmount = $this->orderShippingTotal($wooOrder->id);
        $discountAmount = max(0, ($subTotal + $taxAmount + $shippingAmount) - $grandTotal);

        $currency = $wooOrder->currency ?: core()->getBaseCurrencyCode();

        $customerEmail = strtolower(trim((string) $wooOrder->billing_email));
        $customerId = $customerEmail ? $this->mapping->get(Mapping::ENTITY_CUSTOMER, $customerEmail) : null;

        $firstName = $billing->first_name ?? 'Guest';
        $lastName = $billing->last_name ?? 'Customer';

        $orderId = DB::table('orders')->insertGetId([
            'increment_id' => (string) $wooOrder->id,
            'status' => $this->mapStatus($wooOrder->status),
            'channel_name' => $this->channelName,
            'is_guest' => $customerId ? 0 : 1,
            'customer_email' => $customerEmail ?: null,
            'customer_first_name' => $firstName,
            'customer_last_name' => $lastName,
            'shipping_method' => 'flatrate_flatrate',
            'shipping_title' => 'Flat Rate - Flat Rate',
            'shipping_description' => 'Imported shipping',
            'total_item_count' => $itemCount,
            'total_qty_ordered' => $qtyOrdered,
            'base_currency_code' => $currency,
            'channel_currency_code' => $currency,
            'order_currency_code' => $currency,
            'grand_total' => $grandTotal,
            'base_grand_total' => $grandTotal,
            'sub_total' => $subTotal,
            'base_sub_total' => $subTotal,
            'tax_amount' => $taxAmount,
            'base_tax_amount' => $taxAmount,
            'shipping_amount' => $shippingAmount,
            'base_shipping_amount' => $shippingAmount,
            'discount_amount' => $discountAmount,
            'base_discount_amount' => $discountAmount,
            'customer_id' => $customerId,
            'customer_type' => $customerId ? Customer::class : null,
            'channel_id' => $this->channelId,
            'channel_type' => Channel::class,
            'created_at' => $wooOrder->date_created_gmt,
            'updated_at' => $wooOrder->date_updated_gmt ?: $wooOrder->date_created_gmt,
        ]);

        $insertedItems = [];

        foreach ($items as $item) {
            $child = $item['_child'] ?? null;
            unset($item['_child']);

            $item['order_id'] = $orderId;
            $item['created_at'] = $wooOrder->date_created_gmt;
            $item['updated_at'] = $wooOrder->date_created_gmt;

            $parentItemId = DB::table('order_items')->insertGetId($item);

            // Track inserted parent items so we can build invoice items for
            // completed orders below.
            $insertedItems[] = ['id' => $parentItemId] + $item;

            // Configurable line items need a child order_item that points at the
            // ordered variant (a simple product). Bagisto's order view / shipment
            // screens read $item->child->product, so a missing child crashes them.
            if ($child) {
                $child['order_id'] = $orderId;
                $child['parent_id'] = $parentItemId;
                $child['created_at'] = $wooOrder->date_created_gmt;
                $child['updated_at'] = $wooOrder->date_created_gmt;

                DB::table('order_items')->insert($child);
            }
        }

        $this->insertAddress($orderId, $customerId, 'order_billing', $billing, $customerEmail, 0);
        $this->insertAddress($orderId, $customerId, 'order_shipping', $shipping, $customerEmail, 1);

        DB::table('order_payment')->insert([
            'order_id' => $orderId,
            'method' => $this->mapPaymentMethod($wooOrder->payment_method),
            'method_title' => $wooOrder->payment_method_title ?: ($wooOrder->payment_method ?: 'Offline'),
            'created_at' => $wooOrder->date_created_gmt,
            'updated_at' => $wooOrder->date_created_gmt,
        ]);

        // Completed orders get a paid invoice so the sales reports / dashboard
        // reflect real revenue (Bagisto aggregates sales from invoices).
        if ($this->mapStatus($wooOrder->status) === 'completed') {
            $this->createInvoice($orderId, $wooOrder, $insertedItems, [
                'sub_total' => $subTotal,
                'grand_total' => $grandTotal,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $discountAmount,
                'qty' => $qtyOrdered,
                'currency' => $currency,
            ]);
        }

        $this->mapping->put(Mapping::ENTITY_ORDER, $wooOrder->id, $orderId, [
            'increment_id' => (string) $wooOrder->id,
            'status' => $this->mapStatus($wooOrder->status),
        ]);
    }

    /**
     * Create a paid invoice for a completed order and back-fill the order's
     * "*_invoiced" totals so Bagisto's reporting recognises the revenue.
     *
     * @param  array<int, array<string, mixed>>  $insertedItems
     * @param  array<string, mixed>  $totals
     */
    protected function createInvoice(int $orderId, object $wooOrder, array $insertedItems, array $totals): void
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'increment_id' => (string) $wooOrder->id,
            'state' => 'paid',
            'email_sent' => 0,
            'total_qty' => $totals['qty'],
            'base_currency_code' => $totals['currency'],
            'channel_currency_code' => $totals['currency'],
            'order_currency_code' => $totals['currency'],
            'sub_total' => $totals['sub_total'],
            'base_sub_total' => $totals['sub_total'],
            'grand_total' => $totals['grand_total'],
            'base_grand_total' => $totals['grand_total'],
            'shipping_amount' => $totals['shipping_amount'],
            'base_shipping_amount' => $totals['shipping_amount'],
            'tax_amount' => $totals['tax_amount'],
            'base_tax_amount' => $totals['tax_amount'],
            'discount_amount' => $totals['discount_amount'],
            'base_discount_amount' => $totals['discount_amount'],
            'order_id' => $orderId,
            'created_at' => $wooOrder->date_created_gmt,
            'updated_at' => $wooOrder->date_created_gmt,
        ]);

        foreach ($insertedItems as $item) {
            DB::table('invoice_items')->insert([
                'name' => $item['name'],
                'sku' => $item['sku'],
                'qty' => $item['qty_ordered'],
                'price' => $item['price'],
                'base_price' => $item['base_price'],
                'total' => $item['total'],
                'base_total' => $item['base_total'],
                'product_id' => $item['product_id'],
                'product_type' => $item['product_type'],
                'order_item_id' => $item['id'],
                'invoice_id' => $invoiceId,
                'created_at' => $wooOrder->date_created_gmt,
                'updated_at' => $wooOrder->date_created_gmt,
            ]);
        }

        // Back-fill the order's invoiced totals so reports count the revenue.
        DB::table('orders')->where('id', $orderId)->update([
            'grand_total_invoiced' => $totals['grand_total'],
            'base_grand_total_invoiced' => $totals['grand_total'],
            'sub_total_invoiced' => $totals['sub_total'],
            'base_sub_total_invoiced' => $totals['sub_total'],
            'tax_amount_invoiced' => $totals['tax_amount'],
            'base_tax_amount_invoiced' => $totals['tax_amount'],
            'shipping_invoiced' => $totals['shipping_amount'],
            'base_shipping_invoiced' => $totals['shipping_amount'],
        ]);
    }

    /**
     * Build Bagisto order_items rows from the legacy line items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function orderItems(int $wooOrderId): array
    {
        $lineItems = $this->woo->table('woocommerce_order_items')
            ->where('order_id', $wooOrderId)
            ->where('order_item_type', 'line_item')
            ->get(['order_item_id', 'order_item_name']);

        $rows = [];

        foreach ($lineItems as $line) {
            $meta = $this->itemMeta($line->order_item_id);

            $wooProductId = (int) ($meta['_variation_id'] ?? 0) ?: (int) ($meta['_product_id'] ?? 0);
            $bagistoProductId = $wooProductId ? $this->mapping->get(Mapping::ENTITY_PRODUCT, $wooProductId) : null;

            // Variations map to their parent simple/configurable product id.
            if (! $bagistoProductId && ! empty($meta['_product_id'])) {
                $bagistoProductId = $this->mapping->get(Mapping::ENTITY_PRODUCT, (int) $meta['_product_id']);
            }

            $qty = max(1, (int) ($meta['_qty'] ?? 1));
            $lineTotal = (float) ($meta['_line_total'] ?? 0);
            $unitPrice = $qty > 0 ? round($lineTotal / $qty, 4) : $lineTotal;

            $product = $bagistoProductId ? DB::table('products')->find($bagistoProductId) : null;

            $row = [
                'sku' => $product->sku ?? ('imported-'.$line->order_item_id),
                'type' => $product->type ?? 'simple',
                'name' => $line->order_item_name,
                'qty_ordered' => $qty,
                'qty_invoiced' => $qty,
                'price' => $unitPrice,
                'base_price' => $unitPrice,
                'total' => $lineTotal,
                'base_total' => $lineTotal,
                'product_id' => $bagistoProductId,
                // `product_type` is the morph type for the polymorphic
                // order_items.product relation, so it must hold the product
                // MODEL class — not the catalogue type (simple/configurable),
                // which lives in the `type` column above.
                'product_type' => $bagistoProductId ? Product::class : null,
                'additional' => json_encode(['imported_from_woo' => true]),
            ];

            // For configurable products, attach a child item pointing at the
            // ordered variant so Bagisto's order/shipment views can resolve
            // $item->child->product.
            if ($product && $product->type === 'configurable') {
                $row['_child'] = $this->buildChildItem($product, $meta, $qty, $unitPrice, $lineTotal, $line->order_item_name);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Build the child order_item for a configurable line item, pointing at the
     * ordered variant. The variant is resolved from the legacy `_variation_id`
     * when it was migrated, otherwise the product's first variant is used.
     *
     * @param  array<string, string>  $meta
     * @return array<string, mixed>
     */
    protected function buildChildItem(object $parentProduct, array $meta, int $qty, float $unitPrice, float $lineTotal, string $name): array
    {
        $variantId = null;

        if (! empty($meta['_variation_id'])) {
            $variantId = $this->mapping->get(Mapping::ENTITY_PRODUCT, (int) $meta['_variation_id']);
        }

        // Fall back to the first variant of the configurable product.
        if (! $variantId) {
            $variantId = DB::table('products')
                ->where('parent_id', $parentProduct->id)
                ->orderBy('id')
                ->value('id');
        }

        $variant = $variantId ? DB::table('products')->find($variantId) : null;

        return [
            'sku' => $variant->sku ?? $parentProduct->sku,
            'type' => 'simple',
            'name' => $name,
            'qty_ordered' => $qty,
            'qty_invoiced' => $qty,
            'price' => $unitPrice,
            'base_price' => $unitPrice,
            'total' => $lineTotal,
            'base_total' => $lineTotal,
            'product_id' => $variantId ?: $parentProduct->id,
            'product_type' => Product::class,
            'additional' => json_encode(['imported_from_woo' => true]),
        ];
    }

    protected function orderShippingTotal(int $wooOrderId): float
    {
        $shippingItems = $this->woo->table('woocommerce_order_items')
            ->where('order_id', $wooOrderId)
            ->where('order_item_type', 'shipping')
            ->pluck('order_item_id');

        $total = 0.0;

        foreach ($shippingItems as $itemId) {
            $meta = $this->itemMeta((int) $itemId);
            $total += (float) ($meta['cost'] ?? 0);
        }

        return round($total, 4);
    }

    /**
     * Insert a billing/shipping address row for the order.
     */
    protected function insertAddress(int $orderId, ?int $customerId, string $type, ?object $src, string $email, int $useForShipping): void
    {
        if (! $src) {
            return;
        }

        DB::table('addresses')->insert([
            'address_type' => $type,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'first_name' => $src->first_name ?: 'Guest',
            'last_name' => $src->last_name ?: 'Customer',
            'company_name' => $src->company ?? null,
            'address' => trim(($src->address_1 ?? '').' '.($src->address_2 ?? '')) ?: 'N/A',
            'city' => $src->city ?: 'N/A',
            'state' => $src->state ?? '',
            'country' => $src->country ?: '',
            'postcode' => $src->postcode ?? '',
            'email' => ($src->email ?? '') ?: ($email ?: null),
            'phone' => $src->phone ?? null,
            'use_for_shipping' => $useForShipping,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fetch order-item meta as a flat key => value array.
     *
     * @return array<string, string>
     */
    protected function itemMeta(int $orderItemId): array
    {
        $rows = $this->woo->table('woocommerce_order_itemmeta')
            ->where('order_item_id', $orderItemId)
            ->get(['meta_key', 'meta_value']);

        $meta = [];

        foreach ($rows as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }

        return $meta;
    }

    /**
     * Translate a WooCommerce status to a Bagisto order status.
     */
    protected function mapStatus(string $wooStatus): string
    {
        return self::STATUS_MAP[$wooStatus] ?? 'pending';
    }

    /**
     * Translate a WooCommerce payment gateway code to a registered Bagisto
     * payment method, falling back to "moneytransfer".
     */
    protected function mapPaymentMethod(?string $wooMethod): string
    {
        $method = strtolower(trim((string) $wooMethod));

        return self::PAYMENT_MAP[$method] ?? 'moneytransfer';
    }
}
