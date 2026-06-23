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
     * Storefront branding. The header logo is rendered as a lightweight text
     * SVG (so there is no binary asset to ship) and the home-page hero reuses
     * the same wordmark. `logo_segments` splits the wordmark so a part of it
     * can be highlighted in a different colour. Leave `wordmark` empty to fall
     * back to the WooCommerce `blogname`.
     */
    'branding' => [
        'wordmark' => env('WOO_BRAND_WORDMARK', 'URmotorparts'),

        // Header logo image. Path is RELATIVE to the WooCommerce uploads
        // directory (`uploads_path`), i.e. the same wp-content/uploads-relative
        // form WordPress stores. This is the real store logo registered in the
        // legacy theme (`site_logo`). When the file exists it becomes the
        // storefront logo (copied into public storage); otherwise the importer
        // falls back to the text wordmark SVG built from `logo_segments`.
        'logo' => env('WOO_BRAND_LOGO', '2025/01/URmotorparts-300x300.jpg'),

        // Fallback wordmark: coloured pieces that compose the text logo, in
        // order, used only when no `logo` image is available. Their
        // concatenated text should equal `wordmark`; `color` is any CSS colour.
        'logo_segments' => [
            ['text' => 'UR', 'color' => '#0f172a'],
            ['text' => 'motor', 'color' => '#f59e0b'],
            ['text' => 'parts', 'color' => '#0f172a'],
        ],

        // Home-page hero banner. Path is RELATIVE to the WooCommerce uploads
        // directory (`uploads_path`), i.e. the same wp-content/uploads-relative
        // form WordPress stores. When the file exists the hero becomes that
        // image (linked to the storefront); otherwise it falls back to the
        // text hero (wordmark + tagline + "Shop now"). Leave empty to disable.
        'hero_banner' => env('WOO_HERO_BANNER', '2026/03/motorcycle-accessories-lower-triple-tree-clamp-fork-slider-crash-protector-speedometer-housing-case-brake-cluth-line-and-more.-Buy-from-URMOTORPARTS.jpg'),
    ],

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
