<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Replaces the demo "look & feel" that `bagisto:install` seeds (store name,
 * CMS pages, home-page sections and footer links) with the real data coming
 * from the legacy WooCommerce/WordPress store.
 *
 * Unlike the catalogue migrators this one is mostly about the storefront
 * presentation, so it writes directly to the relevant Bagisto tables in the
 * same shape the installer seeders use. It stays idempotent through the
 * `woo_import_maps` table for CMS pages and by fully rebuilding the channel's
 * theme customizations on every run.
 */
class SiteContentMigrator
{
    protected string $defaultLocale;

    protected int $channelId;

    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping
    ) {
        $this->defaultLocale = core()->getDefaultLocaleCodeFromDefaultChannel();
        $this->channelId = (int) config('woo-importer.defaults.channel_id', 1);
    }

    /**
     * Run the full site-content migration.
     */
    public function migrate(Command $console): void
    {
        $this->migrateStoreIdentity($console);
        $this->migrateBranding($console);
        $this->migrateCmsPages($console);
        $this->normalizeCategoryTree($console);
        $this->decorateCategories($console);
        $this->rebuildHomePage($console);
    }

    /**
     * Replace the "Demo store" name and home-page SEO with the legacy
     * `blogname` / `blogdescription`.
     */
    protected function migrateStoreIdentity(Command $console): void
    {
        $name = $this->storeName();
        $description = trim((string) $this->woo->option('blogdescription', ''));

        foreach ($this->channelLocales() as $locale) {
            DB::table('channel_translations')
                ->where('channel_id', $this->channelId)
                ->where('locale', $locale)
                ->update([
                    'name' => $name,
                    'home_seo' => json_encode([
                        'meta_title' => $name,
                        'meta_keywords' => $name,
                        'meta_description' => $description !== '' ? $description : $name,
                    ]),
                ]);
        }

        $console->info("  Store identity set to: {$name}");
    }

    /**
     * Point the channel at the storefront logo. Prefers the real brand logo
     * image copied from the WooCommerce uploads (`branding.logo`); when that
     * file is unavailable it falls back to a generated text wordmark SVG built
     * from `branding.logo_segments`. Done in code so a from-scratch deploy
     * reproduces the exact same branding. Idempotent: overwrites each run.
     */
    protected function migrateBranding(Command $console): void
    {
        if ($logo = $this->copyBrandLogo()) {
            $console->info('  Storefront logo set to image: '.$logo);
        } elseif ($logo = $this->buildWordmarkLogo()) {
            $console->info('  Storefront logo set to wordmark: '.$this->brandName());
        }

        if ($logo) {
            DB::table('channels')->where('id', $this->channelId)->update(['logo' => $logo]);
        }

        // Browser-tab icon. Use a dedicated square image when configured,
        // otherwise reuse the logo so the favicon matches the store logo.
        if ($favicon = ($this->copyFavicon() ?: $logo)) {
            DB::table('channels')->where('id', $this->channelId)->update(['favicon' => $favicon]);

            $console->info('  Storefront favicon set to: '.$favicon);
        }
    }

    /**
     * Copy a dedicated favicon image (config `branding.favicon`, relative to
     * the WooCommerce uploads directory) into public storage. Returns the
     * stored path, or null when no favicon is configured or the file is
     * missing (the caller then falls back to reusing the logo).
     */
    protected function copyFavicon(): ?string
    {
        $relative = trim((string) config('woo-importer.branding.favicon', ''));

        if ($relative === '') {
            return null;
        }

        $absolute = rtrim((string) config('woo-importer.uploads_path'), '/').'/'.ltrim($relative, '/');

        if (! is_file($absolute)) {
            return null;
        }

        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) ?: 'png';
        $target = 'channel/'.$this->channelId.'/favicon.'.$ext;

        Storage::disk('public')->put($target, file_get_contents($absolute));

        return $target;
    }

    /**
     * Copy the configured brand logo image from the WooCommerce uploads
     * directory into public storage and return its (public-disk relative)
     * path. Returns null when no logo is configured or the file is missing.
     */
    protected function copyBrandLogo(): ?string
    {
        $relative = trim((string) config('woo-importer.branding.logo', ''));

        if ($relative === '') {
            return null;
        }

        $absolute = rtrim((string) config('woo-importer.uploads_path'), '/').'/'.ltrim($relative, '/');

        if (! is_file($absolute)) {
            return null;
        }

        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) ?: 'jpg';
        $target = 'channel/'.$this->channelId.'/logo.'.$ext;

        Storage::disk('public')->put($target, file_get_contents($absolute));

        return $target;
    }

    /**
     * Build the storefront logo as a small text SVG (the fallback when no
     * brand logo image is configured) and return its public-disk path. Returns
     * null when no logo segments are configured.
     */
    protected function buildWordmarkLogo(): ?string
    {
        $segments = array_values(array_filter(
            (array) config('woo-importer.branding.logo_segments', []),
            fn ($segment) => is_array($segment) && isset($segment['text'])
        ));

        if (empty($segments)) {
            return null;
        }

        $wordmark = $this->brandName();

        $tspans = '';

        foreach ($segments as $segment) {
            $tspans .= '<tspan fill="'.e($segment['color'] ?? '#0f172a').'">'.e($segment['text']).'</tspan>';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 48" width="280" height="48" role="img" aria-label="'.e($wordmark).'">'
            .'<text x="0" y="35" font-family="\'Poppins\',\'Segoe UI\',Arial,Helvetica,sans-serif" font-size="30" font-weight="700" letter-spacing="-0.5">'
            .$tspans
            .'</text>'
            .'</svg>';

        $path = 'channel/'.$this->channelId.'/logo.svg';

        Storage::disk('public')->put($path, $svg);

        return $path;
    }

    /**
     * Migrate the real WordPress content pages, replacing the demo CMS pages.
     */
    protected function migrateCmsPages(Command $console): void
    {
        $this->purgeUnmappedCmsPages();

        $slugs = (array) config('woo-importer.content.cms_pages', []);

        $migrated = 0;

        foreach ($slugs as $slug) {
            $page = $this->woo->table('posts')
                ->where('post_type', 'page')
                ->where('post_name', $slug)
                ->where('post_status', 'publish')
                ->first(['ID', 'post_title', 'post_name', 'post_content']);

            if (! $page) {
                continue;
            }

            $html = $this->cleanHtml((string) $page->post_content);

            if ($html === '') {
                continue;
            }

            $title = trim((string) $page->post_title) ?: ucwords(str_replace('-', ' ', $slug));

            if ($bagistoId = $this->mapping->get(Mapping::ENTITY_CMS_PAGE, $page->ID)) {
                DB::table('cms_page_translations')
                    ->where('cms_page_id', $bagistoId)
                    ->update([
                        'html_content' => $html,
                        'page_title' => $title,
                    ]);

                continue;
            }

            $urlKey = $this->uniqueUrlKey($slug ?: 'page');

            $cmsId = DB::table('cms_pages')->insertGetId([
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($this->channelLocales() as $locale) {
                DB::table('cms_page_translations')->insert([
                    'locale' => $locale,
                    'cms_page_id' => $cmsId,
                    'url_key' => $urlKey,
                    'html_content' => $html,
                    'page_title' => $title,
                    'meta_title' => $title,
                    'meta_description' => '',
                    'meta_keywords' => '',
                ]);
            }

            DB::table('cms_page_channels')->insert([
                'cms_page_id' => $cmsId,
                'channel_id' => $this->channelId,
            ]);

            $this->mapping->put(Mapping::ENTITY_CMS_PAGE, $page->ID, $cmsId, [
                'url_key' => $urlKey,
                'title' => $title,
            ]);

            $migrated++;
        }

        $console->info("  CMS pages migrated: {$migrated}");
    }

    /**
     * Make the category tree behave like the WooCommerce storefront:
     *  1. Link every product to ALL of its ancestor categories (not just the
     *     leaf), so a parent category page lists the products that live in its
     *     sub-categories — exactly how WooCommerce shows them.
     *  2. Hide categories that (including descendants) contain no products,
     *     matching WooCommerce's default "hide empty categories" behaviour.
     *
     * Both steps are idempotent so the command can be re-run safely.
     */
    protected function normalizeCategoryTree(Command $console): void
    {
        $rootId = (int) config('woo-importer.defaults.root_category_id', 1);

        // (1) Back-fill ancestor links using the nested-set bounds.
        DB::statement('
            INSERT IGNORE INTO product_categories (product_id, category_id)
            SELECT DISTINCT pc.product_id, anc.id
            FROM product_categories pc
            JOIN categories leaf ON leaf.id = pc.category_id
            JOIN categories anc ON anc._lft < leaf._lft
                               AND anc._rgt > leaf._rgt
                               AND anc.id <> ?
        ', [$rootId]);

        // (2) Hide empty categories; (re-)enable the ones that have products so
        // the rule is fully recomputed on every run.
        $hidden = DB::table('categories as c')
            ->where('c.id', '<>', $rootId)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product_categories as pc')
                    ->whereColumn('pc.category_id', 'c.id');
            })
            ->update(['status' => 0]);

        DB::table('categories as c')
            ->where('c.id', '<>', $rootId)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product_categories as pc')
                    ->whereColumn('pc.category_id', 'c.id');
            })
            ->update(['status' => 1]);

        $console->info("  Empty categories hidden: {$hidden}");
    }

    /**
     * Borrow a product image for each top-level category that has none, so the
     * category carousel on the home page renders thumbnails instead of blanks.
     */
    protected function decorateCategories(Command $console): void
    {
        $rootId = (int) config('woo-importer.defaults.root_category_id', 1);

        $categories = DB::table('categories')
            ->where('parent_id', $rootId)
            ->get(['id', 'logo_path']);

        $decorated = 0;

        foreach ($categories as $category) {
            if (! empty($category->logo_path)) {
                continue;
            }

            if (! $image = $this->sampleCategoryImage((int) $category->id)) {
                continue;
            }

            DB::table('categories')
                ->where('id', $category->id)
                ->update(['logo_path' => $image]);

            $decorated++;
        }

        $console->info("  Categories given a thumbnail: {$decorated}");
    }

    /**
     * Rebuild the channel's home-page sections from real catalogue data,
     * removing every demo theme customization in the process.
     */
    protected function rebuildHomePage(Command $console): void
    {
        $existing = DB::table('theme_customizations')
            ->where('channel_id', $this->channelId)
            ->pluck('id')
            ->all();

        if (! empty($existing)) {
            DB::table('theme_customization_translations')->whereIn('theme_customization_id', $existing)->delete();
            DB::table('theme_customizations')->whereIn('id', $existing)->delete();
        }

        $rootId = (int) config('woo-importer.defaults.root_category_id', 1);
        $locales = $this->channelLocales();
        $sortOrder = 1;

        // (1) Hero banner — the configured banner image, or a text hero fallback.
        $this->addCustomization('static_content', 'Hero Banner', $sortOrder++, $locales, fn () => $this->heroOptions());

        // (2) Shop-by-category carousel (uses the thumbnails added above).
        $this->addCustomization('category_carousel', 'Shop by Category', $sortOrder++, $locales, fn () => [
            'title' => 'Shop by Category',
            'filters' => ['parent_id' => $rootId, 'sort' => 'asc', 'limit' => 12],
        ]);

        // (3) One product carousel per best-stocked real category.
        foreach ($this->topCategoriesByProductCount((int) config('woo-importer.content.product_carousel_count', 3)) as $category) {
            $name = (string) $category->name;

            $this->addCustomization('product_carousel', $name, $sortOrder++, $locales, fn () => [
                'title' => $name,
                'filters' => ['category_id' => (int) $category->id, 'sort' => 'created_at-desc', 'limit' => 10],
            ]);
        }

        // (4) Services strip (generic, store-agnostic copy).
        $this->addCustomization('services_content', 'Services Content', $sortOrder++, $locales, fn () => $this->servicesOptions());

        // (5) Footer links pointing at the migrated CMS pages.
        if ($footer = $this->footerLinkOptions()) {
            $this->addCustomization('footer_links', 'Footer Links', $sortOrder++, $locales, fn () => $footer);
        }

        $console->info('  Home-page sections rebuilt with real data.');
    }

    /**
     * Insert one theme customization row plus a translation per locale.
     *
     * @param  array<int, string>  $locales
     * @param  callable():array  $optionsFactory
     */
    protected function addCustomization(string $type, string $name, int $sortOrder, array $locales, callable $optionsFactory): void
    {
        $id = DB::table('theme_customizations')->insertGetId([
            'type' => $type,
            'name' => $name,
            'sort_order' => $sortOrder,
            'status' => 1,
            'channel_id' => $this->channelId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $options = json_encode($optionsFactory());

        foreach ($locales as $locale) {
            DB::table('theme_customization_translations')->insert([
                'theme_customization_id' => $id,
                'locale' => $locale,
                'options' => $options,
            ]);
        }
    }

    /**
     * Home-page hero. Uses the configured banner image when it is available
     * (a clean, full-width clickable banner), otherwise falls back to the
     * text hero (wordmark + tagline + "Shop now").
     *
     * @return array<string, string>
     */
    protected function heroOptions(): array
    {
        $cta = ($top = $this->topCategoriesByProductCount(1)->first())
            ? '/'.$this->categorySlug((int) $top->id)
            : '/';

        if ($banner = $this->heroBanner()) {
            return $this->heroImageOptions($banner, $cta);
        }

        return $this->heroTextOptions($cta);
    }

    /**
     * Copy the configured hero banner from the WooCommerce uploads directory
     * into public storage and return its (public-disk relative) path. Returns
     * null when no banner is configured or the source file is missing.
     */
    protected function heroBanner(): ?string
    {
        $relative = trim((string) config('woo-importer.branding.hero_banner', ''));

        if ($relative === '') {
            return null;
        }

        $absolute = rtrim((string) config('woo-importer.uploads_path'), '/').'/'.ltrim($relative, '/');

        if (! is_file($absolute)) {
            return null;
        }

        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) ?: 'jpg';
        $target = 'theme/'.$this->channelId.'/hero-banner.'.$ext;

        Storage::disk('public')->put($target, file_get_contents($absolute));

        return $target;
    }

    /**
     * Image hero: the whole banner is shown full-width and links to the shop.
     * The banner already carries its own title/products, so no text overlay.
     *
     * @return array<string, string>
     */
    protected function heroImageOptions(string $bannerPath, string $cta): array
    {
        // Root-relative URL so the stored hero works under any host/scheme
        // (migration must not bake the current APP_URL host into content).
        $url = parse_url((string) Storage::disk('public')->url($bannerPath), PHP_URL_PATH)
            ?: '/storage/'.ltrim($bannerPath, '/');
        $alt = $this->brandName();

        $html = '<a href="'.e($cta).'" class="um-hero" aria-label="'.e($alt).'">'
            .'<img src="'.e($url).'" alt="'.e($alt).'" loading="eager">'
            .'</a>';

        $css = '.um-hero{display:block;max-width:1320px;margin:24px auto 0;border-radius:20px;overflow:hidden;line-height:0;}'
            .'.um-hero img{display:block;width:100%;height:auto;}';

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Text hero options (wordmark + tagline + call-to-action).
     *
     * @return array<string, string>
     */
    protected function heroTextOptions(string $cta): array
    {
        $name = $this->brandName();
        $tagline = trim((string) $this->woo->option('blogdescription', '')) ?: 'Quality parts & accessories, shipped fast.';

        $html = '<div class="um-hero">'
            .'<div class="um-hero-inner">'
            .'<h1>'.e($name).'</h1>'
            .'<p>'.e($tagline).'</p>'
            .'<a href="'.e($cta).'"><button class="primary-button">Shop now</button></a>'
            .'</div>'
            .'</div>';

        $css = '.um-hero{background:linear-gradient(120deg,#0f172a 0%,#1e293b 60%,#334155 100%);border-radius:20px;'
            .'margin:24px auto 0;padding:80px 24px;max-width:1320px;}'
            .'.um-hero-inner{max-width:640px;margin:0 auto;text-align:center;color:#fff;}'
            .'.um-hero-inner h1{font-size:48px;font-weight:600;margin:0 0 12px;text-transform:none;}'
            .'.um-hero-inner p{font-size:18px;opacity:.85;margin:0 0 28px;}'
            .'@media (max-width:768px){.um-hero{padding:48px 16px;}.um-hero-inner h1{font-size:30px;}.um-hero-inner p{font-size:16px;}}';

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Generic services strip.
     *
     * @return array<string, mixed>
     */
    protected function servicesOptions(): array
    {
        return [
            'services' => [
                ['title' => 'Shipping', 'description' => 'Spend over $200 get free shipping', 'service_icon' => 'icon-truck'],
                ['title' => 'Raw Materials', 'description' => 'Our products are made using the best available materials', 'service_icon' => 'icon-product'],
                ['title' => 'Quality Control', 'description' => '100% inspection will be conducted before delivery', 'service_icon' => 'icon-tick'],
                ['title' => 'Market', 'description' => 'USA, UK, Europe, North America, Australia, Japan, etc', 'service_icon' => 'icon-location'],
                ['title' => '6 months Warranty', 'description' => 'We guarantee that all parts warranted if a problem is due to the quality issue of the product itself', 'service_icon' => 'icon-gdpr-safe'],
                ['title' => 'Money Back Guarantee', 'description' => 'Money back if does not fit your bike', 'service_icon' => 'icon-dollar-sign'],
            ],
        ];
    }

    /**
     * Build footer link columns from the migrated CMS pages.
     *
     * @return array<string, mixed>|null
     */
    protected function footerLinkOptions(): ?array
    {
        $links = [];

        foreach ($this->mapping->all(Mapping::ENTITY_CMS_PAGE) as $wooId => $bagistoId) {
            $meta = $this->mapping->meta(Mapping::ENTITY_CMS_PAGE, $wooId);

            if (empty($meta['url_key'])) {
                continue;
            }

            $links[] = [
                // Root-relative so the link is host/scheme independent.
                // "Contact us" points at the built-in contact form route, not a CMS page.
                'url' => $meta['url_key'] === 'contact-us'
                    ? '/contact-us'
                    : '/page/'.$meta['url_key'],
                'title' => $meta['title'] ?? ucwords(str_replace('-', ' ', $meta['url_key'])),
            ];
        }

        if (empty($links)) {
            return null;
        }

        // Split the links across two footer columns.
        $half = (int) ceil(count($links) / 2);

        $columns = [
            'column_1' => array_slice($links, 0, $half),
            'column_2' => array_slice($links, $half),
        ];

        foreach ($columns as $key => $items) {
            $columns[$key] = array_values(array_map(function ($item, $index) {
                $item['sort_order'] = $index + 1;

                return $item;
            }, $items, array_keys($items)));
        }

        return $columns;
    }

    /**
     * Delete CMS pages that are NOT part of our migration mapping (i.e. the
     * demo pages seeded by the installer), so real pages take their place.
     */
    protected function purgeUnmappedCmsPages(): void
    {
        $keepIds = array_values($this->mapping->all(Mapping::ENTITY_CMS_PAGE));

        $query = DB::table('cms_pages');

        if (! empty($keepIds)) {
            $query->whereNotIn('id', $keepIds);
        }

        $demoIds = $query->pluck('id')->all();

        if (empty($demoIds)) {
            return;
        }

        DB::table('cms_page_translations')->whereIn('cms_page_id', $demoIds)->delete();
        DB::table('cms_page_channels')->whereIn('cms_page_id', $demoIds)->delete();
        DB::table('cms_pages')->whereIn('id', $demoIds)->delete();
    }

    /**
     * Strip Gutenberg/HTML comments and shortcodes, and rewrite absolute
     * legacy URLs to relative ones.
     */
    protected function cleanHtml(string $raw): string
    {
        // Remove HTML and Gutenberg block comments (<!-- wp:... -->).
        $html = preg_replace('/<!--.*?-->/s', '', $raw) ?? $raw;

        // Turn social-icon shortcodes into real links BEFORE stripping the rest,
        // so the store's social profiles are preserved instead of dropped.
        $html = $this->expandSocialShortcodes($html);

        // Remove any remaining WordPress shortcodes such as [contact-form] or
        // [/vc_row], including ones carrying many attributes (any length, but
        // never spanning a closing bracket).
        $html = preg_replace('/\[\/?[a-z][^\]]*\]/i', '', $html) ?? $html;

        // Make legacy absolute links relative to the new store.
        foreach (['home', 'siteurl'] as $option) {
            $base = rtrim((string) $this->woo->option($option, ''), '/');

            if ($base !== '') {
                $html = str_replace($base, '', $html);
            }
        }

        return trim($html);
    }

    /**
     * Expand a WordPress "social icons" shortcode (e.g. [adswst_social_icons
     * facebook_link="..." instagram_link="..."]) into a real list of links so
     * the legacy store's social profiles survive the migration instead of
     * leaking the raw shortcode onto the page.
     */
    protected function expandSocialShortcodes(string $html): string
    {
        return preg_replace_callback(
            '/\[[a-z0-9_]*social[a-z0-9_]*\b[^\]]*\]/i',
            function (array $match) {
                $platforms = ['facebook', 'instagram', 'pinterest', 'youtube', 'twitter', 'linkedin', 'tiktok'];

                $links = [];

                foreach ($platforms as $platform) {
                    if (
                        preg_match('/'.$platform.'_link\s*=\s*"([^"]+)"/i', $match[0], $m)
                        && trim($m[1]) !== ''
                    ) {
                        $links[] = '<a href="'.e(trim($m[1])).'" target="_blank" rel="noopener noreferrer">'.ucfirst($platform).'</a>';
                    }
                }

                return empty($links)
                    ? ''
                    : '<div class="social-links">'.implode(' ', $links).'</div>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Find a product image path to reuse as a category thumbnail, looking at
     * the category itself first and then its direct children.
     */
    protected function sampleCategoryImage(int $categoryId): ?string
    {
        if ($path = $this->firstProductImageForCategories([$categoryId])) {
            return $path;
        }

        $childIds = DB::table('categories')
            ->where('parent_id', $categoryId)
            ->pluck('id')
            ->all();

        return $childIds ? $this->firstProductImageForCategories($childIds) : null;
    }

    /**
     * @param  array<int, int>  $categoryIds
     */
    protected function firstProductImageForCategories(array $categoryIds): ?string
    {
        return DB::table('product_categories as pc')
            ->join('product_images as pi', 'pi.product_id', '=', 'pc.product_id')
            ->whereIn('pc.category_id', $categoryIds)
            ->orderBy('pi.id')
            ->value('pi.path');
    }

    /**
     * Top-level categories ordered by the number of products directly in them.
     */
    protected function topCategoriesByProductCount(int $limit): Collection
    {
        $rootId = (int) config('woo-importer.defaults.root_category_id', 1);

        return DB::table('categories as c')
            ->join('category_translations as ct', function ($join) {
                $join->on('ct.category_id', '=', 'c.id')
                    ->where('ct.locale', '=', $this->defaultLocale);
            })
            ->join('product_categories as pc', 'pc.category_id', '=', 'c.id')
            ->where('c.parent_id', $rootId)
            ->groupBy('c.id', 'ct.name')
            ->orderByRaw('COUNT(pc.product_id) DESC')
            ->limit($limit)
            ->get(['c.id', 'ct.name']);
    }

    protected function categorySlug(int $categoryId): string
    {
        return (string) DB::table('category_translations')
            ->where('category_id', $categoryId)
            ->where('locale', $this->defaultLocale)
            ->value('slug');
    }

    /**
     * Locales enabled for the target channel (falls back to the default).
     *
     * @return array<int, string>
     */
    protected function channelLocales(): array
    {
        $locales = DB::table('channel_locales as cl')
            ->join('locales as l', 'l.id', '=', 'cl.locale_id')
            ->where('cl.channel_id', $this->channelId)
            ->pluck('l.code')
            ->all();

        return $locales ?: [$this->defaultLocale];
    }

    /**
     * Ensure a CMS url_key is unique across translations.
     */
    protected function uniqueUrlKey(string $slug): string
    {
        $base = $slug;
        $key = $base;
        $suffix = 1;

        while (DB::table('cms_page_translations')->where('url_key', $key)->exists()) {
            $key = $base.'-'.(++$suffix);
        }

        return $key;
    }

    protected function storeName(): string
    {
        return trim((string) $this->woo->option('blogname', '')) ?: config('app.name', 'Store');
    }

    /**
     * The display brand/wordmark used for the logo and hero. Falls back to the
     * WooCommerce store name when no branding wordmark is configured.
     */
    protected function brandName(): string
    {
        $wordmark = trim((string) config('woo-importer.branding.wordmark', ''));

        return $wordmark !== '' ? $wordmark : $this->storeName();
    }
}
