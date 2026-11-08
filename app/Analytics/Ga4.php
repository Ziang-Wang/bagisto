<?php

namespace App\Analytics;

use Webkul\Checkout\Facades\Cart;
use Webkul\Sales\Repositories\OrderRepository;

/**
 * Builds the Google Analytics 4 e-commerce event payloads.
 *
 * Items are identified by product id rather than SKU: a configurable product's
 * order line keeps the parent id but carries the chosen child's SKU, so ids are
 * what line view_item, add_to_cart and purchase up for every product type.
 * Amounts follow GA4's convention: `value` is the items total after
 * discounts, with tax and shipping reported separately.
 */
class Ga4
{
    /**
     * view_item for a product page.
     */
    public static function viewItem($product): array
    {
        $price = round((float) $product->getTypeInstance()->getMinimalPrice(), 2);

        return [
            'currency' => core()->getBaseCurrencyCode(),
            'value' => $price,
            'items' => [[
                'item_id' => (string) $product->id,
                'item_name' => $product->name,
                'price' => $price,
            ]],
        ];
    }

    /**
     * begin_checkout for the visitor's cart, or null when it is empty.
     */
    public static function beginCheckout(): ?array
    {
        $cart = Cart::getCart();

        if (! $cart || $cart->items->isEmpty()) {
            return null;
        }

        return static::withoutNulls([
            'currency' => $cart->base_currency_code ?: core()->getBaseCurrencyCode(),
            'value' => round((float) $cart->base_sub_total - (float) $cart->base_discount_amount, 2),
            'coupon' => $cart->coupon_code ?: null,
            'items' => static::lines($cart->items, 'quantity'),
        ]);
    }

    /**
     * purchase for the order shown on the checkout success page, or null.
     */
    public static function purchase(): ?array
    {
        $orderId = session('order_id');

        if (! $orderId || ! $order = app(OrderRepository::class)->find($orderId)) {
            return null;
        }

        return static::withoutNulls([
            'transaction_id' => (string) $order->increment_id,
            'currency' => $order->base_currency_code ?: core()->getBaseCurrencyCode(),
            'value' => round((float) $order->base_sub_total - (float) $order->base_discount_amount, 2),
            'tax' => round((float) $order->base_tax_amount, 2),
            'shipping' => round((float) $order->base_shipping_amount, 2),
            'coupon' => $order->coupon_code ?: null,
            'items' => static::lines($order->items, 'qty_ordered'),
        ]);
    }

    /**
     * GA4 items for top-level cart or order lines (configurable children skipped).
     */
    protected static function lines($items, string $quantityColumn): array
    {
        return $items->whereNull('parent_id')
            ->map(fn ($item) => [
                'item_id' => (string) $item->product_id,
                'item_name' => $item->name,
                'price' => round((float) $item->base_price, 2),
                'quantity' => (int) $item->{$quantityColumn},
            ])
            ->values()
            ->all();
    }

    protected static function withoutNulls(array $payload): array
    {
        return array_filter($payload, fn ($value) => $value !== null);
    }
}
