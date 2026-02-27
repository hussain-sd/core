<?php

namespace SmartTill\Core\Filament\Resources\PurchaseOrders\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Route;
use SmartTill\Core\Enums\PurchaseOrderStatus;
use SmartTill\Core\Filament\Resources\Suppliers\RelationManagers\PurchaseOrdersRelationManager;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->prefix('#')
                    ->searchable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->sortable()
                    ->searchable()
                    ->hiddenOn(PurchaseOrdersRelationManager::class),
                TextColumn::make('total_requested_supplier_price')
                    ->label('Req. Supplier Price')
                    ->money(
                        fn () => Filament::getTenant()?->currency?->code ?? 'PKR',
                        decimalPlaces: fn () => Filament::getTenant()?->currency?->decimal_places ?? 2
                    )
                    ->sortable(),
                TextColumn::make('total_received_supplier_price')
                    ->label('Rec. Supplier Price')
                    ->money(
                        fn () => Filament::getTenant()?->currency?->code ?? 'PKR',
                        decimalPlaces: fn () => Filament::getTenant()?->currency?->decimal_places ?? 2
                    )
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->label('Deleted at')
                    ->dateTime()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->created_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->updated_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('View')
                        ->color('primary'),
                    EditAction::make()
                        ->label('Edit')
                        ->color('warning')
                        ->visible(fn ($record) => $record->status !== PurchaseOrderStatus::Closed),
                    Action::make('print')
                        ->label('Print')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->color('gray')
                        ->visible(fn () => Route::has('print.purchase-order') && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Print Purchase Orders'))
                        ->authorize(fn () => Route::has('print.purchase-order') && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Print Purchase Orders'))
                        ->url(fn ($record) => Route::has('print.purchase-order') ? route('print.purchase-order', [
                            'purchaseOrder' => $record->id,
                        ]) : null)
                        ->openUrlInNewTab(),
                    Action::make('close')
                        ->label('Mark as Closed')
                        ->icon(Heroicon::Check)
                        ->color('success')
                        ->visible(fn ($record) => $record->status !== PurchaseOrderStatus::Closed && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Close Purchase Orders'))
                        ->authorize(fn () => \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Close Purchase Orders'))
                        ->url(fn ($record) => route('filament.store.resources.purchase-orders.close', [
                            'tenant' => filament()->getTenant(),
                            'record' => $record,
                        ])),
                    DeleteAction::make()
                        ->label('Delete')
                        ->color('danger')
                        ->visible(fn ($record) => ! $record->trashed() && $record->status !== PurchaseOrderStatus::Closed),
                    RestoreAction::make()
                        ->label('Restore')
                        ->color('success')
                        ->visible(fn ($record) => $record->trashed()),
                    ForceDeleteAction::make()
                        ->label('Force delete')
                        ->color('warning')
                        ->visible(fn ($record) => $record->trashed()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => ! $record->trashed() && $record->status !== PurchaseOrderStatus::Closed)),
                    RestoreBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => $record->trashed())),
                    ForceDeleteBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => $record->trashed())),
                ]),
            ]);
    }
}
