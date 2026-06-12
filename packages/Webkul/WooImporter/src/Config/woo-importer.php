<?php

/*
|--------------------------------------------------------------------------
| WooCommerce importer settings
|--------------------------------------------------------------------------
|
| Central place for tweaking how the legacy WooCommerce store is mapped onto
| Bagisto. Everything is overridable through environment variables so the
| same code can be reused for other migrations without edits.
|
*/

return [
    /**
     * The `$table_prefix` from the legacy wp-config.php. Bagisto reads the
     * WordPress tables using this prefix (e.g. wp_posts, wp_postmeta...).
     */
    'table_prefix' => env('WOO_DB_PREFIX', 'wp_'),

    /**
     * Absolute path to the copied `wp-content/uploads` directory on THIS
     * machine. The importer reads product images from here. If it is empty
     * or the file is missing, the product is still imported without images.
     */
    'uploads_path' => env('WOO_UPLOADS_PATH', storage_path('woo-uploads')),

    /**
     * Default Bagisto identifiers used while creating records. These match a
     * standard `php artisan bagisto:install` and rarely need changing.
     */
    'defaults' => [
        'attribute_family_id' => 1,
        'attribute_group_id' => 1,   // "General" group inside the default family
        'channel_id' => 1,
        'inventory_source_id' => 1,
        'root_category_id' => 1,
        'customer_group_id' => 2,   // "general" customer group
    ],

    /**
     * Inventory behaviour. WooCommerce frequently does not manage stock and
     * relies on the `instock` / `outofstock` status only. When no numeric
     * stock value is available we fall back to these quantities so products
     * remain salable in Bagisto.
     */
    'inventory' => [
        'default_in_stock_qty' => (int) env('WOO_DEFAULT_STOCK', 1000),
        'out_of_stock_qty' => 0,
    ],

    /**
     * How variable products are mapped:
     *  - "auto"         : 1 variation => simple product, 2+ => configurable (recommended)
     *  - "configurable" : always configurable
     *  - "flatten"      : every variation becomes its own simple product
     */
    'variation_strategy' => env('WOO_VARIATION_STRATEGY', 'auto'),

    /**
     * Storefront content migration (store name, CMS pages, home page, footer).
     * `cms_pages` lists the legacy WordPress page slugs to import as Bagisto
     * CMS pages, replacing the demo pages seeded by the installer.
     */
    'content' => [
        'cms_pages' => [
            'about-us',
            'contact-us',
            'company',
            'privacy-policy',
            'returns-policy',
            'shipping-delivery',
            'terms-and-conditions',
            'payment-methods',
            'frequently-asked-questions',
            'faq',
        ],

        // Number of product carousels (one per best-stocked category) on the home page.
        'product_carousel_count' => (int) env('WOO_HOME_CAROUSELS', 3),
    ],
];
