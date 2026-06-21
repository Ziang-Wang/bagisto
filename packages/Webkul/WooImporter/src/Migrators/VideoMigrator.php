<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Migrates product videos from the legacy store.
 *
 * In WooCommerce the videos are stored as standalone `video/*` attachments that
 * are linked to a product (or one of its variations) through `post_parent`.
 * They are NOT referenced by the AliDropship "product video" meta (that field
 * is present on every product but empty). This migrator copies each video file
 * into the Bagisto product directory and registers it as a `videos` row in
 * `product_images`, resolving variation parents back to their configurable
 * product so the video lands on a real Bagisto product.
 *
 * Idempotent: existing video rows for a product are left untouched.
 */
class VideoMigrator
{
    protected string $uploadsPath;

    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping
    ) {
        $this->uploadsPath = rtrim((string) config('woo-importer.uploads_path'), '/');
    }

    /**
     * Run the video migration.
     */
    public function migrate(Command $console): void
    {
        if (! is_dir($this->uploadsPath)) {
            $console->warn('  Uploads directory not found ('.$this->uploadsPath.'). Skipping videos.');

            return;
        }

        $videos = $this->woo->table('posts')
            ->where('post_type', 'attachment')
            ->where('post_mime_type', 'like', 'video/%')
            ->get(['ID', 'post_parent']);

        if ($videos->isEmpty()) {
            $console->warn('  No product videos found in the legacy store.');

            return;
        }

        $migrated = $skipped = $orphaned = $missing = 0;

        foreach ($videos as $video) {
            $productId = $this->resolveProductId((int) $video->post_parent);

            if (! $productId) {
                $orphaned++;

                continue;
            }

            $relative = $this->woo->table('postmeta')
                ->where('post_id', $video->ID)
                ->where('meta_key', '_wp_attached_file')
                ->value('meta_value');

            if (! $relative) {
                $missing++;

                continue;
            }

            $absolute = $this->uploadsPath.'/'.ltrim($relative, '/');

            if (! is_file($absolute)) {
                $missing++;

                continue;
            }

            // Skip if this product already has this video (idempotent re-runs).
            $extension = pathinfo($absolute, PATHINFO_EXTENSION) ?: 'mp4';

            $alreadyHasVideo = DB::table('product_videos')
                ->where('product_id', $productId)
                ->where('type', 'videos')
                ->exists();

            if ($alreadyHasVideo) {
                $skipped++;

                continue;
            }

            $directory = 'product/'.$productId;

            $targetName = $directory.'/'.Str::random(40).'.'.$extension;

            Storage::put($targetName, file_get_contents($absolute));

            $position = (int) DB::table('product_videos')
                ->where('product_id', $productId)
                ->max('position');

            // Videos live in `product_videos` (NOT `product_images`); the storefront
            // gallery reads them via ProductVideo::getVideos($product->videos) and
            // renders a <video>. Putting them in product_images makes the gallery
            // treat them as broken <img>.
            DB::table('product_videos')->insert([
                'type' => 'videos',
                'path' => $targetName,
                'product_id' => $productId,
                'position' => $position + 1,
            ]);

            $migrated++;
        }

        $console->info("  Videos migrated: {$migrated} — skipped: {$skipped} — orphaned: {$orphaned} — file missing: {$missing}");
    }

    /**
     * Resolve a legacy attachment parent (product or variation) to a migrated
     * Bagisto product id.
     */
    protected function resolveProductId(int $wooParentId): ?int
    {
        if (! $wooParentId) {
            return null;
        }

        // Direct product mapping.
        if ($productId = $this->mapping->get(Mapping::ENTITY_PRODUCT, $wooParentId)) {
            return $productId;
        }

        // The parent is a variation — fall back to ITS parent product.
        $parent = $this->woo->table('posts')
            ->where('ID', $wooParentId)
            ->value('post_parent');

        if ($parent) {
            return $this->mapping->get(Mapping::ENTITY_PRODUCT, (int) $parent);
        }

        return null;
    }
}
