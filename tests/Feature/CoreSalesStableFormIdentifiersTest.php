<?php

use SmartTill\Core\Filament\Resources\Sales\Schemas\SaleForm;

it('builds stable identifiers for existing preparable sale rows', function (): void {
    expect(SaleForm::makePreparableVariationInstanceId(55, 91, 2))
        ->toBe('prep-sale-55-91-2');

    expect(SaleForm::makePreparableItemId(55, 7001, 91, 12, 2))
        ->toBe('prep-item-7001');

    expect(SaleForm::makePreparableItemId(55, null, 91, 12, 2))
        ->toBe('prep-item-sale-55-91-12-2');
});

it('builds stable identifiers for draft preparable rows', function (): void {
    expect(SaleForm::makeDraftPreparableVariationInstanceId([
        ['instance_id' => 'draft-prep-1'],
        ['instance_id' => 'draft-prep-4'],
    ]))->toBe('draft-prep-5');

    expect(SaleForm::makeDraftPreparableItemId(91, 12))
        ->toBe('draft-item-91-12-1');

    expect(SaleForm::makeDraftPreparableItemId(91, 12, [
        ['item_id' => 'draft-item-91-12-1'],
        ['item_id' => 'draft-item-91-12-4'],
    ]))->toBe('draft-item-91-12-5');
});

it('does not use random time based identifiers in sales form state', function (): void {
    $editSaleContents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Pages/EditSale.php');
    $saleFormContents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Schemas/SaleForm.php');

    expect($editSaleContents)
        ->not->toContain('microtime(')
        ->not->toContain('uniqid(')
        ->toContain('SaleForm::makePreparableVariationInstanceId')
        ->toContain('SaleForm::makePreparableItemId')
        ->toContain('makePreparableVariationInstanceId($sale->id, $variationId, $sequence)');

    expect($saleFormContents)
        ->not->toContain('microtime(')
        ->not->toContain('uniqid(')
        ->toContain('makeDraftPreparableVariationInstanceId')
        ->toContain('makeDraftPreparableItemId')
        ->toContain('nextDraftItemSequence');
});
