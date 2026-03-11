<?php

namespace SmartTill\Core\Filament\Resources\PurchaseOrders\Schemas;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use SmartTill\Core\Models\Unit;
use SmartTill\Core\Models\Variation;

class ReceiveForm
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
                                TextInput::make('supplierName')
                                    ->label('Supplier')
                                    ->disabled()
                                    ->columnSpan(2),
                                TextInput::make('reference')
                                    ->label('Reference #')
                                    ->prefix('#')
                                    ->disabled()
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->collapsible(false)
                    ->compact()
                    ->columnSpanFull(),

                // Items Table
                Section::make('Items to Receive')
                    ->description('Update received quantities and pricing')
                    ->schema([
                        Repeater::make('purchaseOrderProducts')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->afterStateHydrated(function ($state, $set) {
                                $items = $state ?? [];
                                if (empty($items)) {
                                    self::resetSummary($set);

                                    return;
                                }

                                $store = Filament::getTenant();
                                $currency = $store?->currency;
                                $decimalPlaces = $currency->decimal_places ?? 2;

                                $items = collect($items)->map(function (array $item) use ($decimalPlaces, $store): array {
                                    // Ensure received_tax_input has % sign if it exists (prefilled in ClosePurchaseOrder mount)
                                    if (! empty($item['received_tax_input']) && $store?->tax_enabled) {
                                        $taxInputStr = (string) $item['received_tax_input'];
                                        if (! str_ends_with($taxInputStr, '%')) {
                                            $numericValue = (float) str_replace('%', '', trim($taxInputStr));
                                            if ($numericValue > 0) {
                                                $formatted = rtrim(rtrim(number_format($numericValue, 6, '.', ''), '0'), '.');
                                                $item['received_tax_input'] = $formatted.'%';
                                            }
                                        }
                                    }

                                    if (! empty($item['received_supplier_input'])) {
                                        return $item;
                                    }

                                    $percentage = $item['received_supplier_percentage'] ?? $item['requested_supplier_percentage'] ?? null;
                                    $price = $item['received_supplier_price'] ?? $item['requested_supplier_price'] ?? null;
                                    $inputIsPercent = $item['received_supplier_is_percentage']
                                        ?? $item['requested_supplier_is_percentage']
                                        ?? null;

                                    if ($inputIsPercent === true) {
                                        if (is_numeric($percentage)) {
                                            $item['received_supplier_input'] = rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%';

                                            return $item;
                                        }
                                    } elseif ($inputIsPercent === false) {
                                        if (is_numeric($price)) {
                                            $item['received_supplier_input'] = rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.');

                                            return $item;
                                        }
                                    }

                                    if (is_numeric($percentage) && (float) $percentage > 0) {
                                        $item['received_supplier_input'] = rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%';

                                        return $item;
                                    }

                                    if (is_numeric($price)) {
                                        $item['received_supplier_input'] = rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.');
                                    }

                                    return $item;
                                })->values()->all();

                                $set('purchaseOrderProducts', $items);
                                self::recalcSummaryFromItems($items, $set);
                            })
                            ->afterStateUpdated(function ($state, $set, $get) {
                                $items = $state ?? [];
                                if (empty($items)) {
                                    self::resetSummary($set);

                                    return;
                                }

                                self::recalcSummaryFromItems($items, $set);
                            })
                            ->table(function (): array {
                                $store = Filament::getTenant();
                                $isTaxEnabled = $store?->tax_enabled ?? false;

                                $columns = [
                                    Repeater\TableColumn::make('Product')->width($isTaxEnabled ? '30%' : '40%'),
                                    Repeater\TableColumn::make('Qty')->width('10%'),
                                    Repeater\TableColumn::make('Unit')->width('15%'),
                                    Repeater\TableColumn::make('Price')->width('10%'),
                                ];

                                if ($isTaxEnabled) {
                                    $columns[] = Repeater\TableColumn::make('Tax %')->width('10%');
                                }

                                $columns[] = Repeater\TableColumn::make('Cost')->width('10%');
                                $columns[] = Repeater\TableColumn::make('Barcode')->width('15%');

                                return $columns;
                            })
                            ->schema(function (): array {
                                $store = Filament::getTenant();
                                $isTaxEnabled = $store?->tax_enabled ?? false;

                                $schema = [
                                    Hidden::make('variation_id'),
                                    Hidden::make('requested_tax_percentage'),
                                    Hidden::make('requested_tax_amount'),
                                    Hidden::make('requested_supplier_percentage'),
                                    Hidden::make('requested_supplier_is_percentage'),
                                    Hidden::make('requested_supplier_price'),
                                    Hidden::make('received_tax_percentage'),
                                    Hidden::make('received_tax_amount'),
                                    Hidden::make('received_supplier_is_percentage'),
                                    Hidden::make('received_supplier_price'),
                                    Hidden::make('received_unit_price_base')
                                        ->dehydrated(false)
                                        ->afterStateHydrated(function ($state, $set, $get): void {
                                            if ($state !== null) {
                                                return;
                                            }

                                            $variationId = $get('variation_id');
                                            $variation = $variationId ? Variation::findCached($variationId) : null;
                                            $variationUnit = $variation?->unit;
                                            $selectedUnitId = $get('received_unit_id') ?? $get('requested_unit_id');
                                            $selectedUnit = $selectedUnitId ? Unit::find($selectedUnitId) : null;
                                            $price = $get('received_unit_price') ?? $get('requested_unit_price');

                                            if ($variationUnit && $selectedUnit && is_numeric($price) && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                                                $basePrice = Unit::convertPrice((float) $price, $selectedUnit, $variationUnit);
                                                $set('received_unit_price_base', $basePrice);

                                                return;
                                            }

                                            if ($variation) {
                                                $set('received_unit_price_base', $variation->price);
                                            }
                                        }),
                                    TextEntry::make('description')
                                        ->formatStateUsing(function ($state, $get) {
                                            // If description is stored in database, use it
                                            if ($state) {
                                                return $state;
                                            }

                                            // Fallback: generate from variation if description is missing
                                            $variationId = $get('variation_id');
                                            if ($variationId) {
                                                $variation = Variation::findCached($variationId);

                                                return $variation ? $variation->sku.' - '.$variation->description : '';
                                            }

                                            return '';
                                        }),

                                    TextInput::make('received_quantity')
                                        ->default(fn ($get) => $get('requested_quantity'))
                                        ->inputMode('decimal')
                                        ->rule('regex:/^\\d+(\\.\\d{1,6})?$/')
                                        ->live(onBlur: true)
                                        ->extraInputAttributes([
                                            'class' => 'text-xs py-0.5 px-1.5 h-7',
                                            'data-sale-item-input' => 'true',
                                            'x-on:focus' => '$event.target.select && $event.target.select()',
                                        ])
                                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                                            $rounded = $state;
                                            if (is_numeric($state)) {
                                                $rounded = round((float) $state, 6);
                                                $set('received_quantity', $rounded);
                                            }

                                            self::refreshRepeater($get, $set);
                                        }),
                                    Select::make('received_unit_id')
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
                                            $receivedUnitId = $get('received_unit_id');
                                            if ($receivedUnitId) {
                                                return $receivedUnitId;
                                            }

                                            $requestedUnitId = $get('requested_unit_id');
                                            if ($requestedUnitId) {
                                                return $requestedUnitId;
                                            }

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

                                            $basePrice = $get('received_unit_price_base');
                                            if (! is_numeric($basePrice)) {
                                                $basePrice = $variation->price;
                                                $set('received_unit_price_base', $basePrice);
                                            }

                                            $store = Filament::getTenant();
                                            $currency = $store?->currency;
                                            $decimalPlaces = $currency->decimal_places ?? 2;
                                            $displayPrice = Unit::convertPrice((float) $basePrice, $variationUnit, $selectedUnit);
                                            $roundedDisplayPrice = round($displayPrice, $decimalPlaces);
                                            $set('received_unit_price', $roundedDisplayPrice);

                                            $store = Filament::getTenant();
                                            $variationId = $get('variation_id');
                                            $variation = $variationId ? Variation::findCached($variationId) : null;

                                            // Prefer existing received tax, then requested tax, then from stock
                                            $existingReceivedTax = $get('received_tax_percentage');
                                            $requestedTax = $get('requested_tax_percentage');
                                            $barcode = $variation?->stocks()->latest('id')->first();
                                            $stockTaxPercent = $store?->getEffectiveTaxPercentage($barcode) ?? 0;

                                            // Use received tax if exists, otherwise requested tax, otherwise stock tax
                                            $taxPercent = 0;
                                            if (is_numeric($existingReceivedTax) && (float) $existingReceivedTax > 0) {
                                                $taxPercent = (float) $existingReceivedTax;
                                            } elseif (is_numeric($requestedTax) && (float) $requestedTax > 0) {
                                                $taxPercent = (float) $requestedTax;
                                            } elseif ($stockTaxPercent > 0) {
                                                $taxPercent = $stockTaxPercent;
                                            }

                                            if ($taxPercent > 0 && $store?->tax_enabled) {
                                                $set('received_tax_percentage', $taxPercent);
                                                $taxAmount = round($roundedDisplayPrice * ($taxPercent / 100), $decimalPlaces);
                                                $set('received_tax_amount', $taxAmount);
                                                $formattedValue = rtrim(rtrim(number_format((float) $taxPercent, 6, '.', ''), '0'), '.');
                                                $set('received_tax_input', $formattedValue.'%');
                                            } else {
                                                $set('received_tax_percentage', 0);
                                                $set('received_tax_amount', 0);
                                                $set('received_tax_input', null);
                                            }

                                            $supplierPercentage = $get('received_supplier_percentage');
                                            if (! is_numeric($supplierPercentage)) {
                                                $supplierPercentage = $get('requested_supplier_percentage');
                                            }
                                            $supplierPercentage = (float) ($supplierPercentage ?? 0);
                                            $supplierPrice = null;
                                            if ($supplierPercentage > 0) {
                                                $supplierPrice = $roundedDisplayPrice - ($roundedDisplayPrice * ($supplierPercentage / 100));
                                                $set('received_supplier_price', round($supplierPrice, $decimalPlaces));
                                            } elseif (filled($get('received_supplier_input'))) {
                                                $supplier = self::syncSupplierFields($get, $set, $get('received_supplier_input'));
                                                $supplierPrice = $supplier['supplier_price'] ?? null;
                                            }

                                            self::refreshRepeater($get, $set);
                                        }),

                                    TextInput::make('received_unit_price')
                                        ->default(fn ($get) => $get('requested_unit_price') ?? 0)
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

                                            $unitPrice = is_numeric($state) ? round((float) $state, $decimalPlaces) : 0;
                                            if (is_numeric($state) && (float) $state !== $unitPrice) {
                                                $set('received_unit_price', $unitPrice);
                                            }
                                            $variationId = $get('variation_id');
                                            $variation = $variationId ? Variation::findCached($variationId) : null;
                                            $variationUnit = $variation?->unit;
                                            $selectedUnitId = $get('received_unit_id');
                                            $selectedUnit = $selectedUnitId ? Unit::find($selectedUnitId) : null;
                                            if ($variationUnit && $selectedUnit && $variationUnit->dimension_id === $selectedUnit->dimension_id) {
                                                $basePrice = Unit::convertPrice($unitPrice, $selectedUnit, $variationUnit);
                                                $set('received_unit_price_base', $basePrice);
                                            }
                                            $store = Filament::getTenant();
                                            $variationId = $get('variation_id');
                                            $variation = $variationId ? Variation::findCached($variationId) : null;

                                            // Preserve tax percentage when price changes
                                            $taxInput = $get('received_tax_input');
                                            $taxPercentage = $get('received_tax_percentage');

                                            // Remove % from taxInput if present for calculation
                                            $taxInputNumeric = is_string($taxInput) ? str_replace('%', '', trim($taxInput)) : $taxInput;

                                            if (filled($taxInputNumeric) && is_numeric($taxInputNumeric) && $store?->tax_enabled) {
                                                // Re-sync tax fields with existing percentage to recalculate amount based on new price
                                                $taxPercentValue = (float) $taxInputNumeric;
                                                self::syncReceivedTaxFields($get, $set, $taxPercentValue);
                                                // Format with % for display
                                                $formattedValue = rtrim(rtrim(number_format($taxPercentValue, 6, '.', ''), '0'), '.');
                                                $set('received_tax_input', $formattedValue.'%');
                                            } elseif (is_numeric($taxPercentage) && (float) $taxPercentage > 0 && $store?->tax_enabled) {
                                                // Recalculate amount for existing percentage
                                                $taxAmount = round($unitPrice * ((float) $taxPercentage / 100), $decimalPlaces);
                                                $set('received_tax_amount', $taxAmount);
                                                // Format with % for display
                                                $formattedValue = rtrim(rtrim(number_format((float) $taxPercentage, 6, '.', ''), '0'), '.');
                                                $set('received_tax_input', $formattedValue.'%');
                                            } else {
                                                // Initialize from requested tax first, then from stock if no tax input exists
                                                $requestedTaxPercent = $get('requested_tax_percentage');
                                                $barcode = $variation?->stocks()->latest('id')->first();
                                                $stockTaxPercent = $store?->getEffectiveTaxPercentage($barcode) ?? 0;

                                                // Prefer requested tax, fallback to stock tax
                                                $taxPercent = 0;
                                                if (is_numeric($requestedTaxPercent) && (float) $requestedTaxPercent > 0) {
                                                    $taxPercent = (float) $requestedTaxPercent;
                                                } elseif ($stockTaxPercent > 0) {
                                                    $taxPercent = $stockTaxPercent;
                                                }

                                                if ($taxPercent > 0 && $store?->tax_enabled) {
                                                    $set('received_tax_percentage', $taxPercent);
                                                    $taxAmount = round($unitPrice * ($taxPercent / 100), $decimalPlaces);
                                                    $set('received_tax_amount', $taxAmount);
                                                    $formattedValue = rtrim(rtrim(number_format((float) $taxPercent, 6, '.', ''), '0'), '.');
                                                    $set('received_tax_input', $formattedValue.'%');
                                                } else {
                                                    $set('received_tax_percentage', 0);
                                                    $set('received_tax_amount', 0);
                                                    $set('received_tax_input', null);
                                                }
                                            }

                                            if (filled($get('received_supplier_input'))) {
                                                self::syncSupplierFields($get, $set, $get('received_supplier_input'));
                                            } else {
                                                $supplierPercent = $get('received_supplier_percentage');
                                                if (! is_numeric($supplierPercent)) {
                                                    $supplierPercent = $get('requested_supplier_percentage');
                                                }
                                                $supplierPercent = (float) ($supplierPercent ?? 0);
                                                $supplierPrice = $unitPrice - ($unitPrice * ($supplierPercent / 100));
                                                $set('received_supplier_price', round($supplierPrice, $decimalPlaces));
                                            }

                                            self::refreshRepeater($get, $set);
                                        }),
                                ];

                                // Conditionally include tax field only when tax is enabled
                                if ($isTaxEnabled) {
                                    $schema[] = TextInput::make('received_tax_input')
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
                                                        $set('received_tax_input', $formattedValue.'%');
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
                                                $set('received_tax_input', $formattedValue.'%');
                                            }
                                        })
                                        ->afterStateUpdated(function (mixed $state, $set, $get): void {
                                            $rawInput = is_string($state) ? trim($state) : $state;

                                            // Handle empty/null input
                                            if ($rawInput === null || $rawInput === '') {
                                                $set('received_tax_percentage', 0);
                                                $set('received_tax_amount', 0);

                                                // Recalculate summary
                                                $items = $get('../../purchaseOrderProducts') ?? [];
                                                if (! empty($items)) {
                                                    self::recalcSummaryFromItems($items, $set);
                                                }

                                                self::refreshRepeater($get, $set);

                                                return;
                                            }

                                            // Remove % if present for calculation
                                            $numericInput = is_string($rawInput) ? str_replace('%', '', $rawInput) : $rawInput;
                                            $numericValue = is_numeric($numericInput) ? (float) $numericInput : null;

                                            // Handle non-numeric input
                                            if ($numericValue === null) {
                                                $set('received_tax_input', '');
                                                $set('received_tax_percentage', 0);
                                                $set('received_tax_amount', 0);

                                                // Recalculate summary
                                                $items = $get('../../purchaseOrderProducts') ?? [];
                                                if (! empty($items)) {
                                                    self::recalcSummaryFromItems($items, $set);
                                                }

                                                self::refreshRepeater($get, $set);

                                                return;
                                            }

                                            // Validate percentage range (0-999.999999)
                                            if ($numericValue < 0 || $numericValue > 999.999999) {
                                                $set('received_tax_percentage', 0);
                                                $set('received_tax_amount', 0);

                                                // Recalculate summary
                                                $items = $get('../../purchaseOrderProducts') ?? [];
                                                if (! empty($items)) {
                                                    self::recalcSummaryFromItems($items, $set);
                                                }

                                                self::refreshRepeater($get, $set);

                                                return;
                                            }

                                            $store = Filament::getTenant();
                                            $currency = $store?->currency;
                                            $decimalPlaces = $currency->decimal_places ?? 2;

                                            $roundedValue = round($numericValue, 6);
                                            $formattedValue = rtrim(rtrim(number_format($roundedValue, 6, '.', ''), '0'), '.');

                                            // Sync tax fields - always percentage
                                            $taxResult = self::syncReceivedTaxFields($get, $set, $roundedValue);

                                            // Update input with formatted value + % for visibility
                                            $set('received_tax_input', $formattedValue.'%');

                                            // Get items and update current item with new tax values before recalculating
                                            $items = $get('../../purchaseOrderProducts') ?? [];
                                            if (! empty($items)) {
                                                // Find current item by variation_id and update it with new tax values
                                                $currentVariationId = $get('variation_id');
                                                $newTaxPercentage = $taxResult['tax_percentage'] ?? 0;
                                                $newTaxAmount = $taxResult['tax_amount'] ?? 0;

                                                foreach ($items as &$item) {
                                                    if (($item['variation_id'] ?? null) === $currentVariationId) {
                                                        $item['received_tax_percentage'] = $newTaxPercentage;
                                                        $item['received_tax_amount'] = $newTaxAmount;
                                                        $item['received_tax_input'] = $formattedValue.'%';
                                                        break;
                                                    }
                                                }
                                                unset($item);

                                                // Recalculate summary with updated items
                                                self::recalcSummaryFromItems($items, $set);
                                            }

                                            self::refreshRepeater($get, $set);
                                        });
                                }

                                // Always include supplier field
                                $schema[] = TextInput::make('received_supplier_input')
                                    ->label('Supplier Price')
                                    ->inputMode('decimal')
                                    ->placeholder('30% or 70')
                                    ->rule('regex:/^\\d+(\\.\\d{1,6})?%?$/')
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function ($state, $set, $get): void {
                                        if ($state !== null && $state !== '') {
                                            return;
                                        }

                                        $store = Filament::getTenant();
                                        $currency = $store?->currency;
                                        $decimalPlaces = $currency->decimal_places ?? 2;

                                        $percentage = $get('received_supplier_percentage') ?? $get('requested_supplier_percentage');
                                        $price = $get('received_supplier_price') ?? $get('requested_supplier_price');
                                        $inputIsPercent = $get('received_supplier_is_percentage')
                                            ?? $get('requested_supplier_is_percentage');

                                        if ($inputIsPercent === true && is_numeric($percentage)) {
                                            $set('received_supplier_input', rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%');

                                            return;
                                        }

                                        if ($inputIsPercent === false && is_numeric($price)) {
                                            $set('received_supplier_input', rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.'));

                                            return;
                                        }

                                        if (is_numeric($percentage)) {
                                            $set('received_supplier_input', rtrim(rtrim(number_format((float) $percentage, 6, '.', ''), '0'), '.').'%');

                                            return;
                                        }

                                        if (is_numeric($price)) {
                                            $set('received_supplier_input', rtrim(rtrim(number_format((float) $price, $decimalPlaces, '.', ''), '0'), '.'));
                                        }
                                    })
                                    ->live(onBlur: true)
                                    ->extraInputAttributes([
                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                        'data-sale-item-input' => 'true',
                                        'x-on:focus' => '$event.target.select && $event.target.select()',
                                    ])
                                    ->afterStateUpdated(function (mixed $state, $set, $get): void {
                                        $rawInput = is_string($state) ? trim($state) : $state;

                                        if ($rawInput === null || $rawInput === '') {
                                            $set('received_supplier_percentage', null);
                                            $set('received_supplier_price', null);
                                            $set('received_supplier_is_percentage', null);

                                            return;
                                        }

                                        $isPercent = is_string($rawInput) && str_ends_with($rawInput, '%');
                                        $numericValue = is_numeric(str_replace('%', '', (string) $rawInput))
                                            ? (float) str_replace('%', '', (string) $rawInput)
                                            : null;

                                        if ($numericValue === null) {
                                            $set('received_supplier_input', '');
                                            $set('received_supplier_percentage', null);
                                            $set('received_supplier_price', null);
                                            $set('received_supplier_is_percentage', null);

                                            return;
                                        }

                                        if ($isPercent && ($numericValue < 0 || $numericValue > 999.999999)) {
                                            $set('received_supplier_input', '');
                                            $set('received_supplier_percentage', null);
                                            $set('received_supplier_price', null);
                                            $set('received_supplier_is_percentage', null);

                                            return;
                                        }

                                        $store = Filament::getTenant();
                                        $currency = $store?->currency;
                                        $decimalPlaces = $currency->decimal_places ?? 2;

                                        $roundedValue = $isPercent ? round($numericValue, 6) : round($numericValue, $decimalPlaces);
                                        $set(
                                            'received_supplier_input',
                                            $isPercent
                                                ? rtrim(rtrim(number_format($roundedValue, 6, '.', ''), '0'), '.').'%'
                                                : rtrim(rtrim(number_format($roundedValue, $decimalPlaces, '.', ''), '0'), '.')
                                        );

                                        self::syncSupplierFields($get, $set, $state);

                                        self::refreshRepeater($get, $set);
                                    });

                                // Always include barcode field
                                $schema[] = TextInput::make('barcode')
                                    ->label('Barcode')
                                    ->maxLength(255)
                                    ->placeholder('Scan or enter barcode')
                                    ->live(onBlur: true)
                                    ->dehydrated()
                                    ->extraInputAttributes([
                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                        'data-sale-item-input' => 'true',
                                        'x-on:focus' => '$event.target.select && $event.target.select()',
                                    ])
                                    ->suffixAction(
                                        Action::make('generateBarcode')
                                            ->label('Generate')
                                            ->icon(Heroicon::OutlinedBolt)
                                            ->action(fn ($set) => $set('barcode', self::generateEan13()))
                                    )
                                    ->afterStateUpdated(function ($state, $set): void {
                                        if (is_string($state)) {
                                            $set('barcode', trim($state));
                                        }
                                    });

                                return $schema;
                            })
                            ->addable(false)
                            ->reorderable(false)
                            ->deletable(false)
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

                // Summary Section
                Section::make('Receive Summary')
                    ->description('Summary of received items')
                    ->inlineLabel()
                    ->afterHeader([
                    ])
                    ->schema([
                        TextInput::make('total_received_quantity')
                            ->label('Total Items')
                            ->disabled()
                            ->numeric()
                            ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((int) $state) : '0')
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('total_received_tax_amount')
                            ->label('Total Tax')
                            ->disabled()
                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                            ->numeric()
                            ->visible(fn () => Filament::getTenant()?->tax_enabled ?? false)
                            ->columnSpanFull(),
                        TextInput::make('total_received_unit_price')
                            ->label('Total Received Unit Price')
                            ->disabled()
                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                            ->numeric()
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('total_received_supplier_price')
                            ->label('Total Received Supplier Cost')
                            ->disabled()
                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                            ->numeric()
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->collapsible(false)
                    ->compact()
                    ->columnSpanFull(),

            ]);
    }

    /**
     * Reset the summary fields
     */
    private static function resetSummary(callable $set): void
    {
        $set('total_received_quantity', 0);
        $set('total_received_tax_amount', 0);
        $set('total_received_unit_price', 0);
        $set('total_received_supplier_price', 0);
    }

    /**
     * Recalculate the summary totals when the repeater changes.
     */
    private static function recalcSummary(callable $get, callable $set): void
    {
        $items = $get('purchaseOrderProducts') ?? [];
        self::recalcSummaryFromItems($items, $set);
    }

    private static function syncReceivedTaxFields(callable $get, callable $set, float $percentage): array
    {
        $unitPrice = (float) $get('received_unit_price');
        $store = Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;

        if ($percentage < 0 || $percentage > 999.999999 || ! $store?->tax_enabled) {
            $set('received_tax_percentage', 0);
            $set('received_tax_amount', 0);

            return ['tax_percentage' => 0, 'tax_amount' => 0];
        }

        $roundedPercentage = round($percentage, 6);
        $set('received_tax_percentage', $roundedPercentage);

        $taxAmount = null;
        if ($unitPrice > 0) {
            $taxAmount = round($unitPrice * ($roundedPercentage / 100), $decimalPlaces);
            $set('received_tax_amount', $taxAmount);
        } else {
            $set('received_tax_amount', 0);
        }

        return [
            'tax_percentage' => $roundedPercentage,
            'tax_amount' => $taxAmount ?? 0,
        ];
    }

    private static function syncSupplierFields(callable $get, callable $set, mixed $stateOverride = null): array
    {
        $rawInput = $stateOverride ?? $get('received_supplier_input');
        $rawInput = is_string($rawInput) ? trim($rawInput) : $rawInput;
        $unitPrice = (float) $get('received_unit_price');
        $store = Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;

        if ($rawInput === null || $rawInput === '') {
            $set('received_supplier_is_percentage', null);

            return ['supplier_price' => null, 'supplier_percentage' => null];
        }

        $isPercent = is_string($rawInput) && str_ends_with($rawInput, '%');
        $numericValue = (float) str_replace('%', '', (string) $rawInput);

        if ($isPercent) {
            if ($numericValue < 0 || $numericValue > 999.999999) {
                $set('received_supplier_input', '');
                $set('received_supplier_percentage', null);
                $set('received_supplier_price', null);
                $set('received_supplier_is_percentage', null);

                return ['supplier_price' => null, 'supplier_percentage' => null];
            }

            $percentage = round($numericValue, 6);
            $set('received_supplier_percentage', $percentage);
            $set('received_supplier_is_percentage', true);

            $supplierPrice = null;
            if ($unitPrice > 0) {
                $supplierPrice = $unitPrice - ($unitPrice * ($percentage / 100));
                $set('received_supplier_price', round($supplierPrice, $decimalPlaces));
            }

            return [
                'supplier_price' => $supplierPrice !== null ? round($supplierPrice, $decimalPlaces) : null,
                'supplier_percentage' => $percentage,
            ];
        }

        $price = round($numericValue, $decimalPlaces);
        $set('received_supplier_price', $price);
        $set('received_supplier_is_percentage', false);

        $percentage = null;
        if ($unitPrice > 0) {
            $percentage = (($unitPrice - $price) / $unitPrice) * 100;
            $set('received_supplier_percentage', round($percentage, 6));
        }

        return [
            'supplier_price' => $price,
            'supplier_percentage' => $percentage !== null ? round($percentage, 6) : null,
        ];
    }

    private static function refreshRepeater(callable $get, callable $set): void
    {
        $path = '../../purchaseOrderProducts';
        $set($path, $get($path) ?? [], shouldCallUpdatedHooks: true);
    }

    private static function recalcSummaryFromItems(
        array $items,
        callable $set,
        string $statePathPrefix = ''
    ): void {
        $store = Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;

        if (empty($items)) {
            $set($statePathPrefix.'total_received_quantity', 0);
            $set($statePathPrefix.'total_received_tax_amount', 0);
            $set($statePathPrefix.'total_received_unit_price', 0);
            $set($statePathPrefix.'total_received_supplier_price', 0);

            return;
        }

        // Count items that have received quantity (not null and > 0)
        $itemsCount = collect($items)->filter(fn ($item) => isset($item['received_quantity']) && (float) ($item['received_quantity'] ?? 0) > 0)->count();

        $sumQty = 0;
        $sumTax = 0;
        $sumUnit = 0;
        $sumSupplier = 0;

        $store = Filament::getTenant();

        foreach ($items as $item) {
            $qty = (float) ($item['received_quantity'] ?? 0);
            // PriceCast already divides by 100 when loading from DB, so unitPrice is already in display format
            $unitPrice = (float) ($item['received_unit_price'] ?? 0);
            $taxPercentage = (float) ($item['received_tax_percentage'] ?? 0);
            $taxAmount = (float) ($item['received_tax_amount'] ?? 0);

            // Only count tax if tax is enabled
            if ($store?->tax_enabled && $taxAmount > 0) {
                $sumTax += $qty * $taxAmount;
            }

            $supplierPercentage = $item['received_supplier_percentage']
                ?? $item['requested_supplier_percentage']
                ?? null;
            $supplierPriceValue = $item['received_supplier_price']
                ?? $item['requested_supplier_price']
                ?? null;
            $inputIsPercent = $item['received_supplier_is_percentage']
                ?? $item['requested_supplier_is_percentage']
                ?? null;

            $supplierPrice = 0.0;
            if (is_numeric($supplierPriceValue)) {
                $supplierPrice = (float) $supplierPriceValue;
            } elseif ($inputIsPercent === true && is_numeric($supplierPercentage) && $unitPrice > 0) {
                $supplierPrice = round($unitPrice - ($unitPrice * ((float) $supplierPercentage / 100)), $decimalPlaces);
            } elseif (is_numeric($supplierPercentage) && $unitPrice > 0) {
                $supplierPrice = round($unitPrice - ($unitPrice * ((float) $supplierPercentage / 100)), $decimalPlaces);
            }

            $sumQty += $qty;
            $sumUnit += $qty * $unitPrice;
            $sumSupplier += $qty * $supplierPrice;
        }

        $set($statePathPrefix.'total_received_quantity', $itemsCount);
        if ($store?->tax_enabled) {
            $set($statePathPrefix.'total_received_tax_amount', round($sumTax, $decimalPlaces));
        } else {
            $set($statePathPrefix.'total_received_tax_amount', 0);
        }
        $set($statePathPrefix.'total_received_unit_price', round($sumUnit, $decimalPlaces));
        $set($statePathPrefix.'total_received_supplier_price', round($sumSupplier, $decimalPlaces));
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
}
