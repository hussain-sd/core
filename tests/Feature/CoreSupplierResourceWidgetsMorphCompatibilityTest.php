<?php

it('uses compatible supplier morph types in supplier resource widgets', function (): void {
    $paymentStats = file_get_contents(__DIR__.'/../../src/Filament/Resources/Suppliers/Widgets/SupplierPaymentStats.php');
    $statsOverview = file_get_contents(__DIR__.'/../../src/Filament/Resources/Suppliers/Widgets/SupplierStatsOverview.php');

    expect($paymentStats)
        ->toContain('private const SUPPLIER_MORPH_TYPES = [')
        ->toContain("'App\\\\Models\\\\Supplier'")
        ->toContain("'supplier'")
        ->toContain('->whereIn(\'transactionable_type\', self::SUPPLIER_MORPH_TYPES)');

    expect($statsOverview)
        ->toContain('private const SUPPLIER_MORPH_TYPES = [')
        ->toContain("'App\\\\Models\\\\Supplier'")
        ->toContain("'supplier'")
        ->toContain('->whereIn(\'transactionable_type\', self::SUPPLIER_MORPH_TYPES)');
});

