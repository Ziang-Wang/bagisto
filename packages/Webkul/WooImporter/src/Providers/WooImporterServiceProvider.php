<?php

namespace Webkul\WooImporter\Providers;

use Illuminate\Support\ServiceProvider;
use Webkul\WooImporter\Console\Commands\MigrateAttributesCommand;
use Webkul\WooImporter\Console\Commands\MigrateCategoriesCommand;
use Webkul\WooImporter\Console\Commands\MigrateCommand;
use Webkul\WooImporter\Console\Commands\MigrateCustomersCommand;
use Webkul\WooImporter\Console\Commands\MigrateProductsCommand;
use Webkul\WooImporter\Console\Commands\MigrateSiteContentCommand;

class WooImporterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateCommand::class,
                MigrateCategoriesCommand::class,
                MigrateAttributesCommand::class,
                MigrateProductsCommand::class,
                MigrateCustomersCommand::class,
                MigrateSiteContentCommand::class,
            ]);
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        /**
         * The read-only "woocommerce" source connection is declared directly in
         * config/database.php (cache-safe). Here we only merge the importer's
         * own settings so they are available even before publishing.
         */
        $this->mergeConfigFrom(
            __DIR__.'/../Config/woo-importer.php',
            'woo-importer'
        );
    }
}
