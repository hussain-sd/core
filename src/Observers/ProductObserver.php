<?php

namespace SmartTill\Core\Observers;

use Illuminate\Support\Facades\Cache;
use SmartTill\Core\Models\Product;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
        $this->invalidateProductSearchCache($product->store_id);
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        // If brand_id changed, update all variations with new brand_name
        if ($product->wasChanged('brand_id')) {
            $product->load('brand');
            $brandName = $product->brand?->name;

            $product->variations()->update([
                'brand_name' => $brandName,
            ]);
        }

        if ($product->wasChanged('name')) {
            $originalName = trim((string) $product->getOriginal('name'));
            $currentName = trim((string) $product->name);

            $product->variations()
                ->get()
                ->each(function ($variation) use ($originalName, $currentName, $product): void {
                    $description = trim((string) $variation->description);

                    if (! $product->has_variations) {
                        if ($description === $currentName) {
                            return;
                        }

                        $variation->update([
                            'description' => $currentName,
                        ]);

                        return;
                    }

                    if ($originalName === '') {
                        return;
                    }

                    if ($description === $originalName) {
                        $variation->update([
                            'description' => $currentName,
                        ]);

                        return;
                    }

                    $prefix = $originalName.' - ';

                    if (! str_starts_with($description, $prefix)) {
                        return;
                    }

                    $variation->update([
                        'description' => $currentName.substr($description, strlen($originalName)),
                    ]);
                });
        }

        $this->invalidateProductSearchCache($product->store_id);
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        $this->invalidateProductSearchCache($product->store_id);
    }

    /**
     * Handle the Product "restored" event.
     */
    public function restored(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "force deleted" event.
     */
    public function forceDeleted(Product $product): void
    {
        $this->invalidateProductSearchCache($product->store_id);
    }

    /**
     * Invalidate product search cache for a specific store.
     */
    protected function invalidateProductSearchCache(int $storeId): void
    {
        $cacheVersionKey = "product_search_version_{$storeId}";
        // Initialize key if it doesn't exist (atomic operation with Redis)
        Cache::add($cacheVersionKey, 0, now()->addYears(10));
        // Increment version to invalidate cache
        Cache::increment($cacheVersionKey);
    }
}
