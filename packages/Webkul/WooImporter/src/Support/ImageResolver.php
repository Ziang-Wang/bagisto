<?php

namespace Webkul\WooImporter\Support;

use Illuminate\Http\UploadedFile;

/**
 * Turns legacy WooCommerce attachment paths into {@see UploadedFile} instances
 * that Bagisto's ProductImageRepository can consume (it re-encodes them to
 * webp and stores them under storage/app/public/product/{id}).
 */
class ImageResolver
{
    protected string $uploadsPath;

    public function __construct(protected WooClient $woo)
    {
        // Normalise: strip a trailing slash and an accidental ".../uploads" duplication later.
        $this->uploadsPath = rtrim((string) config('woo-importer.uploads_path'), '/');
    }

    /**
     * Whether the configured uploads directory exists locally.
     */
    public function available(): bool
    {
        return $this->uploadsPath !== '' && is_dir($this->uploadsPath);
    }

    public function uploadsPath(): string
    {
        return $this->uploadsPath;
    }

    /**
     * Build an UploadedFile for a single WordPress attachment id.
     */
    public function fromAttachmentId(?int $attachmentId): ?UploadedFile
    {
        $relative = $this->woo->attachmentRelativePath($attachmentId);

        return $this->fromRelativePath($relative);
    }

    /**
     * Build an UploadedFile from a path relative to wp-content/uploads.
     */
    public function fromRelativePath(?string $relative): ?UploadedFile
    {
        if (empty($relative)) {
            return null;
        }

        $absolute = $this->uploadsPath.'/'.ltrim($relative, '/');

        if (! is_file($absolute)) {
            return null;
        }

        // test mode (5th arg = true) bypasses is_uploaded_file() checks so this works in CLI.
        return new UploadedFile(
            $absolute,
            basename($absolute),
            mime_content_type($absolute) ?: null,
            null,
            true
        );
    }

    /**
     * Build an ordered, de-duplicated list of UploadedFiles for a product:
     * the featured image first, then the gallery images.
     *
     * @param  array<int, int>  $attachmentIds
     * @return array<int, UploadedFile>
     */
    public function fromAttachmentIds(array $attachmentIds): array
    {
        $files = [];
        $seen = [];

        foreach (array_filter(array_unique($attachmentIds)) as $id) {
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            if ($file = $this->fromAttachmentId((int) $id)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
