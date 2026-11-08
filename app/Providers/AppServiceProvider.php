<?php

namespace App\Providers;

use App\Shipping\Carriers\CountryRate;
use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\View;
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

        $this->registerAnalytics();
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

    /**
     * Google Analytics 4 (config/analytics.php): the base tag, consent handling
     * and checkout events go into every storefront <head>, view_item into
     * product pages. Hooked through Bagisto's view render events so the Shop
     * package's templates stay untouched. The templates live in
     * app/Analytics/views because resources/views is git-ignored.
     */
    protected function registerAnalytics(): void
    {
        if (! config('analytics.ga4.enabled') || ! config('analytics.ga4.measurement_id')) {
            return;
        }

        View::addNamespace('analytics', app_path('Analytics/views'));

        Event::listen('bagisto.shop.layout.head.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('analytics::ga4.head');
        });

        Event::listen('bagisto.shop.products.view.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('analytics::ga4.view-item');
        });
    }
}
