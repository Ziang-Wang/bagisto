<?php

namespace Webkul\WooImporter\Migrators;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webkul\Product\Repositories\ProductReviewRepository;
use Webkul\WooImporter\Support\Mapping;
use Webkul\WooImporter\Support\WooClient;

/**
 * Migrates WooCommerce product reviews (`wp_comments` of type `review`) into
 * Bagisto product reviews.
 *
 * The original moderation state is preserved: approved Woo reviews become
 * "approved", everything else becomes "pending" so unmoderated/spam reviews
 * stay hidden on the storefront until the owner approves them. The 1–5 star
 * rating is copied from the `rating` comment-meta.
 *
 * Idempotent through the `woo_import_maps` table (entity = review).
 */
class ReviewMigrator
{
    public function __construct(
        protected WooClient $woo,
        protected Mapping $mapping,
        protected ProductReviewRepository $reviewRepository
    ) {}

    /**
     * Run the review migration.
     */
    public function migrate(Command $console): void
    {
        $reviews = $this->woo->table('comments')
            ->where('comment_type', 'review')
            ->get([
                'comment_ID',
                'comment_post_ID',
                'comment_author',
                'comment_author_email',
                'comment_content',
                'comment_approved',
                'comment_date',
            ]);

        if ($reviews->isEmpty()) {
            $console->warn('  No product reviews found in the legacy store.');

            return;
        }

        $migrated = $skipped = $orphaned = 0;

        foreach ($reviews as $review) {
            if ($this->mapping->get(Mapping::ENTITY_REVIEW, $review->comment_ID)) {
                $skipped++;

                continue;
            }

            // Resolve the Bagisto product the review belongs to.
            $productId = $this->mapping->get(Mapping::ENTITY_PRODUCT, (int) $review->comment_post_ID);

            if (! $productId) {
                $orphaned++;

                continue;
            }

            $rating = (int) $this->woo->table('commentmeta')
                ->where('comment_id', $review->comment_ID)
                ->where('meta_key', 'rating')
                ->value('meta_value');

            $rating = max(1, min(5, $rating ?: 5));

            $comment = trim((string) $review->comment_content);

            $created = $this->reviewRepository->create([
                'title' => Str::limit($comment, 50, '') ?: 'Review',
                'comment' => $comment,
                'rating' => $rating,
                'status' => $review->comment_approved === '1' ? 'approved' : 'pending',
                'product_id' => $productId,
                'customer_id' => null,
                'name' => $review->comment_author ?: 'Anonymous',
                'created_at' => $review->comment_date,
                'updated_at' => $review->comment_date,
            ]);

            $this->mapping->put(Mapping::ENTITY_REVIEW, $review->comment_ID, $created->id);

            $migrated++;
        }

        $console->info("  Reviews migrated: {$migrated} — skipped: {$skipped} — orphaned (no product): {$orphaned}");
    }
}
