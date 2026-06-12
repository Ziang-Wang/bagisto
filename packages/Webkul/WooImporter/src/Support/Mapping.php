<?php

namespace Webkul\WooImporter\Support;

use Illuminate\Support\Facades\DB;

/**
 * Persists and resolves the link between legacy WooCommerce records and the
 * Bagisto records they were migrated to (stored in `woo_import_maps`).
 *
 * This keeps every migration step idempotent: before creating a record the
 * migrators ask here whether it already exists.
 */
class Mapping
{
    const ENTITY_CATEGORY = 'category';

    const ENTITY_ATTRIBUTE = 'attribute';

    const ENTITY_ATTRIBUTE_OPTION = 'attribute_option';

    const ENTITY_PRODUCT = 'product';

    const ENTITY_CUSTOMER = 'customer';

    const ENTITY_CMS_PAGE = 'cms_page';

    /**
     * Remember a mapping (insert or update).
     */
    public function put(string $entity, string|int $wooKey, int $bagistoId, array $meta = []): void
    {
        DB::table('woo_import_maps')->updateOrInsert(
            ['entity' => $entity, 'woo_key' => (string) $wooKey],
            ['bagisto_id' => $bagistoId, 'meta' => json_encode($meta), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Resolve the Bagisto id for a legacy key, or null when not migrated yet.
     */
    public function get(string $entity, string|int $wooKey): ?int
    {
        $id = DB::table('woo_import_maps')
            ->where('entity', $entity)
            ->where('woo_key', (string) $wooKey)
            ->value('bagisto_id');

        return $id ? (int) $id : null;
    }

    /**
     * Fetch the stored meta payload for a legacy key.
     */
    public function meta(string $entity, string|int $wooKey): array
    {
        $meta = DB::table('woo_import_maps')
            ->where('entity', $entity)
            ->where('woo_key', (string) $wooKey)
            ->value('meta');

        return $meta ? (array) json_decode($meta, true) : [];
    }

    /**
     * Return [woo_key => bagisto_id] for an entity type.
     */
    public function all(string $entity): array
    {
        return DB::table('woo_import_maps')
            ->where('entity', $entity)
            ->pluck('bagisto_id', 'woo_key')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Remove every mapping for a fresh re-run.
     */
    public function flush(?string $entity = null): void
    {
        $query = DB::table('woo_import_maps');

        if ($entity) {
            $query->where('entity', $entity);
        }

        $query->delete();
    }
}
