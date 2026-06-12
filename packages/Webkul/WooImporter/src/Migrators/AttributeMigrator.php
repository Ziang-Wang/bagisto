<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeOptionRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Creates the global, configurable Bagisto attributes (and their options) that
 * are required to model WooCommerce "variable" products as Bagisto
 * "configurable" products.
 *
 * WooCommerce stores variation attributes as free-text, product-level custom
 * attributes (e.g. `attribute_brake-disc = "front brake disc"`). Bagisto needs
 * these to be real `select` attributes with predefined options before they can
 * act as a configurable product's super attribute, so we materialise one
 * Bagisto attribute per distinct Woo attribute name and one option per distinct
 * value.
 */
class AttributeMigrator
{
    /**
     * Bagisto system attribute codes that must never be reused for variation
     * attributes (they have special meaning / swatch types).
     *
     * @var array<int, string>
     */
    protected array $reservedCodes = [
        'sku', 'name', 'url_key', 'tax_category_id', 'new', 'featured',
        'visible_individually', 'status', 'short_description', 'description',
        'price', 'cost', 'special_price', 'special_price_from', 'special_price_to',
        'meta_title', 'meta_keywords', 'meta_description', 'length', 'width',
        'height', 'weight', 'color', 'size', 'brand', 'guest_checkout',
        'product_number', 'manage_stock', 'allow_rma', 'rma_rule_id', 'parent_id',
        'type', 'attribute_family_id',
    ];

    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping,
        protected AttributeRepository $attributeRepository,
        protected AttributeOptionRepository $attributeOptionRepository
    ) {}

    /**
     * Run the attribute migration.
     */
    public function migrate(Command $console): void
    {
        $attributeValues = $this->collectAttributeValues();

        if (empty($attributeValues)) {
            $console->warn('  No variation attributes found in the WooCommerce database.');

            return;
        }

        $groupId = (int) config('woo-importer.defaults.attribute_group_id', 1);

        foreach ($attributeValues as $wooName => $values) {
            $this->migrateAttribute($wooName, $values, $groupId, $console);
        }

        $console->info('  Configurable attributes ready: '.count($this->mapping->all(Mapping::ENTITY_ATTRIBUTE)));
    }

    /**
     * Build [wooAttributeName => [distinct, values...]] from the variation meta.
     *
     * @return array<string, array<int, string>>
     */
    protected function collectAttributeValues(): array
    {
        $rows = $this->woo->table('postmeta')
            ->where('meta_key', 'like', 'attribute_%')
            ->where('meta_value', '<>', '')
            ->distinct()
            ->get(['meta_key', 'meta_value']);

        $result = [];

        foreach ($rows as $row) {
            $wooName = Str::after($row->meta_key, 'attribute_');

            $value = trim((string) $row->meta_value);

            if ($wooName === '' || $value === '') {
                continue;
            }

            $result[$wooName][$value] = $value;
        }

        return array_map(fn ($values) => array_values($values), $result);
    }

    /**
     * Create (or resolve) one Bagisto attribute and all of its options.
     *
     * @param  array<int, string>  $values
     */
    protected function migrateAttribute(string $wooName, array $values, int $groupId, Command $console): void
    {
        if ($this->mapping->get(Mapping::ENTITY_ATTRIBUTE, $wooName)) {
            $this->ensureOptions($wooName, $values);

            return;
        }

        $code = $this->resolveCode($wooName);

        $label = Str::title(str_replace(['-', '_'], ' ', $wooName));

        $attribute = $this->attributeRepository->create([
            'code' => $code,
            'admin_name' => $label,
            'type' => 'select',
            'is_required' => 0,
            'is_unique' => 0,
            'is_filterable' => 1,
            'is_configurable' => 1,
            'is_visible_on_front' => 1,
            'is_comparable' => 1,
            'is_user_defined' => 1,
            'value_per_locale' => 0,
            'value_per_channel' => 0,
            'swatch_type' => 'dropdown',
            'position' => 100,
        ] + $this->localizedName($label));

        $this->mapping->put(Mapping::ENTITY_ATTRIBUTE, $wooName, $attribute->id, ['code' => $code]);

        $this->attachToFamilyGroup($attribute->id, $groupId);

        $this->ensureOptions($wooName, $values);

        $console->line("  • <info>{$label}</info> (<comment>{$code}</comment>) — ".count($values).' options');
    }

    /**
     * Create any missing options for an attribute and map value => option id.
     *
     * @param  array<int, string>  $values
     */
    protected function ensureOptions(string $wooName, array $values): void
    {
        $attributeId = $this->mapping->get(Mapping::ENTITY_ATTRIBUTE, $wooName);

        if (! $attributeId) {
            return;
        }

        $sortOrder = 0;

        foreach ($values as $value) {
            $optionKey = $wooName.'|'.$value;

            if ($this->mapping->get(Mapping::ENTITY_ATTRIBUTE_OPTION, $optionKey)) {
                continue;
            }

            $option = $this->attributeOptionRepository->create([
                'attribute_id' => $attributeId,
                'admin_name' => $value,
                'sort_order' => ++$sortOrder,
            ] + $this->localizedLabel($value));

            $this->mapping->put(Mapping::ENTITY_ATTRIBUTE_OPTION, $optionKey, $option->id, [
                'value' => $value,
            ]);
        }
    }

    /**
     * Link an attribute to a group of the default attribute family so it shows
     * up in the family's custom attributes and can be used as a super attribute.
     */
    protected function attachToFamilyGroup(int $attributeId, int $groupId): void
    {
        $exists = DB::table('attribute_group_mappings')
            ->where('attribute_id', $attributeId)
            ->where('attribute_group_id', $groupId)
            ->exists();

        if ($exists) {
            return;
        }

        $position = (int) DB::table('attribute_group_mappings')
            ->where('attribute_group_id', $groupId)
            ->max('position');

        DB::table('attribute_group_mappings')->insert([
            'attribute_id' => $attributeId,
            'attribute_group_id' => $groupId,
            'position' => $position + 1,
        ]);
    }

    /**
     * Turn a WooCommerce attribute name into a safe, unique Bagisto code.
     */
    protected function resolveCode(string $wooName): string
    {
        $code = Str::slug($wooName, '_');

        $code = preg_replace('/[^a-z0-9_]/', '', strtolower($code)) ?: 'attr';

        if (ctype_digit($code[0])) {
            $code = 'attr_'.$code;
        }

        if (in_array($code, $this->reservedCodes)) {
            $code = 'woo_'.$code;
        }

        // Guarantee uniqueness against attributes already in the database.
        $base = $code;
        $suffix = 1;

        while ($this->attributeRepository->findOneByField('code', $code)) {
            $code = $base.'_'.(++$suffix);
        }

        return $code;
    }

    /**
     * Build per-locale `name` translation payload for an attribute.
     */
    protected function localizedName(string $label): array
    {
        $data = [];

        foreach (core()->getAllLocales() as $locale) {
            $data[$locale->code] = ['name' => $label];
        }

        return $data;
    }

    /**
     * Build per-locale `label` translation payload for an option.
     */
    protected function localizedLabel(string $label): array
    {
        $data = [];

        foreach (core()->getAllLocales() as $locale) {
            $data[$locale->code] = ['label' => $label];
        }

        return $data;
    }
}
