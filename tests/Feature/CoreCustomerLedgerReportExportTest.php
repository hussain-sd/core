<?php

it('exports customer ledger reports with metadata rows before ledger rows', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("Action::make('exportLedger')")
        ->toContain("Select::make('format')")
        ->toContain("['Store Name', \$store?->business_name ?: \$store?->name ?: '—']")
        ->toContain("['Customer Name', \$customer->name ?: '—']")
        ->toContain("\$ledgerHeaderRow = ['Date', 'Reference', 'Note', 'Type', 'Amount', 'Balance'];")
        ->toContain("\$csv->insertOne(\$ledgerHeaderRow);")
        ->toContain("\$writer->addRow(Row::fromValues(\$ledgerHeaderRow));");
});

