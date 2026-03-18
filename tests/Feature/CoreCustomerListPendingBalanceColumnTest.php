<?php

it('adds latest pending balance to the customer query', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Models/Customer.php');

    expect($contents)
        ->toContain('public static function transactionMorphTypes(): array')
        ->toContain('public function scopeWithPendingBalance(Builder $query): Builder')
        ->toContain("'pending_balance_raw' => Transaction::query()")
        ->toContain("->whereColumn('transactionable_id', 'customers.id')")
        ->toContain("->whereIn('transactionable_type', self::transactionMorphTypes())");
});

it('shows balance after email in the customers table', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Customers/Tables/CustomersTable.php');

    $emailPosition = strpos($contents, "TextColumn::make('email')");
    $balancePosition = strpos($contents, "TextColumn::make('pending_balance_raw')");
    $statusPosition = strpos($contents, "TextColumn::make('status')");

    expect($contents)
        ->toContain('->modifyQueryUsing(fn (Builder $query): Builder => $query->withPendingBalance())')
        ->toContain("->label('Balance')")
        ->toContain("number_format(\$pendingBalance, \$decimalPlaces, '.', ',')");

    expect($emailPosition)->not->toBeFalse();
    expect($balancePosition)->not->toBeFalse();
    expect($statusPosition)->not->toBeFalse();
    expect($balancePosition)->toBeGreaterThan($emailPosition);
    expect($balancePosition)->toBeLessThan($statusPosition);
});
