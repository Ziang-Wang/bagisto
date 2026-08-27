<?php

/*
|--------------------------------------------------------------------------
| Country-based shipping rates
|--------------------------------------------------------------------------
|
| Bagisto core only ships a single Flat Rate (one price for the whole world)
| and Free Shipping. This config drives the CountryRate carrier, which offers
| DIFFERENT rate options depending on the customer's shipping country.
|
| `groups` is matched top-down against the shipping address country:
|   - a group with a `countries` array applies to those ISO2 codes
|   - the group with `countries => '*'` is the fallback for every other country
| The first matching group wins, so keep '*' last.
|
| Each group lists one or more `methods`; every method becomes a selectable
| shipping option at checkout. `type` controls how the price is applied:
|   - 'per_order' : flat, regardless of item count (default)
|   - 'per_unit'  : rate x total quantity of shippable items
|
*/

return [
    'active' => true,

    // Carrier title shown as the group heading at checkout.
    'title' => 'Shipping',

    'groups' => [
        // United States.
        [
            'countries' => ['US'],
            'methods' => [
                [
                    'code' => 'standard',
                    'title' => 'Standard Shipping',
                    'description' => 'Delivery in 7-15 business days.',
                    'rate' => 10.00,
                    'type' => 'per_order',
                ],
            ],
        ],

        // Everywhere else: single international flat rate.
        [
            'countries' => '*',
            'methods' => [
                [
                    'code' => 'flatrate',
                    'title' => 'Flat Rate',
                    'description' => 'International delivery.',
                    'rate' => 33.99,
                    'type' => 'per_order',
                ],
            ],
        ],
    ],
];
