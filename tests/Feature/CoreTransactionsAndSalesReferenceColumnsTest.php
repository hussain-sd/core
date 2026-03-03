<?php

it('hides sync reference column in transaction relation managers', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Transactions/Tables/TransactionsTable.php');

    expect($contents)
        ->toContain('SyncReferenceColumn::make()')
        ->toContain('->hiddenOn([')
        ->toContain('TransactionsRelationManager::class')
        ->toContain('CustomerTransactionsRelationManager::class')
        ->toContain('SupplierTransactionsRelationManager::class');
});

it('uses a single sync reference column in sales table', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Tables/SalesTable.php');

    expect($contents)
        ->toContain('SyncReferenceColumn::make()')
        ->not->toContain("TextColumn::make('reference')")
        ->not->toContain("->label('Sale #')");
});
