<?php

namespace Webkul\WooImporter\Console\Commands;

use Illuminate\Console\Command;
use Webkul\WooImporter\Migrators\ProductMigrator;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

class MigrateProductsCommand extends Command
{
    protected $signature = 'woocommerce:migrate:products
        {--fresh : Re-import products from scratch}
        {--skip-images : Import products without images}
        {--uploads= : Absolute path to the copied wp-content/uploads directory (overrides config)}
        {--strategy= : Variation mapping strategy: auto|configurable|flatten}
        {--limit= : Limit the number of products}';

    protected $description = 'Migrate only WooCommerce products (requires categories & attributes to be migrated first)';

    public function handle(WooClient $woo, Mapping $mapping): int
    {
        if ($missing = $woo->missingTables()) {
            $this->error('WooCommerce database not reachable. Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        if ($this->option('uploads')) {
            config(['woo-importer.uploads_path' => $this->option('uploads')]);
        }

        if ($this->option('strategy')) {
            config(['woo-importer.variation_strategy' => $this->option('strategy')]);
        }

        if ($this->option('fresh')) {
            $mapping->flush(Mapping::ENTITY_PRODUCT);
        }

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        app(ProductMigrator::class)->migrate($this, $limit, ! $this->option('skip-images'));

        $this->newLine();
        $this->info('Done. Rebuild indexes with: php artisan indexer:index --mode=full');

        return self::SUCCESS;
    }
}
