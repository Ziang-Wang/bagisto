<?php

namespace Webkul\WooImporter\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Thin read-only gateway to the legacy WooCommerce database.
 *
 * All access to the "woocommerce" connection goes through here so table
 * prefixing, serialized-meta decoding and the most common lookups live in a
 * single place.
 */
class WooClient
{
    /**
     * Legacy table prefix (e.g. "wp_").
     */
    protected string $prefix;

    public function __construct()
    {
        $this->prefix = config('woo-importer.table_prefix', 'wp_');
    }

    /**
     * Get a query builder for a prefixed legacy table.
     */
    public function table(string $name): Builder
    {
        return DB::connection('woocommerce')->table($this->prefix.$name);
    }

    /**
     * Verify the connection works and the expected WooCommerce tables exist.
     *
     * @return array<int, string> List of missing tables (empty when healthy).
     */
    public function missingTables(): array
    {
        $required = ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships'];

        $missing = [];

        foreach ($required as $table) {
            try {
                DB::connection('woocommerce')->table($this->prefix.$table)->limit(1)->exists();
            } catch (\Throwable $e) {
                $missing[] = $this->prefix.$table;
            }
        }

        return $missing;
    }

    /**
     * Fetch all post meta for a post as a flat key => value array.
     * When a meta key repeats, the values are returned as an array.
     */
    public function meta(int $postId): array
    {
        $rows = $this->table('postmeta')
            ->where('post_id', $postId)
            ->get(['meta_key', 'meta_value']);

        $meta = [];

        foreach ($rows as $row) {
            if (array_key_exists($row->meta_key, $meta)) {
                $meta[$row->meta_key] = (array) $meta[$row->meta_key];
                $meta[$row->meta_key][] = $row->meta_value;
            } else {
                $meta[$row->meta_key] = $row->meta_value;
            }
        }

        return $meta;
    }

    /**
     * Fetch meta for many posts at once. Returns [post_id => [key => value]].
     *
     * @param  array<int, int>  $postIds
     * @return array<int, array<string, mixed>>
     */
    public function metaForMany(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }

        $rows = $this->table('postmeta')
            ->whereIn('post_id', $postIds)
            ->get(['post_id', 'meta_key', 'meta_value']);

        $result = [];

        foreach ($rows as $row) {
            $result[$row->post_id][$row->meta_key] = $row->meta_value;
        }

        return $result;
    }

    /**
     * Resolve an attachment id to its file path relative to wp-content/uploads.
     */
    public function attachmentRelativePath(?int $attachmentId): ?string
    {
        if (empty($attachmentId)) {
            return null;
        }

        $path = $this->table('postmeta')
            ->where('post_id', $attachmentId)
            ->where('meta_key', '_wp_attached_file')
            ->value('meta_value');

        return $path ?: null;
    }

    /**
     * Safely unserialize a WordPress meta value (used for `_product_attributes`,
     * gallery ids, etc.). Returns an empty array on any failure.
     */
    public function unserialize(?string $value): array
    {
        if (empty($value)) {
            return [];
        }

        $data = @unserialize($value, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }

    /**
     * Get the store-wide option value from wp_options.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        $value = $this->table('options')
            ->where('option_name', $name)
            ->value('option_value');

        return $value ?? $default;
    }
}
