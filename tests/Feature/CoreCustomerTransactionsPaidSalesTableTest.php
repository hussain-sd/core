<?php

it('includes paid sale references in the customer transactions relation manager query', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("public const PAID_SALE_REFERENCE_TYPE = 'paid_sale_reference';")
        ->toContain("->modifyQueryUsing(fn (Builder \$query): Builder => \$this->includePaidSalesInTableQuery(\$query)->with('referenceable'))")
        ->toContain("self::PAID_SALE_REFERENCE_TYPE => 'Paid Sale'")
        ->toContain("\$columns = \$this->transactionTableColumns();")
        ->toContain("return Transaction::query()")
        ->toContain("->select(\$this->qualifyTransactionColumns(\$columns))")
        ->toContain("->unionAll(")
        ->toContain("(1000000000 + sales.id) as id")
        ->toContain("COALESCE(sales.note, 'Paid sale (informational only)') as note")
        ->toContain("->selectRaw('sales.updated_at as updated_at')")
        ->toContain('return Schema::getColumnListing(\'transactions\');')
        ->toContain('default => $query->selectRaw("null as {$column}"),');
});

it('renders the customer transactions table with ledger-style reference values and without local ids', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("TextColumn::make('referenceable')")
        ->toContain("->description(")
        ->toContain("? 'Sale'")
        ->toContain("->prefix('#')")
        ->toContain("->formatStateUsing(fn (Transaction \$record) => \$record->referenceable?->reference ?? \$record->referenceable_id)")
        ->toContain("SaleResource::getUrl('view', ['record' => \$record->referenceable])")
        ->toContain("TextColumn::make('amount_balance')")
        ->toContain("->state(fn (Transaction \$record) => \$record->type === self::PAID_SALE_REFERENCE_TYPE ? null : \$record->amount_balance)")
        ->toContain("TextColumn::make('amount')")
        ->toContain("->getStateUsing(fn (Transaction \$record): float => abs((float) \$record->amount))")
        ->toContain("default => null,")
        ->toContain("\$referenceValue = \$sale->reference ?: \$sale->id;")
        ->not->toContain("\$referenceable?->local_id")
        ->not->toContain("TextColumn::make('type')");
});

it('renders paid sale references as informational rows without a balance amount', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Transactions/Tables/TransactionsTable.php');

    expect($contents)
        ->toContain('CustomerTransactionsRelationManager::PAID_SALE_REFERENCE_TYPE')
        ->toContain('Heroicon::OutlinedInformationCircle')
        ->toContain("->placeholder('—')")
        ->toContain("->state(fn (\$record) => \$record->type === CustomerTransactionsRelationManager::PAID_SALE_REFERENCE_TYPE ? null : \$record->amount_balance)");
});
