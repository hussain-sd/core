<?php

it('exports customer ledger reports with metadata rows before ledger rows', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("Action::make('exportLedger')")
        ->toContain("Select::make('format')")
        ->toContain("['Store Name', \$store?->business_name ?: \$store?->name ?: '—']")
        ->toContain("['Customer Name', \$customer->name ?: '—']")
        ->toContain("\$ledgerHeaderRow = ['Date', 'Reference', 'Note', 'Type', 'Amount', 'Balance'];")
        ->toContain('fputcsv($handle, $ledgerHeaderRow);')
        ->toContain('$writer->addRow(Row::fromValues($ledgerHeaderRow));');
});

it('streams ledger rows instead of materializing the entire ledger in memory', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain('->cursor()')
        ->toContain('foreach ($this->ledgerRows($timezone, $decimalPlaces) as $row)')
        ->not->toContain("->get();")
        ->not->toContain('->map(function (Transaction $record)');
});
