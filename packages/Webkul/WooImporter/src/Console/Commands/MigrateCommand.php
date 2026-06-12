<?php

namespace Webkul\WooImporter\Console\Commands;

use Illuminate\Console\Command;
use Webkul\WooImporter\Migrators\AttributeMigrator;
use Webkul\WooImporter\Migrators\CategoryMigrator;
use Webkul\WooImporter\Migrators\CouponMigrator;
use Webkul\WooImporter\Migrators\CustomerMigrator;
use Webkul\WooImporter\Migrators\OrderMigrator;
use Webkul\WooImporter\Migrators\ProductMigrator;
use Webkul\WooImporter\Migrators\ReviewMigrator;
use Webkul\WooImporter\Migrators\SiteContentMigrator;
use Webkul\WooImporter\Migrators\VideoMigrator;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * One-shot orchestrator that runs the full WooCommerce → Bagisto migration in
 * the correct order: categories → attributes → products → (customers).
 */
class MigrateCommand extends Command
{
    protected $signature = 'woocommerce:migrate
        {--fresh : Wipe previous import mappings and re-import everything}
        {--skip-images : Import products without downloading/encoding images (much faster)}
        {--all : Migrate EVERYTHING (customers, orders, coupons, reviews and storefront content)}
        {--with-customers : Also migrate customers (registered + guest order e-mails)}
        {--with-orders : Also migrate historical orders (requires customers)}
        {--with-coupons : Also migrate coupons as cart rules}
        {--with-reviews : Also migrate product reviews}
        {--with-videos : Also migrate product videos}
        {--with-content : Also replace demo storefront content (store name, CMS pages, home page, footer)}
        {--uploads= : Absolute path to the copied wp-content/uploads directory (overrides config)}
        {--strategy= : Variation mapping strategy: auto|configurable|flatten}
        {--limit= : Limit the number of products (useful for a trial run)}';

    protected $description = 'Migrate data (categories, attributes, products, images, customers, orders, coupons, reviews, content) from a WooCommerce database into Bagisto';

    public function handle(WooClient $woo, Mapping $mapping): int
    {
        $this->info('WooCommerce → Bagisto migration');
        $this->line('================================');

        if ($missing = $woo->missingTables()) {
            $this->error('Cannot reach the WooCommerce database or required tables are missing:');
            $this->line('  '.implode(', ', $missing));
            $this->line('Check the WOO_DB_* values in your .env and that the dump was imported.');

            return self::FAILURE;
        }

        // Apply runtime overrides BEFORE resolving the migrators (their image
        // resolver reads the uploads path in its constructor).
        if ($this->option('uploads')) {
            config(['woo-importer.uploads_path' => $this->option('uploads')]);
        }

        if ($this->option('strategy')) {
            config(['woo-importer.variation_strategy' => $this->option('strategy')]);
        }

        if ($this->option('fresh')) {
            $this->warn('Flushing previous import mappings (--fresh)...');
            $mapping->flush();
        }

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $all = $this->option('all');

        $this->newLine();
        $this->comment('[1] Categories');
        app(CategoryMigrator::class)->migrate($this);

        $this->newLine();
        $this->comment('[2] Configurable attributes');
        app(AttributeMigrator::class)->migrate($this);

        $this->newLine();
        $this->comment('[3] Products'.($limit ? " (limited to {$limit})" : ''));
        app(ProductMigrator::class)->migrate($this, $limit, ! $this->option('skip-images'));

        if ($all || $this->option('with-customers')) {
            $this->newLine();
            $this->comment('[4] Customers');
            app(CustomerMigrator::class)->migrate($this);
        }

        if ($all || $this->option('with-orders')) {
            $this->newLine();
            $this->comment('[5] Orders');
            app(OrderMigrator::class)->migrate($this);
        }

        if ($all || $this->option('with-coupons')) {
            $this->newLine();
            $this->comment('[6] Coupons');
            app(CouponMigrator::class)->migrate($this);
        }

        if ($all || $this->option('with-reviews')) {
            $this->newLine();
            $this->comment('[7] Reviews');
            app(ReviewMigrator::class)->migrate($this);
        }

        if ($all || $this->option('with-videos')) {
            $this->newLine();
            $this->comment('[8] Product videos');
            app(VideoMigrator::class)->migrate($this);
        }

        if ($all || $this->option('with-content')) {
            $this->newLine();
            $this->comment('[+] Storefront content (store name, CMS pages, home page, footer)');
            app(SiteContentMigrator::class)->migrate($this);
        }

        $this->newLine();
        $this->info('Migration finished. Next step: rebuild the indexes with');
        $this->line('  php artisan indexer:index --mode=full');

        return self::SUCCESS;
    }
}
