<?php

it('guards payment print action behind route existence checks', function (): void {
    $tableContents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Payments/Tables/PaymentsTable.php');

    expect($tableContents)
        ->toContain("Route::has('print.payment')")
        ->toContain("Route::has('print.payment') ? route('print.payment'");
});

it('registers package payment print route in core routes', function (): void {
    $routesContents = file_get_contents(__DIR__.'/../../routes/web.php');

    expect($routesContents)
        ->toContain("name('print.payment')")
        ->toContain('/payments/{payment}/receipt');
});

it('ships payment print blade in core package', function (): void {
    $viewContents = file_get_contents(__DIR__.'/../../resources/views/print/payment.blade.php');

    expect($viewContents)
        ->toContain('Payment Receipt')
        ->toContain('prepareAndPrint')
        ->toContain('window.addEventListener(\'afterprint\'');
});

it('links payable column to customer and supplier views with alias compatibility', function (): void {
    $tableContents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Payments/Tables/PaymentsTable.php');

    expect($tableContents)
        ->toContain('CUSTOMER_PAYABLE_TYPES')
        ->toContain('SUPPLIER_PAYABLE_TYPES')
        ->toContain("'App\\\\Models\\\\Customer'")
        ->toContain("'App\\\\Models\\\\Supplier'")
        ->toContain("CustomerResource::getUrl('view'")
        ->toContain("SupplierResource::getUrl('view'")
        ->toContain('in_array($record->payable_type, self::CUSTOMER_PAYABLE_TYPES, true)')
        ->toContain('in_array($record->payable_type, self::SUPPLIER_PAYABLE_TYPES, true)');
});
