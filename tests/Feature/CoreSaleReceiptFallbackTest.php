<?php

it('uses reference fallback in sale form redirect for public receipt route', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Schemas/SaleForm.php');

    expect($contents)
        ->toContain('$receiptReference = (string) ($sale->reference ?: ($sale->local_id ?: $sale->id));')
        ->toContain("'reference' => $receiptReference");
});

it('resolves public receipt by reference then local_id then numeric id', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/PublicReceiptController.php');

    expect($contents)
        ->toContain("->where('reference', \$reference)")
        ->toContain("->where('local_id', \$reference)")
        ->toContain('ctype_digit($reference)')
        ->toContain('whereKey((int) $reference)');
});
