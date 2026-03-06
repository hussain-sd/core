<?php

namespace SmartTill\Core\Filament\Resources\Products\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use SmartTill\Core\Filament\Imports\ProductImporter;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Filament\Resources\Helpers\SyncReferenceColumn;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('brand.name')
            ->groups([
                'brand.name',
                'category.name',
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query
                    ->withMin('variations', 'sale_price')
                    ->withMax('variations', 'sale_price')
                    ->withMin('variations', 'price')
                    ->withMax('variations', 'price')
                    ->withCount('variations');
            })
            ->columns([
                SyncReferenceColumn::make(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->where(function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhereHas('variations', function (Builder $q2) use ($search) {
                                    $q2->where('sku', 'like', "%{$search}%")
                                        ->orWhere('description', 'like', "%{$search}%");
                                });
                        });
                    }),

                TextColumn::make('description')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Price range from variations (sale_price min-max, fallback to price if sale_price is null/0/empty).
                // Values stored as integer with multiplier based on currency decimal_places (100, 1000, etc.)
                TextColumn::make('price_range')
                    ->label('Sale Price')
                    ->getStateUsing(function ($record) {
                        $store = Filament::getTenant();
                        $currency = $store->currency ?? null;
                        $decimalPlaces = $currency->decimal_places ?? 2;
                        $multiplier = (int) pow(10, $decimalPlaces);

                        // Return min value for sorting purposes - use sale_price if available, otherwise price
                        $minSalePrice = $record->variations_min_sale_price;
                        $minPrice = $record->variations_min_price;

                        // If sale_price is null/0/empty, use regular price
                        if (! $minSalePrice || $minSalePrice == 0) {
                            return $minPrice / $multiplier;
                        }

                        return $minSalePrice / $multiplier;
                    })
                    ->formatStateUsing(function ($state, $record) {
                        $store = Filament::getTenant();
                        $currency = $store->currency ?? null;
                        $decimalPlaces = $currency->decimal_places ?? 2;
                        $multiplier = (int) pow(10, $decimalPlaces);

                        $minSalePrice = $record->variations_min_sale_price;
                        $maxSalePrice = $record->variations_max_sale_price;
                        $minPrice = $record->variations_min_price;
                        $maxPrice = $record->variations_max_price;

                        // Determine which price to use (sale_price if available, otherwise regular price)
                        $min = ($minSalePrice && $minSalePrice > 0) ? $minSalePrice : $minPrice;
                        $max = ($maxSalePrice && $maxSalePrice > 0) ? $maxSalePrice : $maxPrice;

                        // Convert from stored integer format to actual currency amount
                        $minAmount = $min / $multiplier;
                        $maxAmount = $max / $multiplier;

                        // Format using Filament's money formatter
                        $currencyCode = $currency->code ?? 'PKR';
                        $formatMoney = fn ($value) => \Illuminate\Support\Number::currency($value, $currencyCode);

                        if (! $record->has_variations) {
                            return $formatMoney($minAmount);
                        }

                        // If min and max are the same, show single price
                        if ($min === $max) {
                            return $formatMoney($minAmount);
                        }

                        return $formatMoney($minAmount).' - '.$formatMoney($maxAmount);
                    })
                    ->sortable(query: function (Builder $query, string $direction) {
                        // Sort by min sale price when sorting the range column
                        // The display logic will handle fallback to price in formatStateUsing
                        return $query->orderBy('variations_min_sale_price', $direction);
                    })
                    ->toggleable(),

                // Variations count
                TextColumn::make('variations_count')
                    ->label('Variations')
                    ->state(function ($record) {
                        return $record->variations_count ?? 0;
                    })
                    ->sortable(query: function (Builder $query, string $direction) {
                        return $query->orderBy('variations_count', $direction);
                    })
                    ->toggleable(),

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
            ->filters([
                SelectFilter::make('brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->multiple()
                    ->preload(),

                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->multiple()
                    ->preload(),

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
                        ->color('danger'),
                    RestoreAction::make()
                        ->label('Restore')
                        ->color('success'),
                    ForceDeleteAction::make()
                        ->label('Force delete')
                        ->color('warning'),
                ]),
            ])
            ->headerActions([
                ImportAction::make()
                    ->importer(ProductImporter::class)
                    ->fileRules(['required', 'file', 'mimes:csv', 'max:10240']) // Allow CSV uploads up to 10 MB (in kilobytes)
                    ->options([
                        'store_id' => Filament::getTenant()?->getKey(),
                    ])
                    ->visible(fn () => ResourceCanAccessHelper::check('Import Products'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Import Products')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
