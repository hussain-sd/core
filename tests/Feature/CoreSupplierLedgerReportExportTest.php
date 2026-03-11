<?php

it('exports supplier ledger reports with metadata rows before ledger rows', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Suppliers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("Action::make('exportLedger')")
        ->toContain("Select::make('format')")
        ->toContain("['Store Name', \$store?->business_name ?: \$store?->name ?: '—']")
        ->toContain("['Supplier Name', \$supplier->name ?: '—']")
        ->toContain("\$ledgerHeaderRow = ['Date', 'Reference', 'Note', 'Type', 'Amount', 'Balance'];")
        ->toContain('fputcsv($handle, $this->csvMetadataRow($row));')
        ->toContain('$writer->addRow(Row::fromValues($ledgerHeaderRow));');
});

it('streams supplier ledger rows instead of materializing the entire ledger in memory', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Suppliers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain('->cursor()')
        ->toContain("->reorder('created_at')->orderBy('id')->cursor()")
        ->toContain('foreach ($this->ledgerRows($timezone, $decimalPlaces) as $row)')
        ->not->toContain('->get();')
        ->not->toContain('->map(function (Transaction $record)');
});

it('forces supplier phone metadata to remain textual in csv and xlsx exports', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Suppliers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("if (str_contains(\$label, 'Phone') && \$value !== '—')")
        ->toContain("\$value = '=\"'.\$value.'\"';")
        ->toContain('new StringCell((string) ($row[1] ?? \'\'), null)');
});
