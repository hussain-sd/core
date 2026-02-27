<?php

it('guards purchase order print actions behind route existence checks', function (): void {
    $tableContents = file_get_contents(__DIR__.'/../../src/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php');
    $viewContents = file_get_contents(__DIR__.'/../../src/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php');

    expect($tableContents)
        ->toContain("Route::has('print.purchase-order')")
        ->toContain("Route::has('print.purchase-order') ? route('print.purchase-order'");

    expect($viewContents)
        ->toContain("Route::has('print.purchase-order')")
        ->toContain("Route::has('print.purchase-order') ? route('print.purchase-order'");
});

it('registers package purchase order print route in core routes', function (): void {
    $routesContents = file_get_contents(__DIR__.'/../../routes/web.php');

    expect($routesContents)
        ->toContain("name('print.purchase-order')")
        ->toContain('/purchase-orders/{purchaseOrder}/receipt');
});
