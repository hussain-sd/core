<?php

namespace SmartTill\Core\Filament\Resources\Variations\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use SmartTill\Core\Filament\Exports\VariationExporter;
use SmartTill\Core\Filament\Exports\VariationPricingExporter;
use SmartTill\Core\Filament\Imports\VariationPricingImporter;
use SmartTill\Core\Filament\Imports\VariationStockImporter;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Filament\Resources\Helpers\SyncReferenceColumn;

class VariationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('product.name')
            ->groups([
                'product.name',
                'product.brand.name',
                'product.category.name',
            ])
            ->columns([
                SyncReferenceColumn::make(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('price')
                    ->money(fn () => Filament::getTenant()?->currency?->code ?? 'PKR')
                    ->sortable(),
                TextColumn::make('sale_price')
                    ->numeric()
                    ->money(fn () => Filament::getTenant()?->currency?->code ?? 'PKR')
                    ->sortable(),
                TextColumn::make('sale_percentage')
                    ->numeric(decimalPlaces: 6)
                    ->suffix('%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stock')
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state < 10 ? 'warning' : 'success'))
                    ->formatStateUsing(function ($state, $record): string {
                        $symbol = $record->unit?->symbol;
                        $raw = is_string($state) ? $state : (string) ($state ?? '0');
                        $raw = trim($raw) === '' ? '0' : $raw;
                        $negative = str_starts_with($raw, '-');
                        $raw = ltrim($raw, '+-');
                        [$intPart, $decPart] = array_pad(explode('.', $raw, 2), 2, '');
                        $intPart = $intPart === '' ? '0' : $intPart;
                        $intFormatted = number_format((int) $intPart, 0, '.', ',');
                        $decPart = rtrim($decPart, '0');
                        $trimmed = $decPart !== '' ? $intFormatted.'.'.$decPart : $intFormatted;
                        if ($negative) {
                            $trimmed = '-'.$trimmed;
                        }

                        return $symbol ? "{$trimmed} {$symbol}" : $trimmed;
                    }),
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
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('View')
                        ->color('primary'),
                    EditAction::make()
                        ->label('Edit')
                        ->color('warning'),
                ]),
            ])
            ->headerActions([
                ImportAction::make()
                    ->label('Import Stock')
                    ->importer(VariationStockImporter::class)
                    ->fileRules(['required', 'file', 'mimes:csv', 'max:10240'])
                    ->options([
                        'store_id' => Filament::getTenant()?->getKey(),
                    ])
                    ->visible(fn () => ResourceCanAccessHelper::check('Import Variation Stock'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Import Variation Stock')),
                ImportAction::make('importVariationPricing')
                    ->label('Import Pricing')
                    ->importer(VariationPricingImporter::class)
                    ->fileRules(['required', 'file', 'mimes:csv', 'max:10240'])
                    ->options([
                        'store_id' => Filament::getTenant()?->getKey(),
                    ])
                    ->visible(fn () => ResourceCanAccessHelper::check('Edit Variations'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Edit Variations')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(VariationExporter::class)
                        ->visible(fn () => ResourceCanAccessHelper::check('Export Variations'))
                        ->authorize(fn () => ResourceCanAccessHelper::check('Export Variations')),
                    ExportBulkAction::make('exportVariationPricing')
                        ->label('Export Pricing')
                        ->exporter(VariationPricingExporter::class)
                        ->visible(fn () => ResourceCanAccessHelper::check('Export Variations'))
                        ->authorize(fn () => ResourceCanAccessHelper::check('Export Variations')),
                ]),
            ]);
    }
}
