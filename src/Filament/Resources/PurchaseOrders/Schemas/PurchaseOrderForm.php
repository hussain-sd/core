<?php

namespace SmartTill\Core\Filament\Resources\PurchaseOrders\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use SmartTill\Core\Filament\Forms\Components\ProductSearchInput;
use SmartTill\Core\Models\Supplier;
use SmartTill\Core\Models\Unit;
use SmartTill\Core\Models\Variation;
use SmartTill\Core\Services\CoreStoreSettingsService;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Purchase order header information
                Section::make('Purchase Order Details')
                    ->description('Basic information for this purchase order')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Select::make('supplier_id')
                                    ->label('Supplier')
                                    ->relationship('supplier', 'name')
                                    ->searchable(['name', 'email', 'phone'])
                                    ->preload()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (mixed $state, $set, $get): void {
                                        $supplier = Supplier::find($state);
                                        if ($supplier) {
                                            $supplierId = $supplier->id;
                                            $purchaseOrderCount = $supplier->purchaseOrders()->count();
                                            $reference = $supplierId.'-'.str_pad($purchaseOrderCount + 1, 3, '0', STR_PAD_LEFT);
                                            $set('reference', $reference);
                                        }
                                    })
                                    ->columnSpan(2),
                                TextInput::make('reference')
                                    ->label('Reference #')
                                    ->prefix('#')
                                    ->readOnly()
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->collapsible(false)
                    ->compact()
                    ->columnSpanFull(),

                // Items Section - Table Format
                Section::make('Items')
                    ->description('Purchase order items table')
                    ->schema([
                        // Product Search Input
                        ProductSearchInput::make('product_search')
                            ->hiddenLabel()
                            ->placeholder('Search by SKU / Description')
                            ->reactive()
                            ->columnSpanFull()
                            ->afterStateUpdated(function ($state, $set, $get) {
                                // This will be triggered when a product is selected via Livewire event
                                // The state will contain the variation ID
                                if (! $state) {
                                    return;
                                }

                                $variation = Variation::findCached($state);
                                if (! $variation) {
                                    Notification::make()
                                        ->title('Product not found')
                                        ->danger()
                                        ->duration(1000)
                                        ->send();
                                    $set('product_search', null);

                                    return;
                                }

                                PurchaseOrderForm::upsertVariation($get, $set, $variation);
                                $set('product_search', null);
                            }),

                        // Items Table
                        Repeater::make('purchaseOrderProducts')
                            ->hiddenLabel()
                            ->relationship()
                            ->defaultItems(0)
                            ->afterStateHydrated(function ($state, $set, $get) {
                                $items = $state ?? [];
                                if (empty($items)) {
                                    self::resetSummary($set);

                                    return;
                                }

                                $store = Filament::getTenant();
                                $currency = $store?->currency;
                                $decimalPlaces = $currency->decimal_places ?? 2;

                                $items = collect($items)->map(function (array $item) use ($decimalPlaces): array {
                                    // Handle supplier input
                                    if (empty($item['requested_supplier_input'])) {
                                        $percentage = $item['requested_supplier_percentage'] ?? null;
                                        $price = $item['requested_supplier_price'] ?? null;
                                        $inputIsPercent = $item['requested_supplier_is_percentage'] ?? null;

                                        if ($inputIsPercent === true) {
                                            if (is_numeric($percentage)) {
                                                $item['requested_supplier_input'] = rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%';
                                            }
                                        } elseif ($inputIsPercent === false) {
                                            if (is_numeric($price)) {
                                                $item['requested_supplier_input'] = rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.');
                                            }
                                        } elseif (is_numeric($percentage) && (float) $percentage > 0) {
                                            $item['requested_supplier_input'] = rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%';
                                        } elseif (is_numeric($price)) {
                                            $item['requested_supplier_input'] = rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.');
                                        }
                                    }

                                    // Handle tax input - always percentage with % sign
                                    $taxInput = $item['requested_tax_input'] ?? null;
                                    $taxPercentage = $item['requested_tax_percentage'] ?? null;

                                    if (empty($taxInput)) {
                                        // If no input, get from percentage
                                        if (is_numeric($taxPercentage) && (float) $taxPercentage > 0) {
                                            $formattedValue = rtrim(rtrim(number_format((float) $taxPercentage, 6, '.', ''), '0'), '.');
                                            $item['requested_tax_input'] = $formattedValue.'%';
                                        }
                                    } else {
                                        // If input exists, ensure it has % sign for display
                                        $taxInputStr = (string) $taxInput;
                                        if (! str_ends_with($taxInputStr, '%')) {
                                            // Remove % if present, parse numeric value, then add %
                                            $numericValue = (float) str_replace('%', '', trim($taxInputStr));
                                            if ($numericValue > 0) {
                                                $formattedValue = rtrim(rtrim(number_format($numericValue, 6, '.', ''), '0'), '.');
                                                $item['requested_tax_input'] = $formattedValue.'%';
                                            }
                                        }
                                    }

                                    return $item;
                                })->values()->all();

                                $set('purchaseOrderProducts', $items);
                                self::recalcSummaryFromItems($items, $set, '', 'total_requested_supplier_price');
                            })
                            ->afterStateUpdated(function ($state, $set, $get) {
                                $items = $state ?? [];
                                if (empty($items)) {
                                    self::resetSummary($set);

                                    return;
                                }

                                self::recalcSummaryFromItems($items, $set);
                            })
                            ->deletable(true)
                            ->table(function (): array {
                                $store = Filament::getTenant();
                                $isTaxEnabled = $store?->tax_enabled ?? false;

                                $columns = [
                                    Repeater\TableColumn::make('Product')->width($isTaxEnabled ? '35%' : '45%'),
                                    Repeater\TableColumn::make('Qty')->width('10%'),
                                    Repeater\TableColumn::make('Unit')->width('15%'),
                                    Repeater\TableColumn::make('Price')->width('10%'),
                                ];

                                if ($isTaxEnabled) {
                                    $columns[] = Repeater\TableColumn::make('Tax %')->width('10%');
                                }

                                $columns[] = Repeater\TableColumn::make('Cost')->width('10%');
                                $columns[] = Repeater\TableColumn::make('Totals')->width('10%');

                                return $columns;
                            })
                            ->schema(function (): array {
                                $store = Filament::getTenant();
                                $isTaxEnabled = $store?->tax_enabled ?? false;

                                $schema = [
                                    Hidden::make('variation_id'),
                                    Hidden::make('requested_tax_percentage'),
                                    Hidden::make('requested_tax_amount'),
                                    Hidden::make('requested_supplier_price'),
                                    Hidden::make('requested_supplier_percentage'),
                                    Hidden::make('requested_supplier_is_percentage'),
                                    Hidden::make('requested_unit_price_base')
                                        ->dehydrated(false)
                                        ->afterStateHydrated(function ($state, $set, $get): void {
                                            if ($state !== null) {
                                                return;
                                            }

                                            $variationId = $get('variation_id');
                                            if (! $variationId) {
                                                return;
                                            }

                                            $variation = Variation::findCached($variationId);
                                            $variationUnit = $variation?->unit;
                                            $selectedUnitId = $get('requested_unit_id');
                                            $selectedUnit = $selectedUnitId ? Unit::find($selectedUnitId) : null;
                                            $price = $get('requested_unit_price');

                                            if ($variationUnit && $selectedUnit && is_numeric($price) && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                                                $basePrice = Unit::convertPrice((float) $price, $selectedUnit, $variationUnit);
                                                $set('requested_unit_price_base', $basePrice);

                                                return;
                                            }

                                            if ($variation) {
                                                $set('requested_unit_price_base', $variation->price);
                                            }
                                        }),
                                    TextInput::make('description')
                                        ->disabled()
                                        ->extraInputAttributes([
                                            'class' => 'text-xs py-0.5 px-1.5 h-7',
                                            'data-sale-item-input' => 'true',
                                            'x-on:focus' => '$event.target.select && $event.target.select()',
                                        ])
                                        ->dehydrated(),
                                    TextInput::make('requested_quantity')
                                        ->default(1)
                                        ->inputMode('decimal')
                                        ->rule('regex:/^\\d+(\\.\\d{1,6})?$/')
                                        ->live(onBlur: true)
                                        ->extraInputAttributes([
                                            'class' => 'text-xs py-0.5 px-1.5 h-7',
                                            'data-sale-item-input' => 'true',
                                            'x-on:focus' => '$event.target.select && $event.target.select()',
                                        ])
                                        ->afterStateUpdated(function (mixed $state, $set, $get) {
                                            $rounded = $state;
                                            if (is_numeric($state)) {
                                                $rounded = round((float) $state, 6);
                                                $set('requested_quantity', $rounded);
                                            }

                                            self::updateRequestedLineTotal($get, $set);
                                            self::refreshRepeater($get, $set);
                                        }),
                                    Select::make('requested_unit_id')
                                        ->label('Unit')
                                        ->options(function (callable $get): array {
                                            $variationId = $get('variation_id');
                                            if (! $variationId) {
                                                return [];
                                            }

                                            $variation = Variation::findCached($variationId);
                                            $unit = $variation?->unit;
                                            if (! $unit) {
                                                return [];
                                            }

                                            $storeId = Filament::getTenant()?->getKey();

                                            return Unit::query()
                                                ->forStoreOrGlobal($storeId)
                                                ->where('dimension_id', $unit->dimension_id)
                                                ->orderBy('name')
                                                ->get()
                                                ->mapWithKeys(fn (Unit $u) => [
                                                    $u->id => $u->symbol ? "{$u->name} ({$u->symbol})" : $u->name,
                                                ])
                                                ->all();
                                        })
                                        ->default(function (callable $get) {
                                            $variationId = $get('variation_id');
                                            if (! $variationId) {
                                                return null;
                                            }

                                            $variation = Variation::findCached($variationId);

                                            return $variation?->unit_id;
                                        })
                                        ->required(fn (callable $get) => (bool) (Variation::findCached($get('variation_id'))?->unit_id))
                                        ->live()
                                        ->extraInputAttributes([
                                            'class' => 'text-xs py-0.5 px-1.5 h-7',
                                            'data-sale-item-input' => 'true',
                                        ])
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            $variationId = $get('variation_id');
                                            if (! $variationId || ! $state) {
                                                return;
                                            }

                                            $variation = Variation::findCached($variationId);
                                            $variationUnit = $variation?->unit;
                                            $selectedUnit = Unit::find($state);

                                            if (! $variationUnit || ! $selectedUnit || $variationUnit->dimension_id !== $selectedUnit->dimension_id) {
                                                return;
                                            }

                                            $basePrice = $get('requested_unit_price_base');
                                            if (! is_numeric($basePrice)) {
                                                $basePrice = $variation->price;
                                                $set('requested_unit_price_base', $basePrice);
                                            }

                                            $store = Filament::getTenant();
                                            $currency = $store?->currency;
                                            $decimalPlaces = $currency->decimal_places ?? 2;
                                            $displayPrice = Unit::convertPrice((float) $basePrice, $variationUnit, $selectedUnit);
                                            $roundedDisplayPrice = round($displayPrice, $decimalPlaces);
                                            $set('requested_unit_price', $roundedDisplayPrice);
                                            $store = Filament::getTenant();
                                            $barcode = $variation->stocks()->latest('id')->first();
                                            $taxPercent = $store?->getEffectiveTaxPercentage($barcode) ?? 0;
                                            $taxAmount = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $barcode, $roundedDisplayPrice);

                                            if ($taxPercent > 0) {
                                                $set('requested_tax_percentage', $taxPercent);
                                                $set('requested_tax_amount', round($taxAmount, $decimalPlaces));
                                                $formattedValue = rtrim(rtrim(number_format((float) $taxPercent, 6, '.', ''), '0'), '.');
                                                $set('requested_tax_input', $formattedValue.'%');
                                            } else {
                                                $set('requested_tax_percentage', 0);
                                                $set('requested_tax_amount', 0);
                                                $set('requested_tax_input', null);
                                            }

                                            $supplierPercentage = (float) ($get('requested_supplier_percentage') ?? 0);
                                            $supplierPrice = null;
                                            if ($supplierPercentage > 0) {
                                                $supplierPrice = $roundedDisplayPrice - ($roundedDisplayPrice * ($supplierPercentage / 100));
                                                $set('requested_supplier_price', round($supplierPrice, $decimalPlaces));
                                            } elseif (filled($get('requested_supplier_input'))) {
                                                $supplier = self::syncSupplierFields($get, $set, $get('requested_supplier_input'));
                                                $supplierPrice = $supplier['supplier_price'] ?? null;
                                            }

                                            self::updateRequestedLineTotal($get, $set);
                                            self::refreshRepeater($get, $set);
                                        }),

                                    TextInput::make('requested_unit_price')
                                        ->inputMode('decimal')
                                        ->rule(function () {
                                            $store = Filament::getTenant();
                                            $currency = $store?->currency;
                                            $decimalPlaces = max(0, (int) ($currency->decimal_places ?? 2));

                                            if ($decimalPlaces === 0) {
                                                return 'regex:/^\\d+$/';
                                            }

                                            return 'regex:/^\\d+(\\.\\d{1,'.$decimalPlaces.'})?$/';
                                        })
                                        ->live(onBlur: true)
                                        ->extraInputAttributes([
                                            'class' => 'text-xs py-0.5 px-1.5 h-7',
                                            'data-sale-item-input' => 'true',
                                            'x-on:focus' => '$event.target.select && $event.target.select()',
                                        ])
                                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                                            $store = Filament::getTenant();
                                            $currency = $store?->currency;
                                            $decimalPlaces = $currency->decimal_places ?? 2;

                                            $price = is_numeric($state) ? round((float) $state, $decimalPlaces) : null;
                                            if ($price !== null && (float) $state !== $price) {
                                                $set('requested_unit_price', $price);
                                            }

                                            $variationId = $get('variation_id');
                                            $variation = $variationId ? Variation::findCached($variationId) : null;
                                            $variationUnit = $variation?->unit;
                                            $selectedUnitId = $get('requested_unit_id');
                                            $selectedUnit = $selectedUnitId ? Unit::find($selectedUnitId) : null;

                                            $unitPrice = (float) ($price ?? $state ?? 0);
                                            if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                                                $basePrice = Unit::convertPrice($unitPrice, $selectedUnit, $variationUnit);
                                                $set('requested_unit_price_base', $basePrice);
                                            }
                                            $store = Filament::getTenant();
                                            $variationId = $get('variation_id');
                                            $variation = $variationId ? Variation::findCached($variationId) : null;

                                            // Preserve tax percentage when price changes
                                            $taxInput = $get('requested_tax_input');
                                            $taxPercentage = $get('requested_tax_percentage');

                                            // Remove % from taxInput if present for calculation
                                            $taxInputNumeric = is_string($taxInput) ? str_replace('%', '', trim($taxInput)) : $taxInput;

                                            if (filled($taxInputNumeric) && is_numeric($taxInputNumeric) && $store?->tax_enabled) {
                                                // Re-sync tax fields with existing percentage to recalculate amount based on new price
                                                $taxPercentValue = (float) $taxInputNumeric;
                                                self::syncTaxFields($get, $set, $taxPercentValue);
                                                // Format with % for display
                                                $formattedValue = rtrim(rtrim(number_format($taxPercentValue, 6, '.', ''), '0'), '.');
                                                $set('requested_tax_input', $formattedValue.'%');
                                            } elseif (is_numeric($taxPercentage) && (float) $taxPercentage > 0 && $store?->tax_enabled) {
                                                // Recalculate amount for existing percentage
                                                $taxAmount = round($unitPrice * ((float) $taxPercentage / 100), $decimalPlaces);
                                                $set('requested_tax_amount', $taxAmount);
                                                // Format with % for display
                                                $formattedValue = rtrim(rtrim(number_format((float) $taxPercentage, 6, '.', ''), '0'), '.');
                                                $set('requested_tax_input', $formattedValue.'%');
                                            } else {
                                                // Initialize from stock only if no tax input exists
                                                $barcode = $variation?->stocks()->latest('id')->first();
                                                $taxPercent = $store?->getEffectiveTaxPercentage($barcode) ?? 0;
                                                $taxAmount = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $barcode, $unitPrice);

                                                if ($taxPercent > 0 && $store?->tax_enabled) {
                                                    $set('requested_tax_percentage', $taxPercent);
                                                    $set('requested_tax_amount', round($taxAmount, $decimalPlaces));
                                                    $formattedValue = rtrim(rtrim(number_format((float) $taxPercent, 6, '.', ''), '0'), '.');
                                                    $set('requested_tax_input', $formattedValue.'%');
                                                } else {
                                                    $set('requested_tax_percentage', 0);
                                                    $set('requested_tax_amount', 0);
                                                    $set('requested_tax_input', null);
                                                }
                                            }

                                            $supplier = self::syncSupplierFields($get, $set);

                                            self::updateRequestedLineTotal($get, $set);
                                            self::refreshRepeater($get, $set);
                                        }),
                                ];

                                // Conditionally include tax field only when tax is enabled
                                if ($isTaxEnabled) {
                                    $schema[] = TextInput::make('requested_tax_input')
                                        ->label('Tax')
                                        ->inputMode('decimal')
                                        ->placeholder('18%')
                                        ->rule('regex:/^\\d+(\\.\\d{1,6})?%?$/')
                                        ->dehydrated(false)
                                        ->live(onBlur: true)
                                        ->formatStateUsing(function ($state): ?string {
                                            if (empty($state)) {
                                                return null;
                                            }
                                            // Remove % if present for parsing
                                            $numericValue = is_string($state) ? str_replace('%', '', trim($state)) : $state;
                                            if (is_numeric($numericValue) && (float) $numericValue > 0) {
                                                return rtrim(rtrim(number_format((float) $numericValue, 6, '.', ''), '0'), '.').'%';
                                            }

                                            return null;
                                        })
                                        ->visible(fn () => Filament::getTenant()?->tax_enabled ?? false)
                                        ->extraInputAttributes([
                                            'class' => 'text-xs py-0.5 px-1.5 h-7',
                                            'data-sale-item-input' => 'true',
                                            'x-on:focus' => '$event.target.select && $event.target.select()',
                                        ])
                                        ->afterStateHydrated(function ($state, $set, $get): void {
                                            // Always ensure % sign is present for display
                                            if ($state !== null && $state !== '') {
                                                $stateStr = (string) $state;
                                                // If it doesn't end with %, add it
                                                if (! str_ends_with($stateStr, '%')) {
                                                    // Remove % if present, parse numeric value, then add %
                                                    $numericValue = (float) str_replace('%', '', trim($stateStr));
                                                    if ($numericValue > 0) {
                                                        $formattedValue = rtrim(rtrim(number_format($numericValue, 6, '.', ''), '0'), '.');
                                                        $set('requested_tax_input', $formattedValue.'%');
                                                    }
                                                }

                                                return;
                                            }

                                            $store = Filament::getTenant();
                                            if (! $store?->tax_enabled) {
                                                return;
                                            }

                                            $percentage = $get('requested_tax_percentage');
                                            if (is_numeric($percentage) && (float) $percentage > 0) {
                                                $formattedValue = rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.');
                                                $set('requested_tax_input', $formattedValue.'%');
                                            }
                                        })
                                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                                            $rawInput = is_string($state) ? trim($state) : $state;

                                            // Handle empty/null input
                                            if ($rawInput === null || $rawInput === '') {
                                                $set('requested_tax_percentage', 0);
                                                $set('requested_tax_amount', 0);

                                                self::refreshRepeater($get, $set);

                                                return;
                                            }

                                            // Remove % if present for calculation
                                            $numericInput = is_string($rawInput) ? str_replace('%', '', $rawInput) : $rawInput;
                                            $numericValue = is_numeric($numericInput) ? (float) $numericInput : null;

                                            // Handle non-numeric input
                                            if ($numericValue === null) {
                                                $set('requested_tax_input', '');
                                                $set('requested_tax_percentage', 0);
                                                $set('requested_tax_amount', 0);

                                                self::refreshRepeater($get, $set);

                                                return;
                                            }

                                            // Validate percentage range (0-999.999999)
                                            if ($numericValue < 0 || $numericValue > 999.999999) {
                                                $set('requested_tax_percentage', 0);
                                                $set('requested_tax_amount', 0);

                                                self::refreshRepeater($get, $set);

                                                return;
                                            }

                                            $store = Filament::getTenant();
                                            $currency = $store?->currency;
                                            $decimalPlaces = $currency->decimal_places ?? 2;

                                            $roundedValue = round($numericValue, 6);
                                            $formattedValue = rtrim(rtrim(number_format($roundedValue, 6, '.', ''), '0'), '.');

                                            // Sync tax fields - always percentage
                                            self::syncTaxFields($get, $set, $roundedValue);

                                            // Update input with formatted value + % for visibility
                                            $set('requested_tax_input', $formattedValue.'%');

                                            self::refreshRepeater($get, $set);
                                        });
                                }

                                // Always include supplier field
                                $schema[] = TextInput::make('requested_supplier_input')
                                    ->label('Supplier Price')
                                    ->inputMode('decimal')
                                    ->placeholder('30% or 70')
                                    ->rule('regex:/^\\d+(\\.\\d{1,6})?%?$/')
                                    ->dehydrated(false)
                                    ->live(onBlur: true)
                                    ->extraInputAttributes([
                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                        'data-sale-item-input' => 'true',
                                        'x-on:focus' => '$event.target.select && $event.target.select()',
                                    ])
                                    ->afterStateHydrated(function ($state, $set, $get): void {
                                        if ($state !== null && $state !== '') {
                                            return;
                                        }

                                        $store = Filament::getTenant();
                                        $currency = $store?->currency;
                                        $decimalPlaces = $currency->decimal_places ?? 2;

                                        $percentage = $get('requested_supplier_percentage');
                                        $price = $get('requested_supplier_price');
                                        $inputIsPercent = $get('requested_supplier_is_percentage');

                                        if ($inputIsPercent === true && is_numeric($percentage)) {
                                            $set('requested_supplier_input', rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%');

                                            return;
                                        }

                                        if ($inputIsPercent === false && is_numeric($price)) {
                                            $set('requested_supplier_input', rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.'));

                                            return;
                                        }

                                        if (is_numeric($percentage) && (float) $percentage > 0) {
                                            $set('requested_supplier_input', rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%');

                                            return;
                                        }

                                        if (is_numeric($price)) {
                                            $set('requested_supplier_input', rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.'));
                                        }
                                    })
                                    ->afterStateUpdated(function (mixed $state, $set, $get): void {
                                        $rawInput = is_string($state) ? trim($state) : $state;

                                        // Handle empty/null input
                                        if ($rawInput === null || $rawInput === '') {
                                            $set('requested_supplier_percentage', null);
                                            $set('requested_supplier_price', null);
                                            $set('requested_supplier_is_percentage', null);

                                            return;
                                        }

                                        $isPercent = is_string($rawInput) && str_ends_with($rawInput, '%');
                                        $numericValue = is_numeric(str_replace('%', '', (string) $rawInput))
                                            ? (float) str_replace('%', '', (string) $rawInput)
                                            : null;

                                        // Handle non-numeric input
                                        if ($numericValue === null) {
                                            $set('requested_supplier_input', '');
                                            // Clear hidden fields when invalid (non-numeric) input is detected
                                            $set('requested_supplier_percentage', null);
                                            $set('requested_supplier_price', null);
                                            $set('requested_supplier_is_percentage', null);

                                            return;
                                        }

                                        // Validate percentage range (0-999.999999)
                                        if ($isPercent && ($numericValue < 0 || $numericValue > 999.999999)) {
                                            $set('requested_supplier_input', '');
                                            // Clear hidden fields when invalid input is detected
                                            $set('requested_supplier_percentage', null);
                                            $set('requested_supplier_price', null);
                                            $set('requested_supplier_is_percentage', null);

                                            return;
                                        }

                                        $store = Filament::getTenant();
                                        $currency = $store?->currency;
                                        $decimalPlaces = $currency->decimal_places ?? 2;

                                        $roundedValue = $isPercent ? round($numericValue, 6) : round($numericValue, $decimalPlaces);
                                        $set(
                                            'requested_supplier_input',
                                            $isPercent
                                                ? rtrim(rtrim(number_format($roundedValue, 6, '.', ''), '0'), '.').'%'
                                                : rtrim(rtrim(number_format($roundedValue, $decimalPlaces, '.', ''), '0'), '.')
                                        );

                                        $supplier = self::syncSupplierFields($get, $set, $state);

                                        self::updateRequestedLineTotal($get, $set);
                                        self::refreshRepeater($get, $set);
                                    });

                                $schema[] = TextInput::make('requested_line_total')
                                    ->label('Totals')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric()
                                    ->extraInputAttributes([
                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                    ])
                                    ->afterStateHydrated(function ($state, $set, $get): void {
                                        self::updateRequestedLineTotal($get, $set);
                                    });

                                return $schema;
                            })
                            ->addable(false)
                            ->reorderable(false)
                            ->itemLabel(function (array $state): ?string {
                                if (! ($state['variation_id'] ?? null)) {
                                    return null;
                                }

                                $variation = Variation::findCached($state['variation_id']);
                                if (! $variation) {
                                    return null;
                                }

                                // Use description from state if available, otherwise from variation
                                $description = $state['description'] ?? $variation->description;
                                $productName = $variation->product->name ?? '';

                                return $productName ? "{$productName} - {$description}" : $description;
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible(false)
                    ->columnSpanFull(),

                // Summary Section - Compact and Professional
                Section::make('Order Summary')
                    ->description('Total calculations for this purchase order')
                    ->inlineLabel()
                    ->afterHeader([
                    ])
                    ->schema([
                        TextInput::make('total_requested_quantity')
                            ->label('Total Items')
                            ->disabled()
                            ->numeric()
                            ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((int) $state) : '0')
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('total_requested_tax_amount')
                            ->label('Total Tax')
                            ->disabled()
                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step(0.01)
                            ->rule('regex:/^\\d+(\\.\\d{1,2})?$/')
                            ->visible(fn () => Filament::getTenant()?->tax_enabled ?? false)
                            ->columnSpanFull(),

                        TextInput::make('total_requested_unit_price')
                            ->label('Total Unit Price')
                            ->disabled()
                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step(0.01)
                            ->rule('regex:/^\\d+(\\.\\d{1,2})?$/')
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('total_requested_supplier_price')
                            ->label('Total Supplier Cost')
                            ->disabled()
                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step(0.01)
                            ->rule('regex:/^\\d+(\\.\\d{1,2})?$/')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->collapsible(false)
                    ->compact()
                    ->columnSpanFull(),
            ]);
    }

    private static function recalcSummaryFromItems(
        array $items,
        callable $set,
        string $statePathPrefix = '',
        string $totalPath = 'total_requested_supplier_price'
    ): void {
        if (empty($items)) {
            $set($statePathPrefix.'total_requested_quantity', null);
            $set($statePathPrefix.'total_requested_unit_price', null);
            $set($statePathPrefix.'total_requested_tax_amount', null);
            $set($statePathPrefix.'total_requested_supplier_price', null);

            return;
        }

        $itemsCount = count($items);
        $sumQty = 0.0;
        $sumSupplier = 0.0;
        $sumUnit = 0.0;
        $sumTax = 0.0;
        $store = Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;

        foreach ($items as $item) {
            $qty = (float) ($item['requested_quantity'] ?? 0);
            $unit = (float) ($item['requested_unit_price'] ?? 0);

            // Calculate tax: use stored amount if available, otherwise calculate from percentage
            $taxAmount = 0.0;
            if ($store?->tax_enabled) {
                $taxAmount = (float) ($item['requested_tax_amount'] ?? 0);
                if ($taxAmount === 0.0) {
                    $taxP = (float) ($item['requested_tax_percentage'] ?? 0);
                    $lineUnitTotal = $qty * $unit;
                    $taxAmount = $lineUnitTotal * ($taxP / 100);
                } else {
                    // Tax amount is per unit, multiply by quantity
                    $taxAmount = $taxAmount * $qty;
                }
            }

            $supplier = 0.0;
            $supplierPrice = $item['requested_supplier_price'] ?? null;
            $supplierPercent = $item['requested_supplier_percentage'] ?? null;
            $inputIsPercent = $item['requested_supplier_is_percentage'] ?? null;

            if (is_numeric($supplierPrice)) {
                $supplier = (float) $supplierPrice;
            } elseif ($inputIsPercent === true && is_numeric($supplierPercent) && $unit > 0) {
                $supplier = round($unit - ($unit * ((float) $supplierPercent / 100)), $decimalPlaces);
            } elseif (is_numeric($supplierPercent) && $unit > 0) {
                $supplier = round($unit - ($unit * ((float) $supplierPercent / 100)), $decimalPlaces);
            }

            $sumQty += $qty;
            $sumSupplier += $qty * $supplier;
            $lineUnitTotal = $qty * $unit;
            $sumUnit += $lineUnitTotal;
            $sumTax += $taxAmount;
        }

        $prefix = str_contains($totalPath, 'total_requested_supplier_price')
            ? str_replace('total_requested_supplier_price', '', $totalPath)
            : '';

        $set($statePathPrefix.$prefix.'total_requested_quantity', $itemsCount);
        $set($statePathPrefix.$prefix.'total_requested_unit_price', round($sumUnit, $decimalPlaces));
        $set($statePathPrefix.$prefix.'total_requested_tax_amount', round($sumTax, $decimalPlaces));
        $set($statePathPrefix.$prefix.'total_requested_supplier_price', round($sumSupplier, $decimalPlaces));
    }

    /**
     * Insert a variation into the purchase order or increment an existing one, then update the summary.
     */
    private static function upsertVariation(callable $get, callable $set, Variation $variation): void
    {
        $items = $get('purchaseOrderProducts') ?? [];
        $found = false;

        foreach ($items as $key => &$item) {
            if ($item['variation_id'] == $variation->id) {
                $item['requested_quantity'] += 1;
                $item['requested_unit_id'] = $item['requested_unit_id'] ?? $variation->unit_id;
                $item['requested_unit_price_base'] = $item['requested_unit_price_base'] ?? $variation->price;

                $store = Filament::getTenant();
                $currency = $store?->currency;
                $decimalPlaces = $currency->decimal_places ?? 2;

                // Recalculate tax amount and supplier price based on current values
                $store = Filament::getTenant();
                $unitPrice = (float) ($item['requested_unit_price'] ?? 0);

                // Recalculate tax amount based on percentage (taxes are always percentages)
                $taxPercentage = (float) ($item['requested_tax_percentage'] ?? 0);
                $taxInput = $item['requested_tax_input'] ?? null;

                if ($store?->tax_enabled && $unitPrice > 0 && $taxPercentage > 0) {
                    // Recalculate amount based on new price
                    $item['requested_tax_amount'] = round($unitPrice * ($taxPercentage / 100), $decimalPlaces);
                    // Keep the input as percentage with % sign for display
                    if (filled($taxInput)) {
                        // Remove % if present, then format and add back
                        $taxInputNumeric = is_string($taxInput) ? str_replace('%', '', trim($taxInput)) : $taxInput;
                        if (is_numeric($taxInputNumeric)) {
                            $formattedValue = rtrim(rtrim(number_format((float) $taxInputNumeric, 6, '.', ''), '0'), '.');
                            $item['requested_tax_input'] = $formattedValue.'%';
                        } else {
                            $formattedValue = rtrim(rtrim(number_format((float) $taxPercentage, 6, '.', ''), '0'), '.');
                            $item['requested_tax_input'] = $formattedValue.'%';
                        }
                    } else {
                        $formattedValue = rtrim(rtrim(number_format((float) $taxPercentage, 6, '.', ''), '0'), '.');
                        $item['requested_tax_input'] = $formattedValue.'%';
                    }
                } elseif ($store?->tax_enabled && filled($taxInput)) {
                    // Remove % if present for calculation
                    $taxInputNumeric = is_string($taxInput) ? str_replace('%', '', trim($taxInput)) : $taxInput;
                    if (is_numeric($taxInputNumeric)) {
                        // If we have input but percentage is 0, set percentage from input
                        $taxPercent = (float) $taxInputNumeric;
                        $item['requested_tax_percentage'] = $taxPercent;
                        $item['requested_tax_amount'] = round($unitPrice * ($taxPercent / 100), $decimalPlaces);
                        $formattedValue = rtrim(rtrim(number_format($taxPercent, 6, '.', ''), '0'), '.');
                        $item['requested_tax_input'] = $formattedValue.'%';
                    }
                } elseif (! $store?->tax_enabled || $taxPercentage === 0) {
                    $item['requested_tax_percentage'] = 0;
                    $item['requested_tax_amount'] = 0;
                    $item['requested_tax_input'] = null;
                }

                $supplierPercentage = (float) ($item['requested_supplier_percentage'] ?? 0);
                $supplierPrice = $item['requested_supplier_price'] ?? null;
                $inputIsPercent = $item['requested_supplier_is_percentage'] ?? null;

                if ($inputIsPercent === true) {
                    $item['requested_supplier_price'] = round($unitPrice - ($unitPrice * ($supplierPercentage / 100)), $decimalPlaces);
                    $item['requested_supplier_input'] = rtrim(rtrim(number_format((float) $supplierPercentage, 6, '.', ''), '0'), '.').'%';
                } elseif ($inputIsPercent === false) {
                    $item['requested_supplier_input'] = is_numeric($supplierPrice)
                        ? rtrim(rtrim(number_format((float) $supplierPrice, $decimalPlaces, '.', ''), '0'), '.')
                        : null;
                } elseif ($supplierPercentage > 0) {
                    $item['requested_supplier_price'] = round($unitPrice - ($unitPrice * ($supplierPercentage / 100)), $decimalPlaces);
                    $item['requested_supplier_input'] = rtrim(rtrim(number_format((float) $supplierPercentage, 6, '.', ''), '0'), '.').'%';
                } elseif (is_numeric($supplierPrice)) {
                    $item['requested_supplier_input'] = rtrim(rtrim(number_format((float) $supplierPrice, $decimalPlaces, '.', ''), '0'), '.');
                }

                // Ensure description is set correctly
                $variation = Variation::findCached($item['variation_id']);
                if ($variation) {
                    $item['description'] = $variation->sku.' - '.$variation->description;
                }

                unset($items[$key]);
                array_unshift($items, $item);
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            $unitPrice = $variation->price;
            $barcode = $variation->stocks()
                ->latest('id')
                ->first();
            $store = Filament::getTenant();
            $taxPercentage = $store?->getEffectiveTaxPercentage($barcode) ?? 0;
            $supplierPercentage = $barcode?->supplier_percentage ?? 0;
            $currency = $store?->currency;
            $decimalPlaces = $currency->decimal_places ?? 2;

            // Calculate tax amount based on unit price and tax percentage (will be 0 if taxes disabled)
            $taxAmount = $unitPrice * ($taxPercentage / 100);

            // Calculate supplier price based on unit price and supplier percentage
            $supplierPrice = $unitPrice - ($unitPrice * ($supplierPercentage / 100));

            $taxInput = null;
            if ($store?->tax_enabled && $taxPercentage > 0) {
                $formattedValue = rtrim(rtrim(number_format((float) $taxPercentage, 6, '.', ''), '0'), '.');
                $taxInput = $formattedValue.'%';
            }

            array_unshift($items, [
                'variation_id' => $variation->id,
                'description' => $variation->sku.' - '.$variation->description,
                'requested_quantity' => 1,
                'requested_unit_id' => $variation->unit_id,
                'requested_unit_price_base' => $unitPrice,
                'requested_unit_price' => $unitPrice,
                'requested_tax_percentage' => $taxPercentage,
                'requested_tax_amount' => round($taxAmount, $decimalPlaces),
                'requested_tax_input' => $taxInput,
                'requested_supplier_percentage' => $supplierPercentage,
                'requested_supplier_is_percentage' => $supplierPercentage > 0 ? true : null,
                'requested_supplier_price' => round($supplierPrice, $decimalPlaces),
                'requested_supplier_input' => rtrim(rtrim(number_format((float) $supplierPercentage, 6, '.', ''), '0'), '.').'%',
            ]);
        }

        $set('purchaseOrderProducts', array_values($items));
        self::recalcSummaryFromItems($items, $set);
    }

    /**
     * Reset summary fields and flag when items change.
     */
    private static function resetSummary(callable $set): void
    {
        $set('total_requested_quantity', null);
        $set('total_requested_unit_price', null);
        $set('total_requested_tax_amount', null);
        $set('total_requested_supplier_price', null);
    }

    /**
     * Sync tax fields - taxes are always percentages.
     */
    private static function syncTaxFields(callable $get, callable $set, float $percentage): array
    {
        $unitPrice = (float) $get('requested_unit_price');
        $store = Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;

        if ($percentage < 0 || $percentage > 999.999999 || ! $store?->tax_enabled) {
            $set('requested_tax_percentage', 0);
            $set('requested_tax_amount', 0);

            return ['tax_percentage' => 0, 'tax_amount' => 0];
        }

        $roundedPercentage = round($percentage, 6);
        $set('requested_tax_percentage', $roundedPercentage);

        $taxAmount = null;
        if ($unitPrice > 0) {
            $taxAmount = round($unitPrice * ($roundedPercentage / 100), $decimalPlaces);
            $set('requested_tax_amount', $taxAmount);
        } else {
            $set('requested_tax_amount', 0);
        }

        return [
            'tax_percentage' => $roundedPercentage,
            'tax_amount' => $taxAmount ?? 0,
        ];
    }

    private static function syncSupplierFields(callable $get, callable $set, mixed $stateOverride = null): array
    {
        $rawInput = $stateOverride ?? $get('requested_supplier_input');
        $rawInput = is_string($rawInput) ? trim($rawInput) : $rawInput;
        $unitPrice = (float) $get('requested_unit_price');
        $store = Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;

        if ($rawInput === null || $rawInput === '') {
            $set('requested_supplier_is_percentage', null);

            return ['supplier_price' => null, 'supplier_percentage' => null];
        }

        $isPercent = is_string($rawInput) && str_ends_with($rawInput, '%');
        $numericValue = (float) str_replace('%', '', (string) $rawInput);

        if ($isPercent) {
            // Validate percentage range (0-999.999999)
            if ($numericValue < 0 || $numericValue > 999.999999) {
                $set('requested_supplier_input', '');
                $set('requested_supplier_percentage', null);
                $set('requested_supplier_price', null);
                $set('requested_supplier_is_percentage', null);

                return ['supplier_price' => null, 'supplier_percentage' => null];
            }

            $percentage = round($numericValue, 6);
            $set('requested_supplier_percentage', $percentage);
            $set('requested_supplier_is_percentage', true);

            $supplierPrice = null;
            if ($unitPrice > 0) {
                $supplierPrice = $unitPrice - ($unitPrice * ($percentage / 100));
                $set('requested_supplier_price', round($supplierPrice, $decimalPlaces));
            }

            return [
                'supplier_price' => $supplierPrice !== null ? round($supplierPrice, $decimalPlaces) : null,
                'supplier_percentage' => $percentage,
            ];
        }

        $price = round($numericValue, $decimalPlaces);
        $set('requested_supplier_price', $price);
        $set('requested_supplier_is_percentage', false);

        if ($unitPrice > 0) {
            $percentage = (($unitPrice - $price) / $unitPrice) * 100;
            $set('requested_supplier_percentage', round($percentage, 6));
        }

        return [
            'supplier_price' => $price,
            'supplier_percentage' => $unitPrice > 0 ? round($percentage, 6) : null,
        ];
    }

    private static function updateRequestedLineTotal(callable $get, callable $set): void
    {
        $quantity = (float) ($get('requested_quantity') ?? 0);
        $supplierPrice = $get('requested_supplier_price');

        if (! is_numeric($supplierPrice)) {
            $supplierPrice = 0.0;
            $rawInput = $get('requested_supplier_input');
            $rawInput = is_string($rawInput) ? trim($rawInput) : $rawInput;

            if (is_numeric($rawInput)) {
                $supplierPrice = (float) $rawInput;
            } elseif (is_string($rawInput) && str_ends_with($rawInput, '%')) {
                $unitPrice = (float) ($get('requested_unit_price') ?? 0);
                $numericValue = (float) str_replace('%', '', $rawInput);
                $supplierPrice = $unitPrice - ($unitPrice * ($numericValue / 100));
            }
        }

        $store = Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;
        $lineTotal = $quantity * (float) $supplierPrice;

        $set('requested_line_total', round($lineTotal, $decimalPlaces));
    }

    private static function refreshRepeater(callable $get, callable $set): void
    {
        $path = '../../purchaseOrderProducts';
        $set($path, $get($path) ?? [], shouldCallUpdatedHooks: true);
    }
}
