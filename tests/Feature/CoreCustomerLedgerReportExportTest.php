<?php

it('exports customer ledger reports with metadata rows before ledger rows', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("Action::make('exportLedger')")
        ->toContain("Select::make('format')")
        ->toContain("['Store Name', \$store?->business_name ?: \$store?->name ?: '—']")
        ->toContain("['Customer Name', \$customer->name ?: '—']")
        ->toContain("\$ledgerHeaderRow = ['Date', 'Reference', 'Note', 'Type', 'Amount', 'Balance'];")
        ->toContain('fputcsv($handle, $this->csvMetadataRow($row));')
        ->toContain('$writer->addRow(Row::fromValues($ledgerHeaderRow));');
});

it('streams ledger rows instead of materializing the entire ledger in memory', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain('->cursor()')
        ->toContain("->reorder('created_at')")
        ->toContain("->orderBy('id')")
        ->toContain("->where('payment_status', SalePaymentStatus::Paid)")
        ->toContain("->orderByRaw('COALESCE(paid_at, created_at) asc')")
        ->toContain('foreach ($this->ledgerRows($timezone, $decimalPlaces) as $row)')
        ->not->toContain('->get();')
        ->not->toContain('->map(function (Transaction $record)');
});

it('forces phone metadata to remain textual in csv and xlsx exports', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("if (str_contains(\$label, 'Phone') && \$value !== '—')")
        ->toContain("\$value = '=\"'.\$value.'\"';")
        ->toContain('new StringCell((string) ($row[1] ?? \'\'), null)');
});

it('includes paid sales in customer ledger exports without affecting balance rows', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Filament/Resources/Customers/RelationManagers/TransactionsRelationManager.php');

    expect($contents)
        ->toContain("'Paid Sale'")
        ->toContain("Number::format((float) \$sale->total, \$decimalPlaces)")
        ->toContain("'—'")
        ->toContain("Paid sale (informational only)")
        ->toContain('protected function resolveSaleReferenceSummary(Sale $sale): string');
});
