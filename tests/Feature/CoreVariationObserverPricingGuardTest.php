<?php

use SmartTill\Core\Models\Variation;
use SmartTill\Core\Observers\VariationObserver;

it('clamps sale price above regular price before deriving percentage', function () {
    $variation = new class extends Variation
    {
        protected function casts(): array
        {
            return [];
        }
    };

    $variation->forceFill([
        'price' => 975,
        'sale_price' => 975,
    ]);
    $variation->setRelation('product', null);
    $variation->syncOriginal();

    $variation->sale_price = 959750;

    $observer = new VariationObserver();
    $observer->updating($variation);

    expect((float) $variation->sale_price)->toBe(975.0)
        ->and((float) $variation->sale_percentage)->toBe(0.0);
});

it('clamps sale percentage to a valid discount range before deriving sale price', function () {
    $variation = new class extends Variation
    {
        protected function casts(): array
        {
            return [];
        }
    };

    $variation->forceFill([
        'price' => 975,
        'sale_percentage' => 0,
        'sale_price' => 975,
    ]);
    $variation->setRelation('product', null);
    $variation->syncOriginal();

    $variation->sale_percentage = 250;

    $observer = new VariationObserver();
    $observer->updating($variation);

    expect((float) $variation->sale_percentage)->toBe(100.0)
        ->and((float) $variation->sale_price)->toBe(0.0);
});
