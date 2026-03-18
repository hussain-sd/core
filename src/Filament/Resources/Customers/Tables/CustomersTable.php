<?php

namespace SmartTill\Core\Filament\Resources\Customers\Tables;

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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use SmartTill\Core\Filament\Resources\Helpers\SyncReferenceColumn;
use SmartTill\Core\Models\Customer;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withPendingBalance())
            ->columns([
                SyncReferenceColumn::make(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('pending_balance_raw')
                    ->label('Balance')
                    ->state(function (Customer $record): string {
                        $store = Filament::getTenant();
                        $decimalPlaces = $store?->currency?->decimal_places ?? 2;
                        $multiplier = (int) pow(10, $decimalPlaces);
                        $pendingBalanceRaw = max(0, (float) ($record->pending_balance_raw ?? 0));
                        $pendingBalance = $multiplier > 0 ? ($pendingBalanceRaw / $multiplier) : $pendingBalanceRaw;
                        $currencyCode = $store?->currency?->code ?? 'PKR';

                        return $currencyCode.' '.number_format($pendingBalance, $decimalPlaces, '.', ',');
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
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
                        ->color('warning'),
                    DeleteAction::make()
                        ->label('Delete')
                        ->color('danger')
                        ->visible(fn ($record) => ! $record->trashed()),
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
                        ->visible(fn ($records) => $records->every(fn ($record) => ! $record->trashed())),
                    RestoreBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => $record->trashed())),
                    ForceDeleteBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => $record->trashed())),
                ]),
            ]);
    }
}
