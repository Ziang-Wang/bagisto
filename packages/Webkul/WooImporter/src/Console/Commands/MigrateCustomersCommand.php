<?php

namespace Webkul\WooImporter\Console\Commands;

use Illuminate\Console\Command;
use Webkul\WooImporter\Migrators\CustomerMigrator;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

class MigrateCustomersCommand extends Command
{
    protected $signature = 'woocommerce:migrate:customers {--fresh : Re-import customers from scratch}';

    protected $description = 'Create Bagisto customers from the legacy order e-mails (optional, best-effort)';

    public function handle(WooClient $woo, Mapping $mapping, CustomerMigrator $customers): int
    {
        if ($missing = $woo->missingTables()) {
            $this->error('WooCommerce database not reachable. Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $mapping->flush(Mapping::ENTITY_CUSTOMER);
        }

        $customers->migrate($this);

        return self::SUCCESS;
    }
}
