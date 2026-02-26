<?php

namespace SmartTill\Core\Filament\Resources\Variations\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\Unit;

class StocksRelationManager extends RelationManager
{
    protected static string $relationship = 'stocks';

    protected static ?string $title = 'Stocks';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ResourceCanAccessHelper::check('Manage Stock');
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->label('Add Stock')
                    ->visible(fn () => ResourceCanAccessHelper::check('Manage Stock'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Manage Stock'))
                    ->schema(fn (Schema $schema) => $schema->schema($this->stockFormSchema())->columns(2))
                    ->mutateFormDataUsing(fn (array $data) => $this->normalizeStockUnit($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => ResourceCanAccessHelper::check('Manage Stock'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Manage Stock'))
                    ->schema(fn (Schema $schema) => $schema->schema($this->stockFormSchema())->columns(2))
                    ->mutateFormDataUsing(fn (array $data) => $this->normalizeStockUnit($data)),
            ])
            ->columns([
                TextColumn::make('barcode')
                    ->label('Barcode')
                    ->searchable(),
                TextColumn::make('batch_number')
                    ->label('Batch #')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Price')
                    ->money(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                    ->sortable(),
                TextColumn::make('tax_summary')
                    ->label('Tax Amount')
                    ->visible(fn () => Filament::getTenant()?->tax_enabled ?? false)
                    ->getStateUsing(function ($record): string {
                        $currencyCode = Filament::getTenant()?->currency->code ?? 'PKR';
                        $percentage = rtrim(rtrim(number_format((float) ($record->tax_percentage ?? 0), 6, '.', ''), '0'), '.') ?: '0';
                        $amount = \Illuminate\Support\Number::currency((float) ($record->tax_amount ?? 0), $currencyCode);

                        return $percentage.'% / '.$amount;
                    }),
                TextColumn::make('supplier_summary')
                    ->label('Supplier Price')
                    ->getStateUsing(function ($record): string {
                        $currencyCode = Filament::getTenant()?->currency->code ?? 'PKR';
                        $percentage = rtrim(rtrim(number_format((float) ($record->supplier_percentage ?? 0), 6, '.', ''), '0'), '.') ?: '0';
                        $price = \Illuminate\Support\Number::currency((float) ($record->supplier_price ?? 0), $currencyCode);

                        return $percentage.'% / '.$price;
                    }),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->formatStateUsing(function ($state, $record): string {
                        $symbol = $record->unit?->symbol ?? $record->variation?->unit?->symbol;
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
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function generateEan13(): string
    {
        $base = str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $num = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $num : $num * 3;
        }

        $mod = $sum % 10;
        $checksum = $mod === 0 ? 0 : (10 - $mod);

        return $base.$checksum;
    }

    private function getBasePrice(?callable $get = null): float
    {
        if (is_callable($get)) {
            $base = $get('price_base');
            if (is_numeric($base)) {
                return (float) $base;
            }

            $input = $get('price');
            if (is_numeric($input)) {
                return (float) $input;
            }
        }

        return $this->getVariationBasePrice();
    }

    private function getVariationBasePrice(): float
    {
        $variation = $this->getOwnerRecord();

        return (float) ($variation?->price ?? 0);
    }

    private function stockFormSchema(): array
    {
        return [
            TextInput::make('barcode')
                ->required()
                ->maxLength(255)
                ->default(fn () => $this->getLastBarcode())
                ->suffixAction(
                    Action::make('generateBarcode')
                        ->label('Generate')
                        ->icon(Heroicon::OutlinedBolt)
                        ->action(fn ($set) => $set('barcode', self::generateEan13()))
                ),
            TextInput::make('batch_number')
                ->label('Batch #')
                ->default(fn () => $this->nextBatchNumber())
                ->disabled()
                ->dehydrated()
                ->maxLength(255),
            TextInput::make('price')
                ->label('Price')
                ->numeric()
                ->inputMode('decimal')
                ->step(fn () => $this->getCurrencyStep())
                ->rule(fn () => $this->getCurrencyAmountRegexRule())
                ->prefix(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                ->default(fn () => $this->getVariationBasePrice())
                ->live(onBlur: true)
                ->afterStateUpdated(function (mixed $state, $set, $get): void {
                    $decimalPlaces = $this->getCurrencyDecimalPlaces();
                    $price = is_numeric($state) ? round((float) $state, $decimalPlaces) : null;
                    if ($price !== null && (float) $state !== $price) {
                        $set('price', $price);
                    }

                    $variationUnit = $this->getOwnerRecord()?->unit;
                    $selectedUnit = Unit::query()->find($get('unit_id'));
                    if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id && $price !== null) {
                        $set('price_base', Unit::convertPrice($price, $selectedUnit, $variationUnit));
                    }

                    $base = $this->getBasePrice($get);
                    if ($base <= 0) {
                        return;
                    }

                    $taxPercentage = $get('tax_percentage');
                    $taxAmount = $get('tax_amount');
                    if (is_numeric($taxPercentage)) {
                        $taxAmount = round($base * (((float) $taxPercentage) / 100), $decimalPlaces);
                        $set('tax_amount', $taxAmount);
                        if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                            $set('tax_amount_base', Unit::convertPrice($taxAmount, $selectedUnit, $variationUnit));
                        }
                    } elseif (is_numeric($taxAmount)) {
                        $set('tax_percentage', round((((float) $taxAmount) / $base) * 100, 6));
                    }

                    // Update supplier price and percentage based on new price
                    $supplierPercentage = $get('supplier_percentage');
                    $supplierPrice = $get('supplier_price');

                    // Priority: If supplier_percentage exists, recalculate supplier_price from new price
                    if (is_numeric($supplierPercentage) && (float) $supplierPercentage >= 0) {
                        $computedSupplier = round($base - ($base * (((float) $supplierPercentage) / 100)), $decimalPlaces);
                        $set('supplier_price', $computedSupplier);
                        if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                            $set('supplier_price_base', Unit::convertPrice($computedSupplier, $selectedUnit, $variationUnit));
                        }
                    } elseif (is_numeric($supplierPrice) && (float) $supplierPrice >= 0) {
                        // If supplier_price exists but no percentage, recalculate percentage from new price
                        $newSupplierPercentage = round((($base - (float) $supplierPrice) / $base) * 100, 6);
                        $set('supplier_percentage', $newSupplierPercentage);
                        // Also update supplier_price_base if units match
                        if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                            $set('supplier_price_base', Unit::convertPrice((float) $supplierPrice, $selectedUnit, $variationUnit));
                        }
                    }
                }),
            Hidden::make('price_base')
                ->dehydrated(false)
                ->afterStateHydrated(function ($state, $set, $get): void {
                    if ($state !== null) {
                        return;
                    }

                    $price = $get('price');
                    if (is_numeric($price)) {
                        $set('price_base', $price);
                    }
                }),
            Grid::make()
                ->schema([
                    TextInput::make('tax_percentage')
                        ->label('Tax %')
                        ->numeric()
                        ->inputMode('decimal')
                        ->step('0.000001')
                        ->minValue(0)
                        ->maxValue(999.999999)
                        ->rule('regex:/^\\d{1,3}(\\.\\d{1,6})?$/')
                        ->prefix('%')
                        ->validationMessages([
                            'max' => 'Tax percentage cannot exceed 999.999999%.',
                            'min' => 'Tax percentage cannot be negative.',
                            'regex' => 'Please enter a valid percentage (e.g., 10.5 or 99.999999).',
                        ])
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                            if (! is_numeric($state)) {
                                return;
                            }
                            $percentage = round((float) $state, 6);
                            // Clamp to valid range
                            $percentage = max(0, min(999.999999, $percentage));
                            if ((float) $state !== $percentage) {
                                $set('tax_percentage', $percentage);
                            }

                            $base = $this->getBasePrice($get);
                            if ($base > 0) {
                                $taxAmount = round($base * ($percentage / 100), 2);
                                $set('tax_amount', $taxAmount);

                                $variationUnit = $this->getOwnerRecord()?->unit;
                                $selectedUnit = Unit::query()->find($get('unit_id'));
                                if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                                    $set('tax_amount_base', Unit::convertPrice($taxAmount, $selectedUnit, $variationUnit));
                                } else {
                                    $set('tax_amount_base', $taxAmount);
                                }
                            }
                        }),
                    TextInput::make('tax_amount')
                        ->label('Tax Amount')
                        ->numeric()
                        ->inputMode('decimal')
                        ->step(fn () => $this->getCurrencyStep())
                        ->rule(fn () => $this->getCurrencyAmountRegexRule())
                        ->prefix(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                            $decimalPlaces = $this->getCurrencyDecimalPlaces();
                            $amount = is_numeric($state) ? round((float) $state, $decimalPlaces) : null;
                            if ($amount !== null && (float) $state !== $amount) {
                                $set('tax_amount', $amount);
                            }

                            $variationUnit = $this->getOwnerRecord()?->unit;
                            $selectedUnit = Unit::query()->find($get('unit_id'));
                            if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id && $amount !== null) {
                                $set('tax_amount_base', Unit::convertPrice($amount, $selectedUnit, $variationUnit));
                            }

                            $base = $this->getBasePrice($get);
                            if ($amount !== null && $base > 0) {
                                $set('tax_percentage', round(($amount / $base) * 100, 6));
                            }
                        }),
                    Hidden::make('tax_amount_base')
                        ->dehydrated(false)
                        ->afterStateHydrated(function ($state, $set, $get): void {
                            if ($state !== null) {
                                return;
                            }

                            $amount = $get('tax_amount');
                            if (is_numeric($amount)) {
                                $set('tax_amount_base', $amount);
                            }
                        }),
                ])
                ->visible(fn () => Filament::getTenant()?->tax_enabled ?? false)
                ->columnSpanFull(),
            Grid::make()
                ->schema([
                    TextInput::make('supplier_percentage')
                        ->label('Supplier %')
                        ->numeric()
                        ->inputMode('decimal')
                        ->step('0.000001')
                        ->minValue(0)
                        ->maxValue(999.999999)
                        ->rule('regex:/^\\d{1,3}(\\.\\d{1,6})?$/')
                        ->prefix('%')
                        ->validationMessages([
                            'max' => 'Supplier percentage cannot exceed 999.999999%.',
                            'min' => 'Supplier percentage cannot be negative.',
                            'regex' => 'Please enter a valid percentage (e.g., 10.5 or 99.999999).',
                        ])
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                            if (! is_numeric($state)) {
                                return;
                            }
                            $percentage = round((float) $state, 6);
                            // Clamp to valid range
                            $percentage = max(0, min(999.999999, $percentage));
                            if ((float) $state !== $percentage) {
                                $set('supplier_percentage', $percentage);
                            }

                            $base = $this->getBasePrice($get);
                            if ($base > 0) {
                                $supplierPrice = round($base - ($base * ($percentage / 100)), 2);
                                $set('supplier_price', $supplierPrice);

                                $variationUnit = $this->getOwnerRecord()?->unit;
                                $selectedUnit = Unit::query()->find($get('unit_id'));
                                if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                                    $set('supplier_price_base', Unit::convertPrice($supplierPrice, $selectedUnit, $variationUnit));
                                } else {
                                    $set('supplier_price_base', $supplierPrice);
                                }
                            }
                        }),
                    TextInput::make('supplier_price')
                        ->label('Supplier Price')
                        ->numeric()
                        ->inputMode('decimal')
                        ->step(fn () => $this->getCurrencyStep())
                        ->rule(fn () => $this->getCurrencyAmountRegexRule())
                        ->prefix(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                            $decimalPlaces = $this->getCurrencyDecimalPlaces();
                            $price = is_numeric($state) ? round((float) $state, $decimalPlaces) : null;
                            if ($price !== null && (float) $state !== $price) {
                                $set('supplier_price', $price);
                            }

                            $variationUnit = $this->getOwnerRecord()?->unit;
                            $selectedUnit = Unit::query()->find($get('unit_id'));
                            if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id && $price !== null) {
                                $set('supplier_price_base', Unit::convertPrice($price, $selectedUnit, $variationUnit));
                            }

