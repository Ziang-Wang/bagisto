<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webkul\Category\Models\CategoryTranslation;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Migrates WooCommerce product categories (the `product_cat` taxonomy) into
 * Bagisto categories, preserving the parent/child hierarchy.
 */
class CategoryMigrator
{
    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping,
        protected CategoryRepository $categoryRepository
    ) {}

    /**
     * Run the category migration.
     */
    public function migrate(Command $console): void
    {
        $rootId = (int) config('woo-importer.defaults.root_category_id', 1);

        $terms = $this->fetchCategoryTerms();

        if ($terms->isEmpty()) {
            $console->warn('  No product categories found in the WooCommerce database.');

            return;
        }

        /**
         * Sort so that parents are always created before their children. Because
         * the source uses at most a couple of nesting levels, ordering by parent
         * id (0 first) is enough; deeper trees are handled by the retry loop.
         */
        $pending = $terms->sortBy('parent')->values()->all();

        $bar = $console->getOutput()->createProgressBar(count($pending));

        $bar->start();

        $deferred = [];

        foreach ($pending as $term) {
            if (! $this->tryCreate($term, $rootId)) {
                $deferred[] = $term;
            }

            $bar->advance();
        }

        /**
         * Second pass for children whose parent had not been created yet on the
         * first pass (out-of-order rows).
         */
        foreach ($deferred as $term) {
            $this->tryCreate($term, $rootId);
        }

        $bar->finish();

        $console->newLine();

        $console->info('  Categories migrated: '.count($this->mapping->all(Mapping::ENTITY_CATEGORY)));
    }

    /**
     * Attempt to create a single category. Returns false when the parent is not
     * available yet so the caller can retry it later.
     */
    protected function tryCreate(object $term, int $rootId): bool
    {
        if ($this->mapping->get(Mapping::ENTITY_CATEGORY, $term->term_id)) {
            return true;
        }

        $parentId = $rootId;

        if (! empty($term->parent)) {
            $mappedParent = $this->mapping->get(Mapping::ENTITY_CATEGORY, $term->parent);

            if (! $mappedParent) {
                return false;
            }

            $parentId = $mappedParent;
        }

        $slug = $term->slug ?: Str::slug($term->name);

        $slug = $this->uniqueSlug($slug);

        // Build locale-nested translation data (the same shape the admin sends),
        // filling every locale with the source values so the category shows up
        // regardless of the active storefront locale.
        $translation = [
            'name' => $term->name,
            'slug' => $slug,
            'description' => $term->description ?: '',
            'meta_title' => '',
            'meta_description' => '',
            'meta_keywords' => '',
        ];

        $data = [
            'locale' => core()->getDefaultLocaleCodeFromDefaultChannel(),
            'parent_id' => $parentId,
            'status' => 1,
            'position' => 1,
            'display_mode' => 'products_and_description',
        ];

        foreach (core()->getAllLocales() as $locale) {
            $data[$locale->code] = $translation;
        }

        $category = $this->categoryRepository->create($data);

        $this->mapping->put(Mapping::ENTITY_CATEGORY, $term->term_id, $category->id, [
            'slug' => $slug,
            'name' => $term->name,
        ]);

        return true;
    }

    /**
     * Categories store the slug as a translated attribute; the slug must be
     * unique across all categories. Append a numeric suffix on collision.
     */
    protected function uniqueSlug(string $slug): string
    {
        $candidate = $slug;
        $suffix = 1;

        while (CategoryTranslation::where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.(++$suffix);
        }

        return $candidate;
    }

    /**
     * Pull the product_cat terms together with their description and parent.
     */
    protected function fetchCategoryTerms()
    {
        return $this->woo->table('term_taxonomy as tt')
            ->join($this->prefixed('terms').' as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'product_cat')
            ->orderBy('tt.parent')
            ->get([
                't.term_id',
                't.name',
                't.slug',
                'tt.parent',
                'tt.description',
            ]);
    }

    /**
     * Prefix a table name for use inside raw join clauses.
     */
    protected function prefixed(string $table): string
    {
        return config('woo-importer.table_prefix', 'wp_').$table;
    }
}
