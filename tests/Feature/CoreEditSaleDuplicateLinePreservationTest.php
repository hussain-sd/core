<?php

it('loads edit sale lines from sale_variation rows instead of collapsing through belongsToMany relation', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Pages/EditSale.php');

    expect($contents)
        ->toContain("DB::table('sale_variation')")
        ->toContain("->whereNotNull('variation_id')")
        ->toContain('$regularVariationRows = [];')
        ->toContain('$this->collapseRegularVariationRows(collect($regularVariationRows), $multiplier)')
        ->toContain('protected function collapseRegularVariationRows(Collection $saleVariationRows, float $multiplier): array')
        ->toContain("->groupBy(function (object \$saleVariationRow): string")
        ->not->toContain('foreach ($sale->variations as $variation)');
});

it('does not key updated regular sale lines by variation id during edit saves', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Schemas/SaleForm.php');

    expect($contents)
        ->toContain("\$syncData[] = [")
        ->toContain("'variation_id' => \$variation['variation_id']")
        ->not->toContain("\$syncData[\$variation['variation_id']] = [");
});
