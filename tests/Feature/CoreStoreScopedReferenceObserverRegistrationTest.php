<?php

it('registers store scoped reference observer for core store resources', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Providers/CoreServiceProvider.php');

    expect($contents)
        ->toContain('use SmartTill\Core\Observers\StoreScopedReferenceObserver;')
        ->toContain('Attribute::observe(StoreScopedReferenceObserver::class);')
        ->toContain('Brand::observe(StoreScopedReferenceObserver::class);')
        ->toContain('Category::observe(StoreScopedReferenceObserver::class);')
        ->toContain('Customer::observe(StoreScopedReferenceObserver::class);')
        ->toContain('Product::observe(StoreScopedReferenceObserver::class);')
        ->toContain('PurchaseOrder::observe(StoreScopedReferenceObserver::class);')
        ->toContain('Supplier::observe(StoreScopedReferenceObserver::class);')
        ->toContain('Unit::observe(StoreScopedReferenceObserver::class);')
        ->toContain('Variation::observe(StoreScopedReferenceObserver::class);');
});

