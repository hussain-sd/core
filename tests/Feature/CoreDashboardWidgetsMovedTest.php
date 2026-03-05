<?php

it('ships customer and supplier dashboard widgets in core package', function (): void {
    $customerWidget = file_get_contents(__DIR__.'/../../src/Filament/Widgets/CustomerStatsWidget.php');
    $supplierWidget = file_get_contents(__DIR__.'/../../src/Filament/Widgets/SupplierStatsWidget.php');

    expect($customerWidget)
        ->toContain('namespace SmartTill\\Core\\Filament\\Widgets;')
        ->toContain('class CustomerStatsWidget')
        ->toContain("'App\\\\Models\\\\Customer'");

    expect($supplierWidget)
        ->toContain('namespace SmartTill\\Core\\Filament\\Widgets;')
        ->toContain('class SupplierStatsWidget')
        ->toContain("'App\\\\Models\\\\Supplier'");
});
