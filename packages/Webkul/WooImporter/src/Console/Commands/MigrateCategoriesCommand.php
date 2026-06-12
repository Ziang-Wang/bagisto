<?php

namespace Webkul\WooImporter\Console\Commands;

use Illuminate\Console\Command;
use Webkul\WooImporter\Migrators\CategoryMigrator;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

class MigrateCategoriesCommand extends Command
{
    protected $signature = 'woocommerce:migrate:categories {--fresh : Re-import categories from scratch}';

    protected $description = 'Migrate only WooCommerce product categories into Bagisto';

    public function handle(WooClient $woo, Mapping $mapping, CategoryMigrator $categories): int
    {
        if ($missing = $woo->missingTables()) {
            $this->error('WooCommerce database not reachable. Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $mapping->flush(Mapping::ENTITY_CATEGORY);
        }

        $categories->migrate($this);

        return self::SUCCESS;
    }
}
