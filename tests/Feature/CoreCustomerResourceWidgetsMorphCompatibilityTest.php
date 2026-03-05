<?php

it('uses compatible customer morph types in customer resource widgets', function (): void {
    $paymentStats = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/Widgets/CustomerPaymentStats.php');
    $statsOverview = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/Widgets/CustomerStatsOverview.php');

    expect($paymentStats)
        ->toContain('private const CUSTOMER_MORPH_TYPES = [')
        ->toContain("'App\\\\Models\\\\Customer'")
        ->toContain("'customer'")
        ->toContain('->whereIn(\'transactionable_type\', self::CUSTOMER_MORPH_TYPES)');

    expect($statsOverview)
        ->toContain('private const CUSTOMER_MORPH_TYPES = [')
        ->toContain("'App\\\\Models\\\\Customer'")
        ->toContain("'customer'")
        ->toContain('->whereIn(\'transactionable_type\', self::CUSTOMER_MORPH_TYPES)');
});
