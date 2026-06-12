<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\WooImporter\Support\ImageResolver;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Migrates WooCommerce products into Bagisto.
 *
 * Mapping rules (see config/woo-importer.php → variation_strategy):
 *  - A variable product with a single variation  → Bagisto simple product
 *  - A variable product with 2+ variations        → Bagisto configurable product
 *  - "flatten" strategy                            → every variation becomes a simple product
 *
 * Categories and the configurable super attributes must already be migrated
 * (CategoryMigrator + AttributeMigrator) so their ids can be resolved here.
 */
class ProductMigrator
{
    /** url_key attribute id from the default Bagisto install. */
    const URL_KEY_ATTRIBUTE_ID = 3;

    protected string $defaultChannel;

    protected string $defaultLocale;

    protected bool $importImages = true;

    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping,
        protected ProductRepository $productRepository,
        protected ImageResolver $images
    ) {
        $this->defaultChannel = core()->getDefaultChannelCode();
        $this->defaultLocale = core()->getDefaultLocaleCodeFromDefaultChannel();
    }

    /**
     * Run the product migration.
     */
    public function migrate(Command $console, ?int $limit = null, bool $importImages = true): void
    {
        $this->importImages = $importImages;

        if ($this->importImages && ! $this->images->available()) {
            $console->warn('  Uploads directory not found ('.$this->images->uploadsPath().'). Products will be imported WITHOUT images.');

            $this->importImages = false;
        }

        $query = $this->woo->table('posts')
            ->where('post_type', 'product')
            ->whereIn('post_status', ['publish', 'private'])
            ->orderBy('ID');

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get(['ID', 'post_title', 'post_name', 'post_content', 'post_excerpt', 'post_status']);

        $strategy = config('woo-importer.variation_strategy', 'auto');

        $bar = $console->getOutput()->createProgressBar($products->count());

        $bar->start();

        $succeeded = $failed = 0;

        foreach ($products as $wooProduct) {
            try {
                DB::transaction(function () use ($wooProduct, $strategy) {
                    $this->migrateProduct($wooProduct, $strategy);
                });

                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;

                $console->newLine();
                $console->error("  ✗ Product #{$wooProduct->ID} ({$wooProduct->post_title}): ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();

        $console->newLine();
        $console->info("  Products migrated: {$succeeded}".($failed ? " — failed: {$failed}" : ''));
    }

    /**
     * Migrate a single WooCommerce product based on the chosen strategy.
     */
    protected function migrateProduct(object $wooProduct, string $strategy): void
    {
        if ($this->mapping->get(Mapping::ENTITY_PRODUCT, $wooProduct->ID)) {
            return;
        }

        $meta = $this->woo->meta((int) $wooProduct->ID);

        $variations = $this->loadVariations((int) $wooProduct->ID);

        $categories = $this->resolveCategories((int) $wooProduct->ID);

        if ($strategy === 'flatten') {
            $this->migrateAsFlattened($wooProduct, $meta, $variations, $categories);

            return;
        }

        $useConfigurable = $strategy === 'configurable'
            ? count($variations) >= 1
            : count($variations) >= 2;

        if ($useConfigurable && $this->canBuildConfigurable($variations)) {
            $this->migrateAsConfigurable($wooProduct, $meta, $variations, $categories);
        } else {
            $this->migrateAsSimple($wooProduct, $meta, $variations, $categories);
        }
    }

    // =========================================================================
    // Simple products
    // =========================================================================

    /**
     * Migrate as a single simple product (1-variation or non-variable product).
     *
     * @param  array<int, object>  $variations
     * @param  array<int, int>  $categories
     */
    protected function migrateAsSimple(object $wooProduct, array $meta, array $variations, array $categories): void
    {
        // Prefer the variation's commerce data, fall back to the parent meta.
        $source = isset($variations[0]) ? $variations[0]->meta : $meta;

        $sku = $this->uniqueSku($this->pick($source, '_sku') ?: 'prod-'.$wooProduct->ID);

        $product = $this->productRepository->create([
            'type' => 'simple',
            'attribute_family_id' => (int) config('woo-importer.defaults.attribute_family_id', 1),
            'sku' => $sku,
        ]);

        $imageIds = array_merge(
            [(int) $this->pick($meta, '_thumbnail_id')],
            isset($variations[0]) ? [(int) $this->pick($variations[0]->meta, '_thumbnail_id')] : [],
            $this->galleryIds($meta),
        );

        $data = array_merge(
            $this->commonProductData($wooProduct, $meta, $categories),
            [
                'sku' => $sku,
                'price' => $this->price($source),
                'special_price' => $this->specialPrice($source),
                'weight' => $this->pick($source, '_weight') ?: 0,
                'inventories' => [config('woo-importer.defaults.inventory_source_id', 1) => $this->inventoryQty($source)],
                'images' => $this->imagePayload($imageIds),
            ]
        );

        $this->productRepository->update($data, $product->id);

        $this->mapping->put(Mapping::ENTITY_PRODUCT, $wooProduct->ID, $product->id, [
            'type' => 'simple',
            'sku' => $sku,
        ]);
    }

    /**
     * Migrate each variation as its own simple product (flatten strategy).
     *
     * @param  array<int, object>  $variations
     * @param  array<int, int>  $categories
     */
    protected function migrateAsFlattened(object $wooProduct, array $meta, array $variations, array $categories): void
    {
        if (empty($variations)) {
            $this->migrateAsSimple($wooProduct, $meta, [], $categories);

            return;
        }

        $first = null;

        foreach ($variations as $index => $variation) {
            $source = $variation->meta;

            $sku = $this->uniqueSku($this->pick($source, '_sku') ?: 'prod-'.$wooProduct->ID.'-'.$variation->ID);

            $product = $this->productRepository->create([
                'type' => 'simple',
                'attribute_family_id' => (int) config('woo-importer.defaults.attribute_family_id', 1),
                'sku' => $sku,
            ]);

            $variantLabel = $this->variationLabel($variation);

            $data = array_merge(
                $this->commonProductData($wooProduct, $meta, $categories),
                [
                    'sku' => $sku,
                    'name' => trim($wooProduct->post_title.($variantLabel ? ' - '.$variantLabel : '')),
                    'url_key' => $this->uniqueUrlKey(($wooProduct->post_name ?: Str::slug($wooProduct->post_title)).'-'.$variation->ID),
                    'price' => $this->price($source),
                    'special_price' => $this->specialPrice($source),
                    'weight' => $this->pick($source, '_weight') ?: 0,
                    'inventories' => [config('woo-importer.defaults.inventory_source_id', 1) => $this->inventoryQty($source)],
                    'images' => $this->imagePayload([
                        (int) $this->pick($source, '_thumbnail_id'),
                        (int) $this->pick($meta, '_thumbnail_id'),
                    ]),
                ]
            );

            $this->productRepository->update($data, $product->id);

            $first ??= $product->id;
        }

        $this->mapping->put(Mapping::ENTITY_PRODUCT, $wooProduct->ID, $first, ['type' => 'flattened']);
    }

    // =========================================================================
    // Configurable products
    // =========================================================================

    /**
     * Migrate as a configurable product with one super attribute.
     *
     * @param  array<int, object>  $variations
     * @param  array<int, int>  $categories
     */
    protected function migrateAsConfigurable(object $wooProduct, array $meta, array $variations, array $categories): void
    {
        $wooAttrName = $this->superAttributeName($variations);

        $attributeId = $this->mapping->get(Mapping::ENTITY_ATTRIBUTE, $wooAttrName);
        $attributeCode = $this->mapping->meta(Mapping::ENTITY_ATTRIBUTE, $wooAttrName)['code'] ?? null;

        if (! $attributeId || ! $attributeCode) {
            // Attribute not migrated — degrade gracefully to a simple product.
            $this->migrateAsSimple($wooProduct, $meta, $variations, $categories);

            return;
        }

        // Map each option id to its source variation.
        $wooVarByOption = [];
        $optionIds = [];

        foreach ($variations as $variation) {
            $value = $this->variationAttributeValue($variation, $wooAttrName);

            if ($value === null || $value === '') {
                continue;
            }

            $optionId = $this->mapping->get(Mapping::ENTITY_ATTRIBUTE_OPTION, $wooAttrName.'|'.$value);

            if (! $optionId || isset($wooVarByOption[$optionId])) {
                continue;
            }

            $wooVarByOption[$optionId] = $variation;
            $optionIds[] = $optionId;
        }

        if (count($optionIds) < 2) {
            $this->migrateAsSimple($wooProduct, $meta, $variations, $categories);

            return;
        }

        $parentSku = $this->uniqueSku($this->pick($meta, '_sku') ?: ($wooProduct->post_name ?: 'prod-'.$wooProduct->ID));

        $parent = $this->productRepository->create([
            'type' => 'configurable',
            'attribute_family_id' => (int) config('woo-importer.defaults.attribute_family_id', 1),
            'sku' => $parentSku,
            'super_attributes' => [$attributeCode => $optionIds],
        ]);

        // Build the variant payload by reading back the auto-generated variants.
        $variantsData = [];

        $variants = Product::with('attribute_values')->where('parent_id', $parent->id)->get();

        foreach ($variants as $variant) {
            $optionId = optional(
                $variant->attribute_values->firstWhere('attribute_id', $attributeId)
            )->integer_value;

            $wooVar = $wooVarByOption[$optionId] ?? null;

            if (! $wooVar) {
                continue;
            }

            $source = $wooVar->meta;

            $variantSku = $this->uniqueSku($this->pick($source, '_sku') ?: $variant->sku, $variant->id);

            $variantsData[$variant->id] = [
                'sku' => $variantSku,
                $attributeCode => $optionId,
                'name' => trim($wooProduct->post_title.' - '.$this->variationLabel($wooVar)),
                'price' => $this->price($source),
                'weight' => $this->pick($source, '_weight') ?: 0,
                'status' => 1,
                'inventories' => [config('woo-importer.defaults.inventory_source_id', 1) => $this->inventoryQty($source)],
                'images' => $this->imagePayload([(int) $this->pick($source, '_thumbnail_id')]),
            ];
        }

        $imageIds = array_merge([(int) $this->pick($meta, '_thumbnail_id')], $this->galleryIds($meta));

        $data = array_merge(
            $this->commonProductData($wooProduct, $meta, $categories),
            [
                'sku' => $parentSku,
                'images' => $this->imagePayload($imageIds),
                'variants' => $variantsData,
            ]
        );

        $this->productRepository->update($data, $parent->id);

        $this->mapping->put(Mapping::ENTITY_PRODUCT, $wooProduct->ID, $parent->id, [
            'type' => 'configurable',
            'sku' => $parentSku,
            'super_attribute' => $attributeCode,
        ]);
    }

    // =========================================================================
    // Shared building blocks
    // =========================================================================

    /**
     * Attribute data shared by every product type / variant parent.
     *
     * @param  array<int, int>  $categories
     */
    protected function commonProductData(object $wooProduct, array $meta, array $categories): array
    {
        return [
            'channel' => $this->defaultChannel,
            'locale' => $this->defaultLocale,
            'attribute_family_id' => (int) config('woo-importer.defaults.attribute_family_id', 1),
            'name' => $wooProduct->post_title ?: 'Product '.$wooProduct->ID,
            'url_key' => $this->uniqueUrlKey($wooProduct->post_name ?: Str::slug($wooProduct->post_title)),
            'short_description' => $wooProduct->post_excerpt ?: ' ',
            'description' => $wooProduct->post_content ?: ' ',
            'status' => $wooProduct->post_status === 'publish' ? 1 : 0,
            'visible_individually' => 1,
            'new' => 0,
            'featured' => $this->pick($meta, '_featured') === 'yes' ? 1 : 0,
            'meta_title' => $this->pick($meta, '_yoast_wpseo_title') ?: '',
            'meta_keywords' => $this->pick($meta, '_yoast_wpseo_focuskw') ?: '',
            'meta_description' => $this->pick($meta, '_yoast_wpseo_metadesc') ?: '',
            'categories' => $categories,
            'channels' => [(int) config('woo-importer.defaults.channel_id', 1)],
            'inventories' => [],
        ];
    }

    /**
     * Load a product's published variations together with their meta.
     *
     * @return array<int, object>
     */
    protected function loadVariations(int $productId): array
    {
        $rows = $this->woo->table('posts')
            ->where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->whereIn('post_status', ['publish', 'private'])
            ->orderBy('menu_order')
            ->get(['ID', 'post_title', 'menu_order']);

        if ($rows->isEmpty()) {
            return [];
        }

        $metaForAll = $this->woo->metaForMany($rows->pluck('ID')->all());

        return $rows->map(function ($row) use ($metaForAll) {
            $row->meta = $metaForAll[$row->ID] ?? [];

            return $row;
        })->all();
    }

    /**
     * Resolve Bagisto category ids for a Woo product.
     *
     * @return array<int, int>
     */
    protected function resolveCategories(int $productId): array
    {
        $termIds = $this->woo->table('term_relationships as tr')
            ->join($this->prefixed('term_taxonomy').' as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->where('tr.object_id', $productId)
            ->where('tt.taxonomy', 'product_cat')
            ->pluck('tt.term_id');

        $categories = [];

        foreach ($termIds as $termId) {
            if ($bagistoId = $this->mapping->get(Mapping::ENTITY_CATEGORY, $termId)) {
                $categories[] = $bagistoId;
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * Determine whether enough data exists to build a configurable product.
     *
     * @param  array<int, object>  $variations
     */
    protected function canBuildConfigurable(array $variations): bool
    {
        return count($variations) >= 1 && $this->superAttributeName($variations) !== null;
    }

    /**
     * The single Woo attribute name used across a product's variations.
     *
     * @param  array<int, object>  $variations
     */
    protected function superAttributeName(array $variations): ?string
    {
        foreach ($variations as $variation) {
            foreach ($variation->meta as $key => $value) {
                if (Str::startsWith($key, 'attribute_') && trim((string) $value) !== '') {
                    return Str::after($key, 'attribute_');
                }
            }
        }

        return null;
    }

    /**
     * The raw value of a variation for a given Woo attribute name.
     */
    protected function variationAttributeValue(object $variation, string $wooAttrName): ?string
    {
        $value = $variation->meta['attribute_'.$wooAttrName] ?? null;

        return $value !== null ? trim((string) $value) : null;
    }

    /**
     * Human readable label for a variation (its attribute value).
     */
    protected function variationLabel(object $variation): string
    {
        foreach ($variation->meta as $key => $value) {
            if (Str::startsWith($key, 'attribute_') && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Build the `images` payload Bagisto expects, or an empty array.
     *
     * @param  array<int, int>  $attachmentIds
     * @return array<string, mixed>
     */
    protected function imagePayload(array $attachmentIds): array
    {
        if (! $this->importImages) {
            return [];
        }

        $files = $this->images->fromAttachmentIds($attachmentIds);

        return $files ? ['files' => $files] : [];
    }

    /**
     * Comma separated gallery attachment ids from `_product_image_gallery`.
     *
     * @return array<int, int>
     */
    protected function galleryIds(array $meta): array
    {
        $value = $this->pick($meta, '_product_image_gallery');

        if (empty($value)) {
            return [];
        }

        return array_map('intval', array_filter(explode(',', $value)));
    }

    /**
     * Inventory quantity from a meta set, falling back to the configured
     * defaults when WooCommerce does not manage stock.
     */
    protected function inventoryQty(array $meta): int
    {
        $stock = $this->pick($meta, '_stock');

        if (is_numeric($stock)) {
            return max(0, (int) $stock);
        }

        $status = $this->pick($meta, '_stock_status') ?: 'instock';

        return $status === 'outofstock'
            ? (int) config('woo-importer.inventory.out_of_stock_qty', 0)
            : (int) config('woo-importer.inventory.default_in_stock_qty', 1000);
    }

    /**
     * Normalised regular price.
     */
    protected function price(array $meta): string
    {
        $price = $this->pick($meta, '_regular_price') ?: $this->pick($meta, '_price') ?: '0';

        return (string) (is_numeric($price) ? $price : 0);
    }

    /**
     * Normalised sale price (null when absent).
     */
    protected function specialPrice(array $meta): ?string
    {
        $sale = $this->pick($meta, '_sale_price');

        return is_numeric($sale) && (float) $sale > 0 ? (string) $sale : null;
    }

    /**
     * Read a meta key, flattening WooClient's possible array values.
     */
    protected function pick(array $meta, string $key): mixed
    {
        $value = $meta[$key] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    /**
     * Ensure a globally unique SKU (products.sku is unique).
     */
    protected function uniqueSku(string $sku, ?int $ignoreId = null): string
    {
        $sku = Str::limit(trim($sku) ?: 'sku', 250, '');

        $base = $sku;
        $suffix = 1;

        while (
            Product::where('sku', $sku)
                ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
                ->exists()
        ) {
            $sku = $base.'-'.(++$suffix);
        }

        return $sku;
    }

    /**
     * Ensure a globally unique url_key (stored as a product attribute value).
     */
    protected function uniqueUrlKey(string $key): string
    {
        $key = Str::slug($key) ?: 'product';

        $base = $key;
        $suffix = 1;

        while (
            ProductAttributeValue::where('attribute_id', self::URL_KEY_ATTRIBUTE_ID)
                ->where('text_value', $key)
                ->exists()
        ) {
            $key = $base.'-'.(++$suffix);
        }

        return $key;
    }

    /**
     * Prefix a table name for raw join clauses.
     */
    protected function prefixed(string $table): string
    {
        return config('woo-importer.table_prefix', 'wp_').$table;
    }
}
