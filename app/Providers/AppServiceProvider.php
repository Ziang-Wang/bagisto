<?php

namespace App\Providers;

use App\Shipping\Carriers\CountryRate;
use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $allowedIPs = array_map('trim', explode(',', config('app.debug_allowed_ips', '')));

        $allowedIPs = array_filter($allowedIPs);

        if (empty($allowedIPs)) {
            return;
        }

        if (in_array(Request::ip(), $allowedIPs)) {
            Debugbar::enable();
        } else {
            Debugbar::disable();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });

        $this->registerShippingCarriers();
    }

    /**
     * Append this store's custom shipping carriers to the ones the Shipping
     * package registers. Done here (rather than editing the package config) so
     * core stays untouched.
     */
    protected function registerShippingCarriers(): void
    {
        Config::set('carriers.countryrate', [
            'code' => 'countryrate',
            'title' => Config::get('country-shipping.title', 'Shipping'),
            'class' => CountryRate::class,
        ]);
    }
}
