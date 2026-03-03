<?php

namespace SmartTill\Core\Filament\Resources\Payments\Tables;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use SmartTill\Core\Enums\PaymentMethod;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Filament\Resources\Helpers\SyncReferenceColumn;
use SmartTill\Core\Filament\Resources\Payments\PaymentResource;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Supplier;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SyncReferenceColumn::make(),
                TextColumn::make('payable')
                    ->label('Payable')
                    ->getStateUsing(fn ($record) => $record->payable?->name ?? '—')
                    ->description(fn ($record) => $record->payable_type ? class_basename($record->payable_type) : null, 'above')
                    ->color(fn ($record) => $record->payable ? 'primary' : null)
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->whereHasMorph('payable', [Customer::class, Supplier::class], function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof PaymentMethod) {
                            return $state->getLabel();
                        }

                        return ucfirst((string) $state);
                    })
                    ->color(fn ($state) => $state instanceof PaymentMethod ? $state->getColor() : 'gray'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                    ->sortable(),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->prefix('#')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('note')
                    ->label('Note')
                    ->limit(50)
                    ->searchable()
                    ->placeholder('—'),
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
            ->filters([
                SelectFilter::make('payable_type')
                    ->label('Payable')
                    ->options([
                        Customer::class => 'Customer',
                        Supplier::class => 'Supplier',
                    ])
                    ->multiple()
                    ->preload(),
                SelectFilter::make('payment_method')
                    ->label('Payment Method')
                    ->options(PaymentMethod::class)
                    ->multiple()
                    ->preload(),
                Filter::make('created_at_range')
                    ->label('Date range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $data['from'])
                            )
                            ->when(
                                filled($data['until'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $data['until'])
                            );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('print')
                    ->label('Print')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->visible(fn () => ResourceCanAccessHelper::check('Print Payments') && Route::has('print.payment'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Print Payments'))
                    ->url(fn ($record) => Route::has('print.payment') ? route('print.payment', [
                        'payment' => $record->id,
                        'next' => PaymentResource::getUrl(),
                    ]) : null)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
