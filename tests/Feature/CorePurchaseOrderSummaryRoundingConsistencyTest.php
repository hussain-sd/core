<?php

it('uses stored requested supplier price before recomputing preview totals in purchase order form', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderForm.php');

    expect($contents)
        ->toContain("\$supplierPrice = \$item['requested_supplier_price'] ?? null;")
        ->toContain("if (is_numeric(\$supplierPrice)) {")
        ->toContain("} elseif (\$inputIsPercent === true && is_numeric(\$supplierPercent) && \$unit > 0) {");
});

it('uses stored received supplier price before recomputing receive summary totals', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/PurchaseOrders/Schemas/ReceiveForm.php');

    expect($contents)
        ->toContain("\$supplierPriceValue = \$item['received_supplier_price']")
        ->toContain("if (is_numeric(\$supplierPriceValue)) {")
        ->toContain("} elseif (\$inputIsPercent === true && is_numeric(\$supplierPercentage) && \$unitPrice > 0) {");
});

it('uses stored received supplier price for close purchase order mount summary', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/PurchaseOrders/Pages/ClosePurchaseOrder.php');

    expect($contents)
        ->toContain("\$supplierPriceValue = \$item['received_supplier_price'] ?? null;")
        ->toContain("if (is_numeric(\$supplierPriceValue)) {")
        ->toContain("} elseif (\$inputIsPercent === true && is_numeric(\$supplierPercentage) && \$unitPrice > 0) {");
});
