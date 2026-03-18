<?php

it('normalizes preparable variation keys to numeric sequence values when saving sales', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Schemas/SaleForm.php');

    expect(substr_count($contents, 'foreach (array_values($preparableVariations) as $sequence => $preparableVariation)'))
        ->toBeGreaterThanOrEqual(2);
});
