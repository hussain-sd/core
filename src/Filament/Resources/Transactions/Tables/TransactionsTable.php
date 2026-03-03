<?php

namespace SmartTill\Core\Filament\Resources\Transactions\Tables;

use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use SmartTill\Core\Filament\Resources\Customers\RelationManagers\TransactionsRelationManager as CustomerTransactionsRelationManager;
use SmartTill\Core\Filament\Resources\Helpers\SyncReferenceColumn;
use SmartTill\Core\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use SmartTill\Core\Filament\Resources\Sales\SaleResource;
use SmartTill\Core\Filament\Resources\Suppliers\RelationManagers\TransactionsRelationManager as SupplierTransactionsRelationManager;
use SmartTill\Core\Filament\Resources\Variations\RelationManagers\TransactionsRelationManager;
use SmartTill\Core\Models\PurchaseOrder;
use SmartTill\Core\Models\Sale;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SyncReferenceColumn::make()
                    ->hiddenOn([
                        TransactionsRelationManager::class,
                        CustomerTransactionsRelationManager::class,
                        SupplierTransactionsRelationManager::class,
                    ]),
                TextColumn::make('referenceable')
                    ->label('Reference')
                    ->description(fn ($record) => class_basename($record->referenceable_type), 'above')
                    ->color('primary')
                    ->prefix('#')
                    ->formatStateUsing(fn ($record) => $record->referenceable?->reference ?? $record->referenceable?->id)
                    ->url(function ($record) {
                        if ($record->referenceable_type === PurchaseOrder::class && $record->referenceable) {
                            return PurchaseOrderResource::getUrl('view', ['record' => $record->referenceable]);
                        } elseif ($record->referenceable_type === Sale::class && $record->referenceable) {
                            return SaleResource::getUrl('view', ['record' => $record->referenceable]);
                        }

                        return null;
                    }),
                TextColumn::make('note'),
                TextColumn::make('meta.barcode')
                    ->label('Barcode')
                    ->placeholder('-')
                    ->hiddenOn([
                        CustomerTransactionsRelationManager::class,
                        SupplierTransactionsRelationManager::class,
                    ]),
                TextColumn::make('meta.batch_number')
                    ->label('Batch #')
                    ->placeholder('-')
                    ->hiddenOn([
                        CustomerTransactionsRelationManager::class,
                        SupplierTransactionsRelationManager::class,
                    ]),
                TextColumn::make('created_at')
                    ->label('Created at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->created_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->getStateUsing(fn ($record) => abs($record->amount))
                    ->money(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                    ->icon(fn ($record) => $record->amount > 0 ? Heroicon::OutlinedArrowUp : Heroicon::OutlinedArrowDown)
                    ->iconColor(fn ($record) => ($record->type === 'customer_credit' || $record->type === 'supplier_debit') ? 'success' : 'danger')
                    ->color(fn ($record) => ($record->type === 'customer_credit' || $record->type === 'supplier_debit') ? 'success' : 'danger')
                    ->hiddenOn(TransactionsRelationManager::class),
                TextColumn::make('amount_balance')
                    ->label('Balance')
                    ->money(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                    ->hiddenOn(TransactionsRelationManager::class),
                TextColumn::make('quantity')
                    ->label('Quantity')
                    ->icon(fn ($record) => in_array($record->type, ['product_stock_in', 'variation_stock_in'], true) ? Heroicon::OutlinedArrowDown : Heroicon::OutlinedArrowUp)
                    ->iconColor(fn ($record) => in_array($record->type, ['product_stock_in', 'variation_stock_in'], true) ? 'success' : 'danger')->color(fn ($record) => in_array($record->type, ['product_stock_in', 'variation_stock_in'], true) ? 'success' : 'danger')
                    ->formatStateUsing(function ($state, $record): string {
                        $symbol = $record->transactionable?->unit?->symbol;
                        $value = number_format((float) ($state ?? 0), 6, '.', ',');
                        $trimmed = rtrim(rtrim($value, '0'), '.');

                        return $symbol ? "{$trimmed} {$symbol}" : $trimmed;
                    })
                    ->alignEnd()
                    ->visibleOn(TransactionsRelationManager::class),
                TextColumn::make('quantity_balance')
                    ->label('Balance')
                    ->formatStateUsing(function ($state, $record): string {
                        $symbol = $record->transactionable?->unit?->symbol;
                        $value = number_format((float) ($state ?? 0), 6, '.', ',');
                        $trimmed = rtrim(rtrim($value, '0'), '.');

                        return $symbol ? "{$trimmed} {$symbol}" : $trimmed;
                    })
                    ->alignEnd()
                    ->visibleOn(TransactionsRelationManager::class),
                TextColumn::make('updated_at')
                    ->label('Updated at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->updated_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc');
    }
}
