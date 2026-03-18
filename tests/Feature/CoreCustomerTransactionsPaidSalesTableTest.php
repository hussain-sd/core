<?php

it('includes paid sale references in the customer transactions relation manager query', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("public const PAID_SALE_REFERENCE_TYPE = 'paid_sale_reference';")
        ->toContain("->modifyQueryUsing(fn (Builder \$query): Builder => \$this->includePaidSalesInTableQuery(\$query)->with('referenceable'))")
        ->toContain("self::PAID_SALE_REFERENCE_TYPE => 'Paid Sale'")
        ->toContain("return Transaction::query()")
        ->toContain("->unionAll(")
        ->toContain("(1000000000 + sales.id) as id")
        ->toContain("null as local_id")
        ->toContain("null as reference")
        ->toContain("COALESCE(sales.note, 'Paid sale (informational only)') as note")
        ->toContain("->selectRaw('sales.updated_at as updated_at')")
        ->toContain("->selectRaw('null as local_id')")
        ->toContain("->selectRaw('null as reference');");
});

it('renders the customer transactions table with ledger-style reference values and without local ids', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("TextColumn::make('referenceable')")
        ->toContain("->state(fn (Transaction \$record): string => \$this->resolveReferenceSummaryForTable(\$record))")
        ->toContain("TextColumn::make('type')")
        ->toContain("->formatStateUsing(fn (string \$state): string => \$state === self::PAID_SALE_REFERENCE_TYPE ? 'Paid Sale' : Str::headline(\$state))")
        ->toContain("TextColumn::make('amount_balance')")
        ->toContain("->state(fn (Transaction \$record) => \$record->type === self::PAID_SALE_REFERENCE_TYPE ? null : \$record->amount_balance)")
        ->toContain("\$referenceValue = \$sale->reference ?: \$sale->id;")
        ->not->toContain("\$referenceable?->local_id");
});

it('renders paid sale references as informational rows without a balance amount', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Transactions/Tables/TransactionsTable.php');

    expect($contents)
        ->toContain('CustomerTransactionsRelationManager::PAID_SALE_REFERENCE_TYPE')
        ->toContain('Heroicon::OutlinedInformationCircle')
        ->toContain("->placeholder('—')")
        ->toContain("->state(fn (\$record) => \$record->type === CustomerTransactionsRelationManager::PAID_SALE_REFERENCE_TYPE ? null : \$record->amount_balance)");
});
