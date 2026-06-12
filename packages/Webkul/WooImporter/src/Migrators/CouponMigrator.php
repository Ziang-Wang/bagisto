<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\CartRule\Repositories\CartRuleRepository;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Migrates WooCommerce coupons (`shop_coupon`) into Bagisto cart rules.
 *
 * Each coupon becomes a cart rule scoped to the default channel and all
 * customer groups, carrying a single primary coupon code. Percentage and
 * fixed-cart discounts are both supported.
 *
 * Idempotent through the `woo_import_maps` table (entity = coupon).
 */
class CouponMigrator
{
    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping,
        protected CartRuleRepository $cartRuleRepository
    ) {}

    /**
     * Run the coupon migration.
     */
    public function migrate(Command $console): void
    {
        $coupons = $this->woo->table('posts')
            ->where('post_type', 'shop_coupon')
            ->whereIn('post_status', ['publish', 'draft'])
            ->get(['ID', 'post_title', 'post_excerpt']);

        if ($coupons->isEmpty()) {
            $console->warn('  No coupons found in the legacy store.');

            return;
        }

        $channelId = (int) config('woo-importer.defaults.channel_id', 1);
        $customerGroupIds = $this->allCustomerGroupIds();

        $migrated = $skipped = 0;

        foreach ($coupons as $coupon) {
            if ($this->mapping->get(Mapping::ENTITY_COUPON, $coupon->ID)) {
                $skipped++;

                continue;
            }

            $meta = $this->woo->meta((int) $coupon->ID);

            $code = trim((string) $coupon->post_title);

            if ($code === '') {
                continue;
            }

            $actionType = ($meta['discount_type'] ?? 'percent') === 'percent'
                ? 'by_percent'
                : 'by_fixed';

            $amount = (float) ($meta['coupon_amount'] ?? 0);

            $endsTill = ! empty($meta['date_expires'])
                ? date('Y-m-d H:i:s', (int) $meta['date_expires'])
                : null;

            $cartRule = $this->cartRuleRepository->create([
                'name' => 'Coupon: '.$code,
                'description' => (string) $coupon->post_excerpt,
                'starts_from' => null,
                'ends_till' => $endsTill,
                'status' => 1,
                'channels' => [$channelId],
                'customer_groups' => $customerGroupIds,
                'coupon_type' => 1,
                'use_auto_generation' => 0,
                'coupon_code' => $code,
                'uses_per_coupon' => (int) ($meta['usage_limit'] ?? 0),
                'usage_per_customer' => (int) ($meta['usage_limit_per_user'] ?? 0),
                'condition_type' => 1,
                'conditions' => [],
                'action_type' => $actionType,
                'discount_amount' => $amount,
                'discount_quantity' => 1,
                'discount_step' => 0,
                'apply_to_shipping' => 0,
                'free_shipping' => 0,
                'sort_order' => 0,
            ]);

            $this->mapping->put(Mapping::ENTITY_COUPON, $coupon->ID, $cartRule->id, ['code' => $code]);

            $migrated++;
        }

        $console->info("  Coupons migrated: {$migrated} — skipped (already imported): {$skipped}");
    }

    /**
     * All customer group ids so the coupon applies to everyone.
     *
     * @return array<int, int>
     */
    protected function allCustomerGroupIds(): array
    {
        return DB::table('customer_groups')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
