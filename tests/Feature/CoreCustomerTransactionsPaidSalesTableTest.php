<?php

it('includes paid sale references in the customer transactions relation manager query', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("public const PAID_SALE_REFERENCE_TYPE = 'paid_sale_reference';")
        ->toContain("->modifyQueryUsing(fn (Builder \$query): Builder => \$this->includePaidSalesInTableQuery(\$query))")
        ->toContain("self::PAID_SALE_REFERENCE_TYPE => 'Paid Sale'")
        ->toContain("return Transaction::query()")
        ->toContain("->unionAll(")
        ->toContain("(1000000000 + sales.id) as id")
        ->toContain("COALESCE(sales.note, 'Paid sale (informational only)') as note");
});

it('renders paid sale references as informational rows without a balance amount', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Transactions/Tables/TransactionsTable.php');

    expect($contents)
        ->toContain('CustomerTransactionsRelationManager::PAID_SALE_REFERENCE_TYPE')
        ->toContain('Heroicon::OutlinedInformationCircle')
        ->toContain("->placeholder('—')")
        ->toContain("->state(fn (\$record) => \$record->type === CustomerTransactionsRelationManager::PAID_SALE_REFERENCE_TYPE ? null : \$record->amount_balance)");
});
