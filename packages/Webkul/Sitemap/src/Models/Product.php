<?php

namespace Webkul\Sitemap\Models;

use Illuminate\Support\Carbon;
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;
use Webkul\Product\Models\Product as BaseProduct;

class Product extends BaseProduct implements Sitemapable
{
    /**
     * To get the sitemap tag for the product.
     */
    public function toSitemapTag(): Url|string|array
    {
        // Variants of a configurable product are reached through their parent's
        // page; listing them would hand search engines near-duplicate pages.
        if (
            ! $this->url_key
            || ! $this->status
            || ! $this->visible_individually
            || $this->parent_id
        ) {
            return [];
        }

        return Url::create(route('shop.product_or_category.index', $this->url_key))
            ->setLastModificationDate(Carbon::create($this->updated_at));
    }
}
