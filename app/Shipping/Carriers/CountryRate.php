<?php

namespace App\Shipping\Carriers;

use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartShippingRate;
use Webkul\Shipping\Carriers\AbstractShipping;

/**
 * Country-based shipping carrier.
 *
 * Core Bagisto's Flat Rate charges one price worldwide. This carrier instead
 * reads `config/country-shipping.php` and offers a different set of rate
 * options depending on the shipping country — e.g. Standard $10 / Expedited
 * $22 for the US, and a single $33.99 international rate everywhere else.
 *
 * calculate() may return MULTIPLE rates; Shipping::collectRates() accepts an
 * array, so every method in the matched group becomes a checkout option.
 */
class CountryRate extends AbstractShipping
{
    /**
     * Shipping method carrier code.
     *
     * @var string
     */
    protected $code = 'countryrate';

    /**
     * Calculate the rates available for the cart's shipping country.
     *
     * @return CartShippingRate[]|false
     */
    public function calculate()
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $cart = Cart::getCart();

        if (! $cart) {
            return false;
        }

        $group = $this->resolveGroup($this->shippingCountry($cart));

        if (! $group) {
            return false;
        }

        $quantity = $this->shippableQuantity($cart);

        if (! $quantity) {
            return false;
        }

        $rates = [];

        foreach ($group['methods'] ?? [] as $method) {
            $rates[] = $this->buildRate($method, $quantity);
        }

        return $rates ?: false;
    }

    /**
     * Carrier-level settings live in config, not core_config, so the admin
     * Shipping Methods screen cannot silently disable this carrier.
     */
    public function isAvailable()
    {
        return (bool) config('country-shipping.active', true);
    }

    /**
     * Build one selectable checkout rate from a configured method.
     */
    protected function buildRate(array $method, int $quantity): CartShippingRate
    {
        $rate = new CartShippingRate;

        $basePrice = (float) ($method['rate'] ?? 0);

        if (($method['type'] ?? 'per_order') === 'per_unit') {
            $basePrice *= $quantity;
        }

        $rate->carrier = $this->getCode();
        $rate->carrier_title = config('country-shipping.title', 'Shipping');
        $rate->method = $this->getCode().'_'.($method['code'] ?? 'standard');
        $rate->method_title = $method['title'] ?? 'Shipping';
        $rate->method_description = $method['description'] ?? '';
        $rate->base_price = $basePrice;
        $rate->price = core()->convertPrice($basePrice);

        return $rate;
    }

    /**
     * The country the order ships to. Falls back to the billing address for
     * carts with no shipping address (e.g. virtual items).
     */
    protected function shippingCountry(object $cart): ?string
    {
        $country = $cart->shipping_address->country
            ?? $cart->billing_address->country
            ?? null;

        return $country ? strtoupper($country) : null;
    }

    /**
     * First config group matching the country; the `'*'` group is the
     * catch-all and should be listed last.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveGroup(?string $country): ?array
    {
        $fallback = null;

        foreach ((array) config('country-shipping.groups', []) as $group) {
            $countries = $group['countries'] ?? null;

            if ($countries === '*') {
                $fallback ??= $group;

                continue;
            }

            if ($country && in_array($country, array_map('strtoupper', (array) $countries), true)) {
                return $group;
            }
        }

        return $fallback;
    }

    /**
     * Total quantity of items that actually need shipping.
     */
    protected function shippableQuantity(object $cart): int
    {
        $quantity = 0;

        foreach ($cart->items as $item) {
            if ($item->getTypeInstance()->isStockable()) {
                $quantity += $item->quantity;
            }
        }

        return $quantity;
    }
}
