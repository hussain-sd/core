<?php

namespace SmartTill\Core\Observers;

use Illuminate\Support\Facades\Cache;
use SmartTill\Core\Models\Variation;

class VariationObserver
{
    public function creating(Variation $variation): void
    {
        $this->populateStoreAndBrand($variation);
        $this->recalculate($variation);
    }

    public function created(Variation $variation): void
    {
        $this->invalidateProductSearchCache($variation->store_id);
        $this->invalidateVariationCache($variation);
    }

    public function updating(Variation $variation): void
    {
        $this->populateStoreAndBrand($variation);
        $this->recalculate($variation);
    }

    public function updated(Variation $variation): void
    {
        $this->invalidateProductSearchCache($variation->store_id);
        $this->invalidateVariationCache($variation);
    }

    public function deleted(Variation $variation): void
    {
        $storeId = $variation->store_id ?? $variation->product?->store_id;
        if ($storeId) {
            $this->invalidateProductSearchCache($storeId);
        }
        $this->invalidateVariationCache($variation);
    }

    protected function populateStoreAndBrand(Variation $variation): void
    {
        if ($variation->product_id && ! $variation->product) {
            $variation->load('product.brand');
        }

        if ($variation->product) {
            $variation->store_id = $variation->product->store_id;
            $variation->brand_name = $variation->product->brand?->name;
        }
    }

    protected function recalculate(Variation $variation): void
    {
        $price = $variation->price;
        if (! is_numeric($price) || $price <= 0) {
            return;
        }

        $salePriceDirty = $variation->isDirty('sale_price');
        $salePercentageDirty = $variation->isDirty('sale_percentage');

        if ($salePriceDirty && is_numeric($variation->sale_price)) {
            $salePrice = max(0, min((float) $variation->sale_price, (float) $price));
            $variation->sale_price = $salePrice;
            $variation->sale_percentage = round((($price - $salePrice) / $price) * 100, 6);
        } elseif ($salePercentageDirty && is_numeric($variation->sale_percentage)) {
            $percentage = max(0, min((float) $variation->sale_percentage, 100));
            $variation->sale_percentage = round($percentage, 6);
            $variation->sale_price = round(max(0, $price * (1 - $percentage / 100)), 6);
        }

        $salePriceIsNullOrZero = is_null($variation->sale_price)
            || $variation->sale_price === ''
            || (is_numeric($variation->sale_price) && (float) $variation->sale_price == 0.0);
        $salePercentageIsNullOrZero = is_null($variation->sale_percentage)
            || $variation->sale_percentage === ''
            || (is_numeric($variation->sale_percentage) && (float) $variation->sale_percentage == 0.0);

        if ($salePriceIsNullOrZero && $salePercentageIsNullOrZero) {
            $variation->sale_price = $price;
            $variation->sale_percentage = 0;
        }
    }

    protected function invalidateProductSearchCache(int $storeId): void
    {
        $cacheVersionKey = "product_search_version_{$storeId}";
        Cache::add($cacheVersionKey, 0, now()->addYears(10));
        Cache::increment($cacheVersionKey);
    }

    protected function invalidateVariationCache(Variation $variation): void
    {
        Cache::forget("variation_{$variation->id}");
    }
}
