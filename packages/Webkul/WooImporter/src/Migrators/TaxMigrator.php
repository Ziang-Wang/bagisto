<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Tax\Repositories\TaxCategoryRepository;
use Webkul\Tax\Repositories\TaxRateRepository;

/**
 * Sets up a single flat tax rate (default 15%) for every European country and
 * applies it store-wide.
 *
 * Unlike the other migrators this reads NOTHING from WooCommerce — the legacy
 * store did not model an "EU 15%" rule. It is a fixed business rule expressed
 * through `woo-importer.tax` config so a fresh re-migration always rebuilds the
 * same tax setup (reproducibility), while the rate / country list / category
 * remain tunable without code edits.
 *
 * What it does (all idempotent, keyed by category `code` and rate `identifier`):
 *   1. Ensure a tax category (default code `EU-VAT`) exists.
 *   2. Ensure one country-wide rate per European ISO2 code at the configured
 *      percentage (identifier `EU-<CODE>`, state '' = any state, no zip limit).
 *   3. Attach every rate to the category.
 *   4. Optionally set the category as the DEFAULT product tax category
 *      (core_config `sales.taxes.categories.product`) so all products are taxed
 *      by it. Rates only match European shipping addresses, so non-European
 *      customers are charged 0.
 */
class TaxMigrator
{
    /**
     * Geographic Europe, ISO 3166-1 alpha-2. Used when the config
     * `tax.countries` is the string preset `"europe"`.
     */
    public const EUROPE = [
        'AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE',
        'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'XK', 'LV', 'LI', 'LT',
        'LU', 'MT', 'MD', 'MC', 'ME', 'NL', 'MK', 'NO', 'PL', 'PT', 'RO', 'RU',
        'SM', 'RS', 'SK', 'SI', 'ES', 'SE', 'CH', 'UA', 'GB', 'VA',
    ];

    public function __construct(
        protected TaxCategoryRepository $taxCategoryRepository,
        protected TaxRateRepository $taxRateRepository
    ) {}

    /**
     * Run the tax migration.
     */
    public function migrate(Command $console): void
    {
        $config = config('woo-importer.tax', []);

        if (! ($config['enabled'] ?? true)) {
            $console->warn('  Tax setup disabled (woo-importer.tax.enabled = false) — skipped.');

            return;
        }

        $rate = (float) ($config['rate'] ?? 15);
        $code = (string) ($config['category_code'] ?? 'EU-VAT');
        $rateStr = number_format($rate, 4, '.', '');
        $ratePretty = rtrim(rtrim($rateStr, '0'), '.');

        $countries = $this->resolveCountries($config['countries'] ?? 'europe');

        // Keep only ISO2 codes that actually exist in Bagisto's countries table
        // so every rate maps to a selectable checkout address.
        $known = DB::table('countries')->pluck('code')->map(fn ($c) => strtoupper($c))->all();
        $covered = array_values(array_intersect($countries, $known));
        $skipped = array_values(array_diff($countries, $known));

        if (empty($covered)) {
            $console->error('  No matching countries found — nothing to do.');

            return;
        }

        // 1) Category (find-or-create by code).
        $category = $this->taxCategoryRepository->findOneByField('code', $code);

        if (! $category) {
            $category = $this->taxCategoryRepository->create([
                'code' => $code,
                'name' => "European VAT ({$ratePretty}%)",
                'description' => "{$ratePretty}% tax applied to all European countries.",
            ]);
            $console->line("  Tax category created: {$code} (id={$category->id}).");
        } else {
            $console->line("  Tax category exists: {$code} (id={$category->id}).");
        }

        // 2) One country-wide rate per country + collect ids for the pivot.
        $created = $updated = 0;
        $rateIds = [];

        foreach ($covered as $countryCode) {
            $identifier = 'EU-'.$countryCode;

            $existing = $this->taxRateRepository->findOneByField('identifier', $identifier);

            if (! $existing) {
                $taxRate = $this->taxRateRepository->create([
                    'identifier' => $identifier,
                    'is_zip' => 0,
                    'zip_code' => null,
                    'state' => '',
                    'country' => $countryCode,
                    'tax_rate' => $rateStr,
                ]);
                $created++;
            } else {
                // Re-assert 15% / country-wide in case it drifted.
                $this->taxRateRepository->update([
                    'tax_rate' => $rateStr,
                    'state' => '',
                    'is_zip' => 0,
                ], $existing->id);
                $taxRate = $existing;
                $updated++;
            }

            $rateIds[] = $taxRate->id;
        }

        // 3) Attach every rate to the category (sync = idempotent).
        $category->tax_rates()->sync($rateIds);

        // 4) Optionally make it the default product tax category (store-wide).
        if ($config['set_as_default'] ?? true) {
            DB::table('core_config')->updateOrInsert(
                ['code' => 'sales.taxes.categories.product', 'channel_code' => null, 'locale_code' => null],
                ['value' => (string) $category->id, 'updated_at' => now(), 'created_at' => now()]
            );
            $console->line('  Set as default product tax category (sales.taxes.categories.product).');
        }

        $console->info("  Tax rates: {$created} created, {$updated} updated, ".count($rateIds)." linked at {$ratePretty}% across ".count($covered).' countries.');

        if ($skipped) {
            $console->warn('  Skipped (not in countries table): '.implode(', ', $skipped));
        }
    }

    /**
     * Resolve the configured country list into an array of ISO2 codes.
     * Accepts the string preset `"europe"` or an explicit array of codes.
     */
    protected function resolveCountries(mixed $countries): array
    {
        if (is_string($countries)) {
            return strtolower(trim($countries)) === 'europe' ? self::EUROPE : [];
        }

        return array_values(array_unique(array_map('strtoupper', (array) $countries)));
    }
}