                            $base = $this->getBasePrice($get);
                            if ($price !== null && $base > 0) {
                                $set('supplier_percentage', round((($base - $price) / $base) * 100, 6));
                            }
                        }),
                    Hidden::make('supplier_price_base')
                        ->dehydrated(false)
                        ->afterStateHydrated(function ($state, $set, $get): void {
                            if ($state !== null) {
                                return;
                            }

                            $price = $get('supplier_price');
                            if (is_numeric($price)) {
                                $set('supplier_price_base', $price);
                            }
                        }),
                ])
                ->columnSpanFull(),
            Grid::make()
                ->schema([
                    TextInput::make('stock')
                        ->label('Stock')
                        ->numeric()
                        ->inputMode('decimal')
                        ->step('0.000001')
                        ->rule('regex:/^\\d+(\\.\\d{1,6})?$/')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                            if (is_numeric($state)) {
                                $rounded = round((float) $state, 6);
                                $set('stock', $rounded);
                                $variationUnit = $this->getOwnerRecord()?->unit;
                                $selectedUnit = Unit::query()->find($get('unit_id'));
                                if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                                    $set('stock_base', Unit::convertQuantity($rounded, $selectedUnit, $variationUnit));
                                } else {
                                    $set('stock_base', $rounded);
                                }
                            }
                        }),
                    Hidden::make('stock_base')
                        ->dehydrated(false)
                        ->afterStateHydrated(function ($state, $set, $get): void {
                            if ($state !== null) {
                                return;
                            }

                            $stock = $get('stock');
                            if (is_numeric($stock)) {
                                $set('stock_base', $stock);
                            }
                        }),
                    Select::make('unit_id')
                        ->label('Unit')
                        ->options(fn () => $this->getStockUnitOptions())
                        ->default(fn ($record) => $record?->unit_id ?? $this->getOwnerRecord()?->unit?->id)
                        ->required(fn () => (bool) $this->getOwnerRecord()?->unit_id)
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                            $variation = $this->getOwnerRecord();
                            $variationUnit = $variation?->unit;
                            $selectedUnit = Unit::query()->find($state);
                            if (! $variationUnit || ! $selectedUnit || $variationUnit->dimension_id !== $selectedUnit->dimension_id) {
                                return;
                            }

                            $store = Filament::getTenant();
                            $currency = $store?->currency;
                            $decimalPlaces = $currency->decimal_places ?? 2;
                            $stockValue = $get('stock');

                            $priceBase = $get('price_base');
                            if (! is_numeric($priceBase)) {
                                $currentPrice = $get('price');
                                if (is_numeric($currentPrice)) {
                                    $priceBase = Unit::convertPrice((float) $currentPrice, $selectedUnit, $variationUnit);
                                } else {
                                    $priceBase = $variation?->price ?? 0;
                                }
                                $set('price_base', $priceBase);
                            }

                            $displayPrice = Unit::convertPrice((float) $priceBase, $variationUnit, $selectedUnit);
                            $set('price', round($displayPrice, $decimalPlaces));

                            $taxAmountBase = $get('tax_amount_base');
                            if (is_numeric($taxAmountBase)) {
                                $set('tax_amount', round(Unit::convertPrice((float) $taxAmountBase, $variationUnit, $selectedUnit), $decimalPlaces));
                            } else {
                                $currentTaxAmount = $get('tax_amount');
                                if (is_numeric($currentTaxAmount)) {
                                    $set('tax_amount_base', Unit::convertPrice((float) $currentTaxAmount, $selectedUnit, $variationUnit));
                                }
                            }

                            $supplierPriceBase = $get('supplier_price_base');
                            if (is_numeric($supplierPriceBase)) {
                                $set('supplier_price', round(Unit::convertPrice((float) $supplierPriceBase, $variationUnit, $selectedUnit), $decimalPlaces));
                            } else {
                                $currentSupplier = $get('supplier_price');
                                if (is_numeric($currentSupplier)) {
                                    $set('supplier_price_base', Unit::convertPrice((float) $currentSupplier, $selectedUnit, $variationUnit));
                                }
                            }

                            if (is_numeric($stockValue)) {
                                $baseStock = Unit::convertQuantity((float) $stockValue, $selectedUnit, $variationUnit);
                                $set('stock_base', $baseStock);
                            }
                        }),
                ])
                ->columnSpanFull(),
        ];
    }

    private function nextBatchNumber(): string
    {
        $variationId = $this->getOwnerRecord()?->id;
        if (! $variationId) {
            return 'M-1';
        }

        $batches = Stock::query()
            ->where('variation_id', $variationId)
            ->where('batch_number', 'like', 'M-%')
            ->pluck('batch_number');

        $max = 0;
        foreach ($batches as $batch) {
            if (preg_match('/^M-(\d+)$/', (string) $batch, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return 'M-'.($max + 1);
    }

    private function getLastBarcode(): ?string
    {
        $variationId = $this->getOwnerRecord()?->id;
        if (! $variationId) {
            return null;
        }

        return Stock::query()
            ->where('variation_id', $variationId)
            ->latest('id')
            ->value('barcode');
    }

    private function getStockUnitOptions(): array
    {
        $variation = $this->getOwnerRecord();
        $dimensionId = $variation?->unit?->dimension_id;
        if (! $dimensionId) {
            return [];
        }

        return Unit::query()
            ->forStoreOrGlobal(Filament::getTenant()?->getKey())
            ->where('dimension_id', $dimensionId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    private function normalizeStockUnit(array $data): array
    {
        $variation = $this->getOwnerRecord();
        $variationUnit = $variation?->unit;
        $stockValue = $data['stock'] ?? null;
        $fromUnitId = $data['unit_id'] ?? null;

        if (! $variationUnit || ! is_numeric($stockValue) || ! $fromUnitId) {
            return $data;
        }

        $fromUnit = Unit::query()->find($fromUnitId);
        if (! $fromUnit || $fromUnit->dimension_id !== $variationUnit->dimension_id) {
            $data['unit_id'] = $variationUnit->id;

            return $data;
        }

        $baseValue = ((float) $stockValue * (float) $fromUnit->to_base_factor) + (float) ($fromUnit->to_base_offset ?? 0);
        $normalized = ($baseValue - (float) ($variationUnit->to_base_offset ?? 0)) / (float) $variationUnit->to_base_factor;
        if (is_numeric($data['stock_base'] ?? null)) {
            $data['stock'] = round((float) $data['stock_base'], 6);
        } else {
            $data['stock'] = round($normalized, 6);
        }
        $data['unit_id'] = $variationUnit->id;
        if (is_numeric($data['price'] ?? null)) {
            $data['price'] = Unit::convertPrice((float) $data['price'], $fromUnit, $variationUnit);
        }
        if (is_numeric($data['tax_amount'] ?? null)) {
            $data['tax_amount'] = Unit::convertPrice((float) $data['tax_amount'], $fromUnit, $variationUnit);
        }
        if (is_numeric($data['supplier_price'] ?? null)) {
            $data['supplier_price'] = Unit::convertPrice((float) $data['supplier_price'], $fromUnit, $variationUnit);
        }

        unset($data['price_base'], $data['tax_amount_base'], $data['supplier_price_base'], $data['stock_base']);

        return $data;
    }

    private function getCurrencyDecimalPlaces(): int
    {
        $currency = Filament::getTenant()?->currency;

        return max(0, (int) ($currency?->decimal_places ?? 2));
    }

    private function getCurrencyAmountRegexRule(): string
    {
        $decimalPlaces = $this->getCurrencyDecimalPlaces();
        if ($decimalPlaces === 0) {
            return 'regex:/^\\d+$/';
        }

        return 'regex:/^\\d+(\\.\\d{1,'.$decimalPlaces.'})?$/';
    }

    private function getCurrencyStep(): string
    {
        $decimalPlaces = $this->getCurrencyDecimalPlaces();
        if ($decimalPlaces === 0) {
            return '1';
        }

        return '0.'.str_repeat('0', $decimalPlaces - 1).'1';
    }
}
