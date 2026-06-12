<?php

namespace Webkul\WooImporter\Console\Commands;

use Illuminate\Console\Command;
use Webkul\WooImporter\Migrators\SiteContentMigrator;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Replaces the demo storefront content (store name, CMS pages, home-page
 * sections, footer links) with the real data from the WooCommerce store.
 *
 * Run this AFTER the catalogue migration so categories and products exist.
 */
class MigrateSiteContentCommand extends Command
{
    protected $signature = 'woocommerce:migrate-content
        {--fresh : Forget previous CMS page mappings and re-create them}';

    protected $description = 'Replace Bagisto demo content (store name, CMS pages, home page, footer) with real WooCommerce data';

    public function handle(WooClient $woo, Mapping $mapping): int
    {
        $this->info('WooCommerce → Bagisto site content');
        $this->line('==================================');

        if ($missing = $woo->missingTables()) {
            $this->error('Cannot reach the WooCommerce database or required tables are missing:');
            $this->line('  '.implode(', ', $missing));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('Forgetting previous CMS page mappings (--fresh)...');
            $mapping->flush(Mapping::ENTITY_CMS_PAGE);
        }

        app(SiteContentMigrator::class)->migrate($this);

        $this->newLine();
        $this->info('Done. Clear caches so the storefront picks up the changes:');
        $this->line('  php artisan optimize:clear');

        return self::SUCCESS;
    }
}
