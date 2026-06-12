<?php

namespace Webkul\WooImporter\Console\Commands;

use Illuminate\Console\Command;
use Webkul\WooImporter\Migrators\AttributeMigrator;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

class MigrateAttributesCommand extends Command
{
    protected $signature = 'woocommerce:migrate:attributes {--fresh : Re-import attributes from scratch}';

    protected $description = 'Migrate only the configurable attributes (and options) needed for variable products';

    public function handle(WooClient $woo, Mapping $mapping, AttributeMigrator $attributes): int
    {
        if ($missing = $woo->missingTables()) {
            $this->error('WooCommerce database not reachable. Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $mapping->flush(Mapping::ENTITY_ATTRIBUTE);
            $mapping->flush(Mapping::ENTITY_ATTRIBUTE_OPTION);
        }

        $attributes->migrate($this);

        return self::SUCCESS;
    }
}
