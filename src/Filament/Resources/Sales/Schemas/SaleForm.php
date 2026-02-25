<?php

namespace SmartTill\Core\Filament\Resources\Sales\Schemas;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Enums\SalePaymentMethod;
use SmartTill\Core\Enums\SalePaymentStatus;
use SmartTill\Core\Enums\SaleStatus;
use SmartTill\Core\Filament\Forms\Components\ProductSearchInput;
use SmartTill\Core\Filament\Resources\Customers\Schemas\CustomerForm;
use SmartTill\Core\Filament\Resources\Sales\SaleResource;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\SalePreparableItem;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\Variation;
use SmartTill\Core\Services\CoreStoreSettingsService;
use SmartTill\Core\Services\SaleTransactionService;
use Throwable;

class SaleForm
{
    private static function getPaymentStatusValue($get): ?string
    {
        $value = $get('payment_status');
        if ($value instanceof SalePaymentStatus) {
            return $value->value;
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        Grid::make()
                            ->schema([
                                ProductSearchInput::make('product_search')
                                    ->hiddenLabel()
                                    ->placeholder('Search by Barcode / SKU / Description')
                                    ->mode('barcodes')
                                    ->allowCustom()
                                    ->reactive()
                                    ->columnSpanFull()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        // This will be triggered when a product is selected via Livewire event
                                        // The state will contain the barcode ID
                                        if (! $state) {
                                            return;
                                        }

                                        if (is_array($state) && ($state['type'] ?? null) === 'custom') {
                                            $description = (string) ($state['description'] ?? '');
                                            $set('skip_summary_recalc', true);
                                            SaleForm::upsertCustomProduct($get, $set, $description);
                                            $set('skip_summary_recalc', false);
                                            $set('product_search', null);

                                            return;
                                        }

                                        $barcode = Stock::query()
                                            ->with(['variation.product'])
                                            ->find($state);
                                        if (! $barcode || ! $barcode->variation) {
                                            Notification::make()
                                                ->title('Product not found')
                                                ->danger()
                                                ->duration(1000)
                                                ->send();
                                            $set('product_search', null);

                                            return;
                                        }

                                        $variation = $barcode->variation;
                                        $product = $variation->product;

                                        // Check if product is preparable
                                        if ($product && $product->is_preparable) {
                                            $set('skip_summary_recalc', true);
                                            SaleForm::upsertPreparableProduct($get, $set, $barcode);
                                            $set('skip_summary_recalc', false);
                                        } else {
                                            $set('skip_summary_recalc', true);
                                            SaleForm::upsertProduct($get, $set, $barcode);
                                            $set('skip_summary_recalc', false);
                                        }

                                        $set('product_search', null);
                                    }),
                                Hidden::make('sale_id'),
                                Hidden::make('skip_summary_recalc')
                                    ->default(false)
                                    ->dehydrated(false),
                                Repeater::make('variations')
                                    ->hiddenLabel()
                                    ->default([])
                                    ->deletable()
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        if ($get('skip_summary_recalc')) {
                                            $set('skip_summary_recalc', false);

                                            return;
                                        }
                                        SaleForm::recalcSummary($get, $set);
                                    })
                                    ->table([
                                        Repeater\TableColumn::make('Description')->width('45%'),
                                        Repeater\TableColumn::make('Qty')->width('10%'),
                                        Repeater\TableColumn::make('Price')->width('15%'),
                                        Repeater\TableColumn::make('Disc.')->width('15%'),
                                        Repeater\TableColumn::make('Total')->width('15%'),
                                    ])
                                    ->schema([
                                        Hidden::make('variation_id'),
                                        Hidden::make('stock_id'),
                                        Hidden::make('unit_discount'), // Per-unit discount amount
                                        Hidden::make('discount_type'), // 'percentage' or 'flat'
                                        Hidden::make('discount_percentage'), // Store percentage value if discount_type is 'percentage'
                                        Hidden::make('discount_amount'), // Store the calculated discount amount
                                        TextEntry::make('description')
                                            ->hiddenLabel(),
                                        TextInput::make('quantity')
                                            ->rule('not_in:0')
                                            ->numeric()
                                            ->placeholder('Qty')
                                            ->live(onBlur: true)
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'data-sale-item-input' => 'true',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                // Round to 6 decimal places, allow negative quantities
                                                $quantity = round((float) ($state ?: 1), 6);
                                                // Ensure quantity is not zero
                                                if ($quantity == 0) {
                                                    $quantity = $quantity > 0 ? 1 : -1;
                                                }
                                                $set('quantity', $quantity);

                                                // If discount type is percentage, recalculate the discount amount
                                                if ($get('discount_type') === 'percentage') {
                                                    $unitPrice = (float) ($get('unit_price') ?: 0);
                                                    $percentage = (float) ($get('discount_percentage') ?? 0);
                                                    $subtotalBeforeDiscount = $unitPrice * abs($quantity);

                                                    // Recalculate discount amount from percentage
                                                    // Use absolute value for calculation, then apply sign
                                                    $discountAmount = ($subtotalBeforeDiscount * abs($percentage)) / 100;
                                                    // Apply negative sign if percentage is negative or quantity is negative
                                                    if ($percentage < 0 || $quantity < 0) {
                                                        $discountAmount = $discountAmount * -1;
                                                    }
                                                    $discountAmount = round($discountAmount, 2);

                                                    // Always preserve percentage format in display
                                                    $set('discount', self::formatPercentage($percentage));

                                                    // Update unit_discount - preserve sign when quantity is negative
                                                    if ($quantity != 0) {
                                                        $unitDiscount = $discountAmount / $quantity;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));
                                                    $set('discount_amount', $discountAmount);
                                                } else {
                                                    // For flat discount, automatically adjust sign based on quantity
                                                    $currentDiscount = (float) ($get('discount') ?? 0);
                                                    if ($quantity < 0 && $currentDiscount > 0) {
                                                        // If quantity becomes negative and discount is positive, make discount negative
                                                        $set('discount', self::formatNumberForState($currentDiscount * -1));
                                                    } elseif ($quantity >= 0 && $currentDiscount < 0) {
                                                        // If quantity becomes positive and discount is negative, make discount positive
                                                        $set('discount', self::formatNumberForState(abs($currentDiscount)));
                                                    }
                                                }

                                                SaleForm::recalcLine($get, $set);
                                            }),
                                        TextInput::make('unit_price')
                                            ->numeric()
                                            ->inputMode('decimal')
                                            ->step('0.01')
                                            ->reactive()
                                            ->live(onBlur: true)
                                            ->dehydrated()
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'data-price-input' => 'true',
                                                'data-sale-item-input' => 'true',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->extraAttributes([
                                                'class' => 'price-field-wrapper',
                                                'x-data' => '{}',
                                                'x-init' => "\$nextTick(() => {
                                                    const styleId = 'price-helper-style';
                                                    if (!document.getElementById(styleId)) {
                                                        const style = document.createElement('style');
                                                        style.id = styleId;
                                                        style.textContent = '.price-field-wrapper .fi-sc-text, .fi-input-wrapper:has(input[data-price-input]) .fi-sc-text { font-size: 0.3rem !important; line-height: 0.35rem !important; opacity: 0.5 !important; margin-top: 0.0625rem !important; color: rgb(107 114 128) !important; }';
                                                        document.head.appendChild(style);
                                                    }
                                                })",
                                            ])
                                            ->helperText(function ($get) {
                                                $unitPrice = (float) ($get('unit_price') ?? 0);
                                                $unitDiscount = (float) ($get('unit_discount') ?? 0);

                                                // Only show helper text if there's a discount applied
                                                if ($unitDiscount != 0 && $unitPrice > 0) {
                                                    // Calculate price after discount
                                                    $priceAfterDiscount = $unitPrice - abs($unitDiscount);

                                                    if ($priceAfterDiscount > 0) {
                                                        // Get smallest integer (floor)
                                                        $priceAfterDiscountInteger = (int) floor($priceAfterDiscount);

                                                        if ($priceAfterDiscountInteger > 0) {
                                                            return "1x={$priceAfterDiscountInteger}";
                                                        }
                                                    }
                                                }

                                                return null;
                                            })
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                $unitPrice = round((float) $state, 2);
                                                if ((float) $state !== $unitPrice) {
                                                    $set('unit_price', $unitPrice);
                                                }

                                                // If there's a percentage discount, recalculate discount_amount based on new unit_price
                                                $discountType = $get('discount_type');
                                                if ($discountType === 'percentage') {
                                                    $quantity = (float) ($get('quantity') ?: 1);
                                                    $subtotalBeforeDiscount = $unitPrice * abs($quantity);
                                                    $discountPercentage = (float) ($get('discount_percentage') ?? 0);

                                                    // Recalculate discount amount from percentage
                                                    // Recalculate discount amount from percentage
                                                    // Use absolute value for calculation, then apply sign
                                                    $discountAmount = ($subtotalBeforeDiscount * abs($discountPercentage)) / 100;
                                                    // Apply negative sign if percentage is negative or quantity is negative
                                                    if ($discountPercentage < 0 || $quantity < 0) {
                                                        $discountAmount = $discountAmount * -1;
                                                    }
                                                    $discountAmount = round($discountAmount, 2);

                                                    // Update discount_amount and unit_discount
                                                    $set('discount_amount', $discountAmount);
                                                    if ($quantity != 0) {
                                                        $unitDiscount = $discountAmount / $quantity;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));

                                                    // Always preserve percentage format in display
                                                    $set('discount', self::formatPercentage($discountPercentage));
                                                }

                                                SaleForm::recalcLine($get, $set);
                                            }),
                                        TextInput::make('discount')
                                            ->placeholder('10 or 10%')
                                            ->reactive()
                                            ->live(onBlur: true)
                                            ->afterStateHydrated(function ($state, $set, $get) {
                                                // When loading existing data, format percentage discounts correctly
                                                $discountType = $get('discount_type');
                                                if ($discountType === 'percentage') {
                                                    $discountPercentage = (float) ($get('discount_percentage') ?? 0);
                                                    if ($discountPercentage != 0) {
                                                        $set('discount', self::formatPercentage($discountPercentage));
                                                    }
                                                }
                                            })
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'data-sale-item-input' => 'true',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->helperText(function ($get) {
                                                $quantity = (float) ($get('quantity') ?? 1);
                                                $discount = $get('discount');

                                                // Don't show helper text for percentage discounts
                                                if (is_string($discount) && str_contains($discount, '%')) {
                                                    return null;
                                                }

                                                // Only show hint if quantity > 1 and there's a flat discount
                                                if (abs($quantity) > 1 && $discount !== null && $discount !== '') {
                                                    // Flat discount - use the discount value directly
                                                    $discountAmount = (float) $discount;

                                                    if ($discountAmount != 0) {
                                                        // Calculate per-unit discount
                                                        $unitDiscount = abs($discountAmount) / abs($quantity);
                                                        // Get smallest integer (floor)
                                                        $unitDiscountInteger = (int) floor($unitDiscount);

                                                        if ($unitDiscountInteger > 0) {
                                                            return "1x={$unitDiscountInteger}";
                                                        }
                                                    }
                                                }

                                                return null;
                                            })
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                $quantity = (float) ($get('quantity') ?: 1);
                                                $unitPrice = (float) ($get('unit_price') ?: 0);
                                                $subtotalBeforeDiscount = $unitPrice * abs($quantity);

                                                // Check if state contains percentage symbol
                                                if (is_string($state) && str_contains($state, '%')) {
                                                    // Extract the percentage value
                                                    $rawPercentage = str_replace('%', '', trim($state));

                                                    // Validate format - must be numeric and within reasonable range
                                                    if (! is_numeric($rawPercentage)) {
                                                        $set('discount', '');
                                                        $set('discount_type', null);
                                                        $set('discount_percentage', null);

                                                        return;
                                                    }

                                                    $percentage = (float) $rawPercentage;

                                                    // Validate percentage range based on quantity
                                                    // For returns (negative quantity), allow -999.999999 to 999.999999
                                                    // For normal sales (positive quantity), allow 0 to 999.999999
                                                    if ($quantity < 0) {
                                                        // Return case: allow negative percentages (-999.999999 to 999.999999)
                                                        if ($percentage < -999.999999) {
                                                            $percentage = -999.999999;
                                                        } elseif ($percentage > 999.999999) {
                                                            $percentage = 999.999999;
                                                        }
                                                    } else {
                                                        // Normal sale: allow 0 to 999.999999
                                                        if ($percentage < 0) {
                                                            $percentage = 0;
                                                        } elseif ($percentage > 999.999999) {
                                                            $percentage = 999.999999;
                                                        }
                                                    }

                                                    $percentage = round($percentage, 6); // Round to 6 decimals

                                                    // Calculate discount amount from percentage
                                                    // Use absolute value for calculation, then apply sign
                                                    $discountAmount = ($subtotalBeforeDiscount * abs($percentage)) / 100;
                                                    // Apply negative sign if percentage is negative or quantity is negative
                                                    if ($percentage < 0 || $quantity < 0) {
                                                        $discountAmount = $discountAmount * -1;
                                                    }
                                                    $discountAmount = round($discountAmount, 2);

                                                    // Store that this is a percentage discount
                                                    $set('discount_type', 'percentage');
                                                    $set('discount_percentage', $percentage);

                                                    // Update the display value - show percentage format
                                                    $set('discount', self::formatPercentage($percentage));

                                                    // Update unit_discount - preserve sign when quantity is negative
                                                    if ($quantity != 0) {
                                                        $unitDiscount = $discountAmount / $quantity;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));
                                                    // Store the calculated amount in a hidden field for calculations
                                                    $set('discount_amount', $discountAmount);
                                                } else {
                                                    // Treat as flat discount amount and round to 2 decimal places
                                                    // Automatically set discount to negative when quantity is negative (return case)
                                                    $discountAmount = (float) $state;
                                                    if ($quantity < 0) {
                                                        // For negative quantity, automatically make discount negative
                                                        $discountAmount = abs($discountAmount) * -1;
                                                    } else {
                                                        // For positive quantity, ensure discount is non-negative
                                                        $discountAmount = max(0, $discountAmount);
                                                    }
                                                    $discountAmount = round($discountAmount, 2);
                                                    $set('discount', self::formatNumberForState($discountAmount));

                                                    // Store that this is a flat discount
                                                    $set('discount_type', 'flat');
                                                    $set('discount_percentage', null);

                                                    // Update unit_discount - preserve sign when quantity is negative
                                                    if ($quantity != 0) {
                                                        $unitDiscount = $discountAmount / $quantity;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));
                                                    $set('discount_amount', $discountAmount);
                                                }

                                                // Recalculate line total
                                                SaleForm::recalcLine($get, $set);
                                            }),
                                        TextInput::make('total')
                                            ->placeholder('Total')
                                            ->numeric()
                                            ->live(onBlur: true)
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'data-sale-item-input' => 'true',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                // When total is changed, calculate new discount
                                                $quantity = (float) ($get('quantity') ?: 1);
                                                $rawTotal = round((float) $state, 2);
                                                $newTotal = $quantity >= 0 ? round(max(0, $rawTotal), 2) : $rawTotal;
                                                $set('total', $newTotal);
                                                $unitPrice = (float) ($get('unit_price') ?: 0);

                                                // Calculate what the discount should be: (unitPrice * quantity) - newTotal
                                                $calculatedDiscount = ($unitPrice * $quantity) - $newTotal;
                                                // Allow negative discount when quantity is negative (return case)
                                                if ($quantity >= 0) {
                                                    $calculatedDiscount = max(0, $calculatedDiscount);
                                                }
                                                $calculatedDiscount = round($calculatedDiscount, 2);

                                                // Update unit_discount - preserve sign when quantity is negative
                                                if ($quantity != 0) {
                                                    $unitDiscount = $calculatedDiscount / $quantity;
                                                } else {
                                                    $unitDiscount = 0;
                                                }
                                                $set('unit_discount', round($unitDiscount, 2));
                                                $set('discount', self::formatNumberForState($calculatedDiscount));

                                                // When total is manually changed, reset to flat discount
                                                $set('discount_type', 'flat');
                                                $set('discount_percentage', null);

                                                // Recalculate summary
                                                // From variations repeater item: ../../variations, so preparable_variations is ../../preparable_variations
                                                SaleForm::recalcSummary($get, $set, '../../variations', '../../preparable_variations', 'preparable_items', '../../subtotal', '../../total', '../../total_tax', '../../discount', '../../freight_fare');
                                            }),
                                    ])
                                    ->addable(false)
                                    ->reorderable(false)
                                    ->columnSpanFull(),
                                Repeater::make('preparable_variations')
                                    ->hiddenLabel()
                                    ->default([])
                                    ->deletable()
                                    ->addable(false)
                                    ->reorderable(false)
                                    ->itemLabel(function (array $state): ?string {
                                        return $state['description'] ?? 'Preparable Product';
                                    })
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        SaleForm::recalcSummary($get, $set);
                                    })
                                    ->schema([
                                        Hidden::make('instance_id'),
                                        Hidden::make('variation_id'),
                                        Hidden::make('stock_id'),
                                        Hidden::make('unit_discount'), // Per-unit discount amount
                                        Hidden::make('discount_type'), // 'percentage' or 'flat'
                                        Hidden::make('discount_percentage'), // Store percentage value if discount_type is 'percentage'
                                        Hidden::make('discount_amount'), // Store the calculated discount amount
                                        Hidden::make('description')
                                            ->default('Sample Preparable Product'),
                                        TextInput::make('qty')
                                            ->label('Qty')
                                            ->numeric()
                                            ->default(1)
                                            ->live(onBlur: true)
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                // Round to 6 decimal places, allow negative quantities
                                                $qty = round((float) ($state ?: 1), 6);
                                                // Ensure quantity is not zero
                                                if ($qty == 0) {
                                                    $qty = $qty > 0 ? 1 : -1;
                                                }
                                                $set('qty', $qty);

                                                // If discount type is percentage, recalculate the discount amount
                                                if ($get('discount_type') === 'percentage') {
                                                    $price = (float) ($get('price') ?: 0);
                                                    $percentage = (float) ($get('discount_percentage') ?? 0);

                                                    // Get preparable_items to calculate items total
                                                    $preparableVariations = $get('../../preparable_variations') ?? [];
                                                    $currentVariationId = $get('variation_id');
                                                    $currentDescription = $get('description');
                                                    $itemsTotal = 0;

                                                    foreach ($preparableVariations as $variation) {
                                                        if (($variation['variation_id'] ?? null) == $currentVariationId ||
                                                            ($variation['description'] ?? null) == $currentDescription) {
                                                            $preparableItems = $variation['preparable_items'] ?? [];
                                                            if (is_array($preparableItems)) {
                                                                foreach ($preparableItems as $item) {
                                                                    $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                                                                    $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;
                                                                    $itemDisc = isset($item['disc']) ? (float) $item['disc'] : 0;
                                                                    $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
                                                                }
                                                            }
                                                            break;
                                                        }
                                                    }

                                                    $subtotalBeforeDiscount = ($price + $itemsTotal) * abs($qty);

                                                    // Recalculate discount amount from percentage and round to 2 decimals
                                                    $discountAmount = ($subtotalBeforeDiscount * $percentage) / 100;
                                                    // Automatically set discount to negative when quantity is negative (return case)
                                                    if ($qty < 0) {
                                                        $discountAmount = $discountAmount * -1;
                                                    }
                                                    $discountAmount = round($discountAmount, 2);
                                                    // Update display to show negative discount amount when quantity is negative, otherwise show percentage
                                                    if ($qty < 0) {
                                                        $set('disc', self::formatNumberForState($discountAmount));
                                                    } else {
                                                        // Keep percentage format when quantity is positive
                                                        $set('disc', self::formatPercentage($percentage));
                                                    }
                                                    // Update unit_discount - preserve sign when quantity is negative
                                                    if ($qty != 0) {
                                                        $unitDiscount = $discountAmount / $qty;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));
                                                    $set('discount_amount', $discountAmount);
                                                } else {
                                                    // Recalculate discount based on unit_discount
                                                    $unitDiscount = (float) ($get('unit_discount') ?? 0);
                                                    if ($unitDiscount != 0) {
                                                        // Use quantity (not abs) to preserve sign when quantity is negative
                                                        $discount = round($unitDiscount * $qty, 2);
                                                        $set('disc', self::formatNumberForState($discount));
                                                    } else {
                                                        // If no unit_discount, adjust sign based on quantity
                                                        $currentDiscount = (float) ($get('disc') ?? 0);
                                                        if ($qty < 0 && $currentDiscount > 0) {
                                                            // If quantity becomes negative and discount is positive, make discount negative
                                                            $set('disc', self::formatNumberForState($currentDiscount * -1));
                                                        } elseif ($qty >= 0 && $currentDiscount < 0) {
                                                            // If quantity becomes positive and discount is negative, make discount positive
                                                            $set('disc', self::formatNumberForState(abs($currentDiscount)));
                                                        }
                                                    }
                                                }

                                                SaleForm::recalcPreparableVariationLine($get, $set);
                                            }),
                                        TextInput::make('price')
                                            ->label('Price')
                                            ->numeric()
                                            ->default(100.00)
                                            ->live(onBlur: true)
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'data-price-input' => 'true',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->extraAttributes([
                                                'class' => 'price-field-wrapper',
                                                'x-data' => '{}',
                                                'x-init' => "\$nextTick(() => {
                                                    const styleId = 'price-helper-style';
                                                    if (!document.getElementById(styleId)) {
                                                        const style = document.createElement('style');
                                                        style.id = styleId;
                                                        style.textContent = '.price-field-wrapper .fi-sc-text, .fi-input-wrapper:has(input[data-price-input]) .fi-sc-text { font-size: 0.3rem !important; line-height: 0.35rem !important; opacity: 0.5 !important; margin-top: 0.0625rem !important; color: rgb(107 114 128) !important; }';
                                                        document.head.appendChild(style);
                                                    }
                                                })",
                                            ])
                                            ->helperText(function ($get) {
                                                $price = (float) ($get('price') ?? 0);
                                                $qty = (float) ($get('qty') ?? 1);

                                                // Get preparable_items to calculate items total (needed for accurate price calculation)
                                                $preparableVariations = $get('../../preparable_variations') ?? [];
                                                $currentVariationId = $get('variation_id');
                                                $currentDescription = $get('description');
                                                $itemsTotal = 0;

                                                foreach ($preparableVariations as $variation) {
                                                    if (($variation['variation_id'] ?? null) == $currentVariationId ||
                                                        ($variation['description'] ?? null) == $currentDescription) {
                                                        $preparableItems = $variation['preparable_items'] ?? [];
                                                        if (is_array($preparableItems)) {
                                                            foreach ($preparableItems as $item) {
                                                                $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                                                                $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;
                                                                $itemDisc = isset($item['disc']) ? (float) $item['disc'] : 0;
                                                                $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
                                                            }
                                                        }
                                                        break;
                                                    }
                                                }

                                                // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                                                $discountType = $get('discount_type');
                                                if ($discountType === 'percentage') {
                                                    // For percentage discounts, use the calculated discount_amount
                                                    $discountAmount = (float) ($get('discount_amount') ?? 0);
                                                } else {
                                                    // For flat discounts, use the disc field directly
                                                    $disc = $get('disc');
                                                    $discountAmount = is_string($disc) && str_contains($disc, '%') ? 0 : (float) $disc;
                                                }

                                                // Only show helper text if there's a discount applied and qty > 0
                                                if ($discountAmount != 0 && ($price + $itemsTotal) > 0 && abs($qty) > 0) {
                                                    // Calculate per-unit discount (discount is applied to (price + itemsTotal) * qty)
                                                    $unitDiscount = abs($discountAmount) / abs($qty);
                                                    // Calculate price after discount per unit (price + itemsTotal - unitDiscount)
                                                    $priceAfterDiscount = ($price + ($itemsTotal / abs($qty))) - $unitDiscount;

                                                    if ($priceAfterDiscount > 0) {
                                                        // Get smallest integer (floor)
                                                        $priceAfterDiscountInteger = (int) floor($priceAfterDiscount);

                                                        if ($priceAfterDiscountInteger > 0) {
                                                            return "1x={$priceAfterDiscountInteger}";
                                                        }
                                                    }
                                                }

                                                return null;
                                            })
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                $price = round((float) $state, 2);
                                                if ((float) $state !== $price) {
                                                    $set('price', $price);
                                                }

                                                // If there's a percentage discount, recalculate discount_amount based on new price
                                                $discountType = $get('discount_type');
                                                if ($discountType === 'percentage') {
                                                    $qty = (float) ($get('qty') ?: 1);

                                                    // Get preparable_items to calculate items total
                                                    $preparableVariations = $get('../../preparable_variations') ?? [];
                                                    $currentVariationId = $get('variation_id');
                                                    $currentDescription = $get('description');
                                                    $itemsTotal = 0;

                                                    foreach ($preparableVariations as $variation) {
                                                        if (($variation['variation_id'] ?? null) == $currentVariationId ||
                                                            ($variation['description'] ?? null) == $currentDescription) {
                                                            $preparableItems = $variation['preparable_items'] ?? [];
                                                            if (is_array($preparableItems)) {
                                                                foreach ($preparableItems as $item) {
                                                                    $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                                                                    $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;
                                                                    $itemDisc = isset($item['disc']) ? (float) $item['disc'] : 0;
                                                                    $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
                                                                }
                                                            }
                                                            break;
                                                        }
                                                    }

                                                    $subtotalBeforeDiscount = ($price + $itemsTotal) * abs($qty);
                                                    $discountPercentage = (float) ($get('discount_percentage') ?? 0);

                                                    // Recalculate discount amount from percentage
                                                    // Use absolute value for calculation, then apply sign
                                                    $discountAmount = ($subtotalBeforeDiscount * abs($discountPercentage)) / 100;
                                                    // Apply negative sign if percentage is negative or quantity is negative
                                                    if ($discountPercentage < 0 || $qty < 0) {
                                                        $discountAmount = $discountAmount * -1;
                                                    }
                                                    $discountAmount = round($discountAmount, 2);

                                                    // Update discount_amount and unit_discount
                                                    $set('discount_amount', $discountAmount);
                                                    if ($qty != 0) {
                                                        $unitDiscount = $discountAmount / $qty;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));

                                                    // Always preserve percentage format in display
                                                    $set('disc', self::formatPercentage($discountPercentage));
                                                }

                                                SaleForm::recalcPreparableVariationLine($get, $set);
                                            }),
                                        TextInput::make('disc')
                                            ->label('Disc')
                                            ->placeholder('10 or 10%')
                                            ->reactive()
                                            ->live(onBlur: true)
                                            ->afterStateHydrated(function ($state, $set, $get) {
                                                // When loading existing data, format percentage discounts correctly
                                                $discountType = $get('discount_type');
                                                if ($discountType === 'percentage') {
                                                    $discountPercentage = (float) ($get('discount_percentage') ?? 0);
                                                    if ($discountPercentage != 0) {
                                                        $set('disc', self::formatPercentage($discountPercentage));
                                                    }
                                                }
                                            })
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->helperText(function ($get) {
                                                $qty = (float) ($get('qty') ?? 1);
                                                $disc = $get('disc');

                                                // Don't show helper text for percentage discounts
                                                if (is_string($disc) && str_contains($disc, '%')) {
                                                    return null;
                                                }

                                                // Only show hint if quantity > 1 and there's a discount
                                                if (abs($qty) > 1 && $disc !== null && $disc !== '') {
                                                    // Flat discount - use the discount value directly
                                                    $discountAmount = (float) $disc;

                                                    if ($discountAmount != 0) {
                                                        // Calculate per-unit discount
                                                        $unitDiscount = abs($discountAmount) / abs($qty);
                                                        // Get smallest integer (floor)
                                                        $unitDiscountInteger = (int) floor($unitDiscount);

                                                        if ($unitDiscountInteger > 0) {
                                                            return "1x={$unitDiscountInteger}";
                                                        }
                                                    }
                                                }

                                                return null;
                                            })
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                $qty = (float) ($get('qty') ?: 1);
                                                $price = (float) ($get('price') ?: 0);

                                                // Get preparable_items to calculate items total
                                                $preparableVariations = $get('../../preparable_variations') ?? [];
                                                $currentVariationId = $get('variation_id');
                                                $currentDescription = $get('description');
                                                $itemsTotal = 0;

                                                foreach ($preparableVariations as $variation) {
                                                    if (($variation['variation_id'] ?? null) == $currentVariationId ||
                                                        ($variation['description'] ?? null) == $currentDescription) {
                                                        $preparableItems = $variation['preparable_items'] ?? [];
                                                        if (is_array($preparableItems)) {
                                                            foreach ($preparableItems as $item) {
                                                                $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                                                                $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;
                                                                $itemDisc = isset($item['disc']) ? (float) $item['disc'] : 0;
                                                                $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
                                                            }
                                                        }
                                                        break;
                                                    }
                                                }

                                                $subtotalBeforeDiscount = ($price + $itemsTotal) * abs($qty);

                                                // Check if state contains percentage symbol
                                                // Convert state to string first to handle any numeric conversion
                                                $stateString = is_string($state) ? $state : (string) $state;
                                                if (str_contains($stateString, '%')) {
                                                    // Extract the percentage value
                                                    $rawPercentage = str_replace('%', '', trim($stateString));

                                                    // Validate format - must be numeric
                                                    if (! is_numeric($rawPercentage)) {
                                                        $set('disc', '');
                                                        $set('discount_type', null);
                                                        $set('discount_percentage', null);

                                                        return;
                                                    }

                                                    $percentage = (float) $rawPercentage;

                                                    // Validate percentage range based on quantity
                                                    // For returns (negative quantity), allow -999.999999 to 999.999999
                                                    // For normal sales (positive quantity), allow 0 to 999.999999
                                                    if ($qty < 0) {
                                                        // Return case: allow negative percentages (-999.999999 to 999.999999)
                                                        if ($percentage < -999.999999) {
                                                            $percentage = -999.999999;
                                                        } elseif ($percentage > 999.999999) {
                                                            $percentage = 999.999999;
                                                        }
                                                    } else {
                                                        // Normal sale: allow 0 to 999.999999
                                                        if ($percentage < 0) {
                                                            $percentage = 0;
                                                        } elseif ($percentage > 999.999999) {
                                                            $percentage = 999.999999;
                                                        }
                                                    }

                                                    $percentage = round($percentage, 6); // Round to 6 decimals

                                                    // Calculate discount amount from percentage
                                                    // Use absolute value for calculation, then apply sign
                                                    $discountAmount = ($subtotalBeforeDiscount * abs($percentage)) / 100;
                                                    // Apply negative sign if percentage is negative or quantity is negative
                                                    if ($percentage < 0 || $qty < 0) {
                                                        $discountAmount = $discountAmount * -1;
                                                    }
                                                    $discountAmount = round($discountAmount, 2);

                                                    // Store that this is a percentage discount
                                                    $set('discount_type', 'percentage');
                                                    $set('discount_percentage', $percentage);

                                                    // Update the display value - show percentage format
                                                    $set('disc', self::formatPercentage($percentage));

                                                    // Update unit_discount - preserve sign when quantity is negative
                                                    if ($qty != 0) {
                                                        $unitDiscount = $discountAmount / $qty;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));
                                                    // Store the calculated amount in a hidden field for calculations
                                                    $set('discount_amount', $discountAmount);
                                                } else {
                                                    // Treat as flat discount amount and round to 2 decimal places
                                                    // Automatically set discount to negative when quantity is negative (return case)
                                                    $discountAmount = (float) $state;
                                                    if ($qty < 0) {
                                                        // For negative quantity, automatically make discount negative
                                                        $discountAmount = abs($discountAmount) * -1;
                                                    } else {
                                                        // For positive quantity, ensure discount is non-negative
                                                        $discountAmount = max(0, $discountAmount);
                                                    }
                                                    $discountAmount = round($discountAmount, 2);
                                                    $set('disc', self::formatNumberForState($discountAmount));

                                                    // Store that this is a flat discount
                                                    $set('discount_type', 'flat');
                                                    $set('discount_percentage', null);

                                                    // Update unit_discount - preserve sign when quantity is negative
                                                    if ($qty != 0) {
                                                        $unitDiscount = $discountAmount / $qty;
                                                    } else {
                                                        $unitDiscount = 0;
                                                    }
                                                    $set('unit_discount', round($unitDiscount, 2));
                                                    $set('discount_amount', $discountAmount);
                                                }

                                                // Recalculate summary
                                                SaleForm::recalcPreparableVariationLine($get, $set);
                                            }),
                                        TextInput::make('total')
                                            ->label('Total')
                                            ->numeric()
                                            ->default(100.00)
                                            ->live(onBlur: true)
                                            ->extraInputAttributes([
                                                'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                // When total is changed, calculate new discount
                                                $qty = (float) ($get('qty') ?: 1);
                                                $rawTotal = round((float) $state, 2);
                                                $newTotal = $qty >= 0 ? round(max(0, $rawTotal), 2) : $rawTotal;
                                                $set('total', $newTotal);

                                                $price = (float) ($get('price') ?: 0);

                                                // Get preparable_items to calculate items total
                                                $preparableVariations = $get('../../preparable_variations') ?? [];
                                                $currentVariationId = $get('variation_id');
                                                $currentDescription = $get('description');
                                                $itemsTotal = 0;

                                                foreach ($preparableVariations as $variation) {
                                                    if (($variation['variation_id'] ?? null) == $currentVariationId ||
                                                        ($variation['description'] ?? null) == $currentDescription) {
                                                        $preparableItems = $variation['preparable_items'] ?? [];
                                                        if (is_array($preparableItems)) {
                                                            foreach ($preparableItems as $item) {
                                                                $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                                                                $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;
                                                                $itemDisc = isset($item['disc']) ? (float) $item['disc'] : 0;
                                                                $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
                                                            }
                                                        }
                                                        break;
                                                    }
                                                }

                                                // Calculate what the discount should be: ((price + items_total) * qty) - newTotal
                                                $calculatedDisc = (($price + $itemsTotal) * $qty) - $newTotal;
                                                // Allow negative discount when quantity is negative
                                                if ($qty >= 0) {
                                                    $calculatedDisc = max(0, $calculatedDisc);
                                                }
                                                $calculatedDisc = round($calculatedDisc, 2);

                                                $set('disc', self::formatNumberForState($calculatedDisc));

                                                // Update unit_discount based on quantity
                                                if ($qty != 0) {
                                                    $unitDiscount = $calculatedDisc / $qty;
                                                } else {
                                                    $unitDiscount = 0;
                                                }
                                                $set('unit_discount', round($unitDiscount, 2));

                                                // Recalculate summary
                                                SaleForm::recalcPreparableVariationLine($get, $set);
                                            }),
                                        ProductSearchInput::make('preparable_product_search')
                                            ->hiddenLabel()
                                            ->placeholder('Search by Barcode / SKU / Description for nested items')
                                            ->mode('barcodes')
                                            ->excludePreparable()
                                            ->live()
                                            ->columnSpanFull()
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                // This will be triggered when a product is selected via Livewire event
                                                // The state will contain the barcode ID (when mode is 'barcodes')
                                                if (! $state) {
                                                    return;
                                                }

                                                $barcode = Stock::query()
                                                    ->with(['variation.product'])
                                                    ->find($state);
                                                if (! $barcode || ! $barcode->variation) {
                                                    Notification::make()
                                                        ->title('Product not found')
                                                        ->danger()
                                                        ->duration(1000)
                                                        ->send();
                                                    $set('preparable_product_search', null);

                                                    return;
                                                }

                                                $variation = $barcode->variation;

                                                // Ensure variation is a model instance, not an array
                                                if (! $variation || ! ($variation instanceof Variation)) {
                                                    Notification::make()
                                                        ->title('Product variation not found')
                                                        ->danger()
                                                        ->duration(1000)
                                                        ->send();
                                                    $set('preparable_product_search', null);

                                                    return;
                                                }

                                                // Reload variation to ensure it's a fresh model instance
                                                $variation = Variation::find($variation->id);
                                                if (! $variation) {
                                                    Notification::make()
                                                        ->title('Product variation not found')
                                                        ->danger()
                                                        ->duration(1000)
                                                        ->send();
                                                    $set('preparable_product_search', null);

                                                    return;
                                                }

                                                $product = $variation->product;

                                                // Check if product is preparable (should not be added as nested item)
                                                if ($product && $product->is_preparable) {
                                                    Notification::make()
                                                        ->title('Preparable products cannot be added as nested items')
                                                        ->danger()
                                                        ->duration(2000)
                                                        ->send();
                                                    $set('preparable_product_search', null);

                                                    return;
                                                }

                                                // Get all preparable_variations from root level
                                                // We're inside preparable_variations.{index}.preparable_product_search
                                                // So we need to go up to root level: ../../preparable_variations
                                                $preparableVariations = $get('../../preparable_variations') ?? [];

                                                if (empty($preparableVariations)) {
                                                    Notification::make()
                                                        ->title('Please add a preparable product first')
                                                        ->warning()
                                                        ->duration(2000)
                                                        ->send();
                                                    $set('preparable_product_search', null);

                                                    return;
                                                }

                                                // CRITICAL: Identify which preparable variation this search field belongs to
                                                // We're inside preparable_variations.{index}.preparable_product_search
                                                // Strategy: Use instance_id first (most reliable for multiple instances of same variation)
                                                // Then fall back to other methods
                                                $currentIndex = null;

                                                // FIRST: Try to get instance_id from parent context (most reliable)
                                                $currentInstanceId = $get('instance_id');
                                                if (! $currentInstanceId) {
                                                    $currentInstanceId = $get('../instance_id');
                                                }

                                                if ($currentInstanceId) {
                                                    // Match by instance_id - this is unique for each preparable variation instance
                                                    foreach ($preparableVariations as $index => $preparableVariation) {
                                                        $instanceId = $preparableVariation['instance_id'] ?? null;
                                                        if ($instanceId == $currentInstanceId) {
                                                            $currentIndex = $index;
                                                            break;
                                                        }
                                                    }
                                                }

                                                // SECOND: If instance_id matching failed, try to get preparable_items and match by content
                                                // This helps when instance_id is not yet set
                                                if ($currentIndex === null) {
                                                    // Try to get preparable_items directly from parent context
                                                    try {
                                                        $preparableItems = $get('../preparable_items');
                                                        if (! is_array($preparableItems)) {
                                                            $preparableItems = [];
                                                        }
                                                    } catch (\Exception $e) {
                                                        $preparableItems = [];
                                                    }

                                                    // Match by comparing items - find variation that contains these exact items
                                                    foreach ($preparableVariations as $index => $preparableVariation) {
                                                        $variationItems = $preparableVariation['preparable_items'] ?? [];
                                                        if (! is_array($variationItems)) {
                                                            $variationItems = [];
                                                        }

                                                        // Match by comparing items - check if arrays are identical
                                                        $matches = true;
                                                        if (count($preparableItems) !== count($variationItems)) {
                                                            $matches = false;
                                                        } else {
                                                            foreach ($preparableItems as $currentItem) {
                                                                $found = false;
                                                                foreach ($variationItems as $variationItem) {
                                                                    if (($currentItem['variation_id'] ?? null) == ($variationItem['variation_id'] ?? null) &&
                                                                        ($currentItem['stock_id'] ?? null) == ($variationItem['stock_id'] ?? null)) {
                                                                        $found = true;
                                                                        break;
                                                                    }
                                                                }
                                                                if (! $found) {
                                                                    $matches = false;
                                                                    break;
                                                                }
                                                            }
                                                        }

                                                        if ($matches) {
                                                            $currentIndex = $index;
                                                            break;
                                                        }
                                                    }
                                                }

                                                // THIRD: Fallback - try variation_id and description (less reliable for multiple instances)
                                                if ($currentIndex === null) {
                                                    $currentVariationId = $get('variation_id');
                                                    if (! $currentVariationId) {
                                                        $currentVariationId = $get('../variation_id');
                                                    }

                                                    $currentDescription = $get('description');
                                                    if (! $currentDescription) {
                                                        $currentDescription = $get('../description');
                                                    }

                                                    // Try to match by variation_id + description combination
                                                    if ($currentVariationId && $currentDescription) {
                                                        foreach ($preparableVariations as $index => $preparableVariation) {
                                                            $variationId = $preparableVariation['variation_id'] ?? null;
                                                            $description = $preparableVariation['description'] ?? null;
                                                            if ($variationId == $currentVariationId && $description == $currentDescription) {
                                                                // Only match if this variation has no items yet (to avoid matching wrong instance)
                                                                $variationItems = $preparableVariation['preparable_items'] ?? [];
                                                                if (empty($variationItems)) {
                                                                    $currentIndex = $index;
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                // If we still couldn't find a match, show error
                                                if ($currentIndex === null) {
                                                    Notification::make()
                                                        ->title('Could not identify preparable variation')
                                                        ->body('Please try adding the item again.')
                                                        ->warning()
                                                        ->duration(2000)
                                                        ->send();
                                                    $set('preparable_product_search', null);

                                                    return;
                                                }

                                                // Get preparable_items for the identified variation
                                                $preparableItems = $preparableVariations[$currentIndex]['preparable_items'] ?? [];
                                                if (! is_array($preparableItems)) {
                                                    $preparableItems = [];
                                                }

                                                // Check if this variation already exists in preparable_items
                                                $found = false;
                                                foreach ($preparableItems as $key => &$item) {
                                                    if (($item['variation_id'] ?? null) == $variation->id) {
                                                        // Increment quantity if already exists
                                                        $item['quantity'] = round(($item['quantity'] ?? 1) + 1, 4);
                                                        // Recalculate discount based on unit_discount
                                                        $unitDiscount = isset($item['unit_discount']) ? (float) $item['unit_discount'] : 0;
                                                        $item['disc'] = self::formatNumberForState($unitDiscount * $item['quantity']);
                                                        // Recalculate total
                                                        $item['total'] = round(($item['unit_price'] * $item['quantity']) - $item['disc'], 2);
                                                        // Ensure item_id exists (for backward compatibility)
                                                        if (! isset($item['item_id'])) {
                                                            $item['item_id'] = (string) microtime(true);
                                                        }
                                                        $found = true;
                                                        break;
                                                    }
                                                }
                                                unset($item);

                                                if (! $found) {
                                                    $description = $variation->brand_name
                                                        ? $variation->sku.' - '.$variation->brand_name.' - '.$variation->description
                                                        : $variation->sku.' - '.$variation->description;

                                                    // Calculate base price and sale price (always from variation, never from barcode)
                                                    $basePrice = $variation->price ?? 0;
                                                    $salePrice = $variation->sale_price ?? $basePrice;
                                                    $discountAmount = round($basePrice - $salePrice, 2);

                                                    $preparableItems[] = [
                                                        'item_id' => (string) microtime(true), // Unique identifier for each item instance
                                                        'variation_id' => $variation->id,
                                                        'stock_id' => $barcode->id,
                                                        'description' => $description,
                                                        'quantity' => 1,
                                                        'unit_price' => round($basePrice, 2),
                                                        'unit_discount' => round($discountAmount, 2), // Per-unit discount
                                                        'disc' => round($discountAmount, 2),
                                                        'total' => round($salePrice, 2),
                                                    ];
                                                }

                                                // Update the preparable_items in the full array
                                                if ($currentIndex !== null && isset($preparableVariations[$currentIndex])) {
                                                    $preparableVariations[$currentIndex]['preparable_items'] = array_values($preparableItems);

                                                    // Update the entire preparable_variations array from root
                                                    $set('../../preparable_variations', array_values($preparableVariations));

                                                    // Recalculate the preparable variation total and summary
                                                    SaleForm::recalcPreparableVariationLine($get, $set, '../../preparable_variations');
                                                }

                                                // Clear the search field
                                                $set('preparable_product_search', null);

                                                Notification::make()
                                                    ->title('Product added to preparable items')
                                                    ->success()
                                                    ->duration(1000)
                                                    ->send();
                                            }),
                                        Repeater::make('preparable_items')
                                            ->hiddenLabel()
                                            ->default([])
                                            ->deletable()
                                            ->addable(false)
                                            ->reorderable(false)
                                            ->afterStateUpdated(function ($state, $set, $get) {

                                                // Ensure all items have item_id
                                                if (is_array($state)) {
                                                    $updated = false;
                                                    foreach ($state as &$item) {
                                                        if (! isset($item['item_id']) || empty($item['item_id'])) {
                                                            $item['item_id'] = (string) microtime(true);
                                                            $updated = true;
                                                        }
                                                    }
                                                    unset($item);
                                                    if ($updated) {
                                                        $set('../preparable_items', array_values($state));
                                                    }
                                                }

                                                // CRITICAL: When items are deleted, recalculate the parent preparable variation's total
                                                // Get the parent preparable variation's data
                                                // We're in preparable_variations.{index}.preparable_items context
                                                // Structure: preparable_variations[0].preparable_items[0]
                                                // To get to preparable_variations array: go up 2 levels (../../)
                                                $preparableVariations = $get('../../preparable_variations') ?? [];

                                                // Try to get parent instance_id, variation_id and description from form fields
                                                // Prioritize instance_id (most reliable for multiple instances of same variation)
                                                $parentInstanceId = $get('../../instance_id');
                                                $parentVariationId = $get('../../variation_id');
                                                $parentDescription = $get('../../description');

                                                // Store these variables outside the loop so they're accessible after the loop
                                                $foundIndex = null;
                                                $calculatedPreparableTotal = null;

                                                if (! empty($preparableVariations) && is_array($preparableVariations)) {
                                                    // If we only have one preparable variation, it must be the parent
                                                    // This is a fallback when identification doesn't work
                                                    $useFirstVariationAsParent = count($preparableVariations) === 1;

                                                    foreach ($preparableVariations as $index => &$variation) {
                                                        // Find the parent preparable variation
                                                        // Prioritize instance_id matching (most reliable)
                                                        $isParent = false;

                                                        // FIRST: Try to match by instance_id (most reliable)
                                                        if ($parentInstanceId && ($variation['instance_id'] ?? null) == $parentInstanceId) {
                                                            $isParent = true;
                                                        } elseif ($parentVariationId && ($variation['variation_id'] ?? null) == $parentVariationId) {
                                                            // SECOND: Fallback to variation_id (less reliable when multiple instances exist)
                                                            // Only use this if instance_id matching failed
                                                            $isParent = true;
                                                        } elseif ($parentDescription && ($variation['description'] ?? null) == $parentDescription) {
                                                            // THIRD: Fallback to description (least reliable)
                                                            $isParent = true;
                                                        } elseif ($useFirstVariationAsParent) {
                                                            // FINAL FALLBACK: If there's only one variation, it must be the parent
                                                            $isParent = true;
                                                        }

                                                        if ($isParent) {
                                                            // Store the index and total for use after the loop
                                                            $foundIndex = $index;
                                                            // Get the updated preparable_items array (this is the NEW state after deletion)
                                                            $preparableItems = $state ?? [];
                                                            if (! is_array($preparableItems)) {
                                                                $preparableItems = [];
                                                            }

                                                            // Calculate items total from remaining items
                                                            // Calculate total from all nested items (remaining items after deletion)
                                                            $itemsTotal = 0;
                                                            foreach ($preparableItems as $item) {
                                                                $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                                                                $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;

                                                                // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                                                                $itemDiscountType = isset($item['discount_type']) ? $item['discount_type'] : 'flat';
                                                                if ($itemDiscountType === 'percentage') {
                                                                    // For percentage discounts, use discount_amount (already calculated)
                                                                    $itemDisc = isset($item['discount_amount']) ? (float) $item['discount_amount'] : 0;
                                                                } else {
                                                                    // For flat discounts, use disc field
                                                                    // Handle case where disc might be a string (shouldn't happen for flat, but safety check)
                                                                    $discValue = $item['disc'] ?? 0;
                                                                    if (is_string($discValue) && str_contains($discValue, '%')) {
                                                                        // This shouldn't happen, but if it does, treat as 0
                                                                        $itemDisc = 0;
                                                                    } else {
                                                                        $itemDisc = (float) $discValue;
                                                                    }
                                                                }

                                                                $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
                                                            }

                                                            // Calculate preparable variation total
                                                            // CRITICAL: Read values from the variation array, not from form fields
                                                            // The variation array contains the current state
                                                            $preparableQty = isset($variation['qty']) ? (float) $variation['qty'] : 1;
                                                            $preparablePrice = isset($variation['price']) ? (float) $variation['price'] : 0;

                                                            // Ensure preparablePrice is not null/empty - if it is, it might be 0
                                                            // This could happen if the preparable product doesn't have a price set

                                                            // Get preparable discount
                                                            $preparableDiscountType = isset($variation['discount_type']) ? $variation['discount_type'] : 'flat';
                                                            if ($preparableDiscountType === 'percentage') {
                                                                // For percentage discounts, use discount_amount (already calculated)
                                                                $preparableDisc = isset($variation['discount_amount']) ? (float) $variation['discount_amount'] : 0;
                                                            } else {
                                                                // For flat discounts, use disc field
                                                                // Handle case where disc might be a string (shouldn't happen for flat, but safety check)
                                                                $discValue = $variation['disc'] ?? 0;
                                                                if (is_string($discValue) && str_contains($discValue, '%')) {
                                                                    // This shouldn't happen, but if it does, treat as 0
                                                                    $preparableDisc = 0;
                                                                } else {
                                                                    $preparableDisc = (float) $discValue;
                                                                }
                                                            }

                                                            // Calculate preparable variation total: ((preparable_price + items_total) * preparable_qty) - preparable_disc
                                                            $preparableTotal = round((($preparablePrice + $itemsTotal) * $preparableQty) - $preparableDisc, 2);

                                                            // Store the calculated total for use after the loop
                                                            $calculatedPreparableTotal = $preparableTotal;

                                                            // CRITICAL: Update the preparable variation's total in the array FIRST
                                                            $preparableVariations[$index]['total'] = $preparableTotal;
                                                            $preparableVariations[$index]['preparable_items'] = array_values($preparableItems);

                                                            // CRITICAL: Update the entire array at root level
                                                            // We're in preparable_variations.{index}.preparable_items context
                                                            // To update at root level, go up 2 levels: ../../preparable_variations
                                                            $set('../../preparable_variations', array_values($preparableVariations));

                                                            // CRITICAL: Update the preparable variation's total form field directly
                                                            // This ensures the UI shows the correct value immediately
                                                            // We're in preparable_items context, so go up 2 levels to get to preparable_variation level
                                                            $set('../../total', $preparableTotal);

                                                            break;
                                                        }
                                                    }
                                                    unset($variation);
                                                }

                                                // CRITICAL: Recalculate summary AFTER updating the array
                                                // Use root-level paths since we're in a nested repeater
                                                // The paths need to go up to the root level
                                                // IMPORTANT: recalcSummary reads from the array but doesn't update individual preparable variation totals
                                                // So we've already updated the total above, and recalcSummary will use that updated value
                                                SaleForm::recalcSummary(
                                                    $get,
                                                    $set,
                                                    '../../../../variations', // Regular variations at root (go up 4 levels from preparable_items)
                                                    '../../preparable_variations', // Preparable variations (go up 2 levels from preparable_items)
                                                    'preparable_items', // Field name within each preparable variation
                                                    '../../../../subtotal',
                                                    '../../../../total',
                                                    '../../../../total_tax',
                                                    '../../../../discount',
                                                    '../../../../freight_fare'
                                                );

                                                // CRITICAL: After recalcSummary, ensure the preparable variation's total field is still correct
                                                // Sometimes Filament might not have processed the update yet, so we verify and update again
                                                // Use the stored $foundIndex and $calculatedPreparableTotal variables
                                                if ($foundIndex !== null && $calculatedPreparableTotal !== null) {
                                                    $finalCheckPreparableVariations = $get('../../preparable_variations') ?? [];
                                                    if (isset($finalCheckPreparableVariations[$foundIndex])) {
                                                        $finalCheckPreparableVariations[$foundIndex]['total'] = $calculatedPreparableTotal;
                                                        $set('../../preparable_variations', array_values($finalCheckPreparableVariations));
                                                        $set('../../total', $calculatedPreparableTotal);
                                                    }
                                                }
                                            })
                                            ->table([
                                                Repeater\TableColumn::make('Description')->width('45%'),
                                                Repeater\TableColumn::make('Qty')->width('12%'),
                                                Repeater\TableColumn::make('Price')->width('15%'),
                                                Repeater\TableColumn::make('Disc.')->width('15%'),
                                                Repeater\TableColumn::make('Total')->width('15%'),
                                            ])
                                            ->schema([
                                                Hidden::make('item_id') // Unique identifier for each item instance
                                                    ->default(function ($get, $set) {
                                                        // Try to get existing item_id from form state
                                                        $existingId = $get('item_id');
                                                        if (! empty($existingId)) {
                                                            return $existingId;
                                                        }

                                                        // If not in form state, check if we can get it from the array
                                                        // by matching variation_id + stock_id
                                                        $variationId = $get('variation_id');
                                                        $stockId = $get('stock_id');

                                                        if ($variationId && $stockId) {
                                                            // Try to get preparable_items from parent
                                                            try {
                                                                $preparableItems = $get('../../preparable_items') ?? [];
                                                                if (is_array($preparableItems)) {
                                                                    foreach ($preparableItems as $item) {
                                                                        if (($item['variation_id'] ?? null) == $variationId &&
                                                                            ($item['stock_id'] ?? null) == $stockId) {
                                                                            if (! empty($item['item_id'] ?? null)) {
                                                                                return $item['item_id'];
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            } catch (\Exception $e) {
                                                                // If we can't access parent, generate new ID
                                                            }
                                                        }

                                                        // Generate new ID if none found
                                                        $newId = (string) microtime(true);
                                                        // Set it immediately so it's available for matching
                                                        $set('item_id', $newId);

                                                        return $newId;
                                                    })
                                                    ->dehydrated(),
                                                Hidden::make('variation_id'),
                                                Hidden::make('stock_id'),
                                                Hidden::make('unit_discount'), // Per-unit discount amount
                                                Hidden::make('discount_type'), // 'percentage' or 'flat'
                                                Hidden::make('discount_percentage'), // Store percentage value if discount_type is 'percentage'
                                                Hidden::make('discount_amount'), // Store the calculated discount amount
                                                TextEntry::make('description')
                                                    ->label('Description')
                                                    ->default('Nested Product Item'),
                                                TextInput::make('quantity')
                                                    ->label('Quantity')
                                                    ->numeric()
                                                    ->default(1)
                                                    ->reactive()
                                                    ->live(onBlur: true)
                                                    ->extraInputAttributes([
                                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                        'data-sale-item-input' => 'true',
                                                        'x-on:focus' => '$event.target.select()',
                                                    ])
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        // CRITICAL: Ensure item_id is set before any operations
                                                        // This ensures we can always identify which item is being updated
                                                        $currentItemId = $get('item_id');
                                                        $variationId = $get('variation_id');
                                                        $stockId = $get('stock_id');

                                                        // If item_id is missing, try to get it from the parent array
                                                        if (empty($currentItemId) && $variationId && $stockId) {
                                                            try {
                                                                $preparableItems = $get('../../preparable_items') ?? [];
                                                                if (is_array($preparableItems)) {
                                                                    // Find the item by variation_id + stock_id (unique for different products)
                                                                    foreach ($preparableItems as $item) {
                                                                        if (($item['variation_id'] ?? null) == $variationId &&
                                                                            ($item['stock_id'] ?? null) == $stockId) {
                                                                            if (! empty($item['item_id'] ?? null)) {
                                                                                $currentItemId = $item['item_id'];
                                                                                $set('item_id', $currentItemId);
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            } catch (\Exception $e) {
                                                                // If we can't access parent, generate new ID
                                                            }
                                                        }

                                                        // If still no item_id, generate one (shouldn't happen, but safety net)
                                                        if (empty($currentItemId)) {
                                                            $currentItemId = (string) microtime(true);
                                                            $set('item_id', $currentItemId);
                                                        }

                                                        // Round to 6 decimal places, allow negative quantities
                                                        $quantity = round((float) ($state ?: 1), 6);
                                                        // Ensure quantity is not zero
                                                        if ($quantity == 0) {
                                                            $quantity = $quantity > 0 ? 1 : -1;
                                                        }
                                                        $set('quantity', $quantity);

                                                        // If discount type is percentage, recalculate the discount amount
                                                        if ($get('discount_type') === 'percentage') {
                                                            $unitPrice = (float) ($get('unit_price') ?: 0);
                                                            $percentage = (float) ($get('discount_percentage') ?? 0);
                                                            $subtotalBeforeDiscount = $unitPrice * abs($quantity);

                                                            // Recalculate discount amount from percentage
                                                            // Use absolute value for calculation, then apply sign
                                                            $discountAmount = ($subtotalBeforeDiscount * abs($percentage)) / 100;
                                                            // Apply negative sign if percentage is negative or quantity is negative
                                                            if ($percentage < 0 || $quantity < 0) {
                                                                $discountAmount = $discountAmount * -1;
                                                            }
                                                            $discountAmount = round($discountAmount, 2);

                                                            // Always preserve percentage format in display
                                                            $set('disc', self::formatPercentage($percentage));
                                                            // Update unit_discount - preserve sign when quantity is negative
                                                            if ($quantity != 0) {
                                                                $unitDiscount = $discountAmount / $quantity;
                                                            } else {
                                                                $unitDiscount = 0;
                                                            }
                                                            $set('unit_discount', round($unitDiscount, 2));
                                                            $set('discount_amount', $discountAmount);
                                                        } else {
                                                            // Recalculate discount based on unit_discount
                                                            $unitDiscount = (float) ($get('unit_discount') ?? 0);
                                                            if ($unitDiscount != 0) {
                                                                // Use quantity (not abs) to preserve sign when quantity is negative
                                                                $discount = round($unitDiscount * $quantity, 2);
                                                                $set('disc', self::formatNumberForState($discount));
                                                            } else {
                                                                // If no unit_discount, adjust sign based on quantity
                                                                $currentDiscount = (float) ($get('disc') ?? 0);
                                                                if ($quantity < 0 && $currentDiscount > 0) {
                                                                    // If quantity becomes negative and discount is positive, make discount negative
                                                                    $set('disc', self::formatNumberForState($currentDiscount * -1));
                                                                } elseif ($quantity >= 0 && $currentDiscount < 0) {
                                                                    // If quantity becomes positive and discount is negative, make discount positive
                                                                    $set('disc', self::formatNumberForState(abs($currentDiscount)));
                                                                }
                                                            }
                                                        }

                                                        SaleForm::recalcPreparableItemLine($get, $set);
                                                    }),
                                                TextInput::make('unit_price')
                                                    ->label('Price')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->step('0.01')
                                                    ->default(50.00)
                                                    ->reactive()
                                                    ->live(onBlur: true)
                                                    ->extraInputAttributes([
                                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                        'data-price-input' => 'true',
                                                        'data-sale-item-input' => 'true',
                                                        'x-on:focus' => '$event.target.select()',
                                                    ])
                                                    ->extraAttributes([
                                                        'class' => 'price-field-wrapper',
                                                        'x-data' => '{}',
                                                        'x-init' => "\$nextTick(() => {
                                                            const styleId = 'price-helper-style';
                                                            if (!document.getElementById(styleId)) {
                                                                const style = document.createElement('style');
                                                                style.id = styleId;
                                                                style.textContent = '.price-field-wrapper .fi-sc-text, .fi-input-wrapper:has(input[data-price-input]) .fi-sc-text { font-size: 0.3rem !important; line-height: 0.35rem !important; opacity: 0.5 !important; margin-top: 0.0625rem !important; color: rgb(107 114 128) !important; }';
                                                                document.head.appendChild(style);
                                                            }
                                                        })",
                                                    ])
                                                    ->helperText(function ($get) {
                                                        $unitPrice = (float) ($get('unit_price') ?? 0);
                                                        $quantity = (float) ($get('quantity') ?? 1);

                                                        // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                                                        $discountType = $get('discount_type');
                                                        if ($discountType === 'percentage') {
                                                            // For percentage discounts, use the calculated discount_amount
                                                            $discountAmount = (float) ($get('discount_amount') ?? 0);
                                                        } else {
                                                            // For flat discounts, use the disc field directly
                                                            $disc = $get('disc');
                                                            $discountAmount = is_string($disc) && str_contains($disc, '%') ? 0 : (float) $disc;
                                                        }

                                                        // Only show helper text if there's a discount applied and quantity > 0
                                                        if ($discountAmount != 0 && $unitPrice > 0 && abs($quantity) > 0) {
                                                            // Calculate per-unit discount
                                                            $unitDiscount = abs($discountAmount) / abs($quantity);
                                                            // Calculate price after discount per unit
                                                            $priceAfterDiscount = $unitPrice - $unitDiscount;

                                                            if ($priceAfterDiscount > 0) {
                                                                // Get smallest integer (floor)
                                                                $priceAfterDiscountInteger = (int) floor($priceAfterDiscount);

                                                                if ($priceAfterDiscountInteger > 0) {
                                                                    return "1x={$priceAfterDiscountInteger}";
                                                                }
                                                            }
                                                        }

                                                        return null;
                                                    })
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $unitPrice = round((float) $state, 2);
                                                        if ((float) $state !== $unitPrice) {
                                                            $set('unit_price', $unitPrice);
                                                        }

                                                        // If there's a percentage discount, recalculate discount_amount based on new unit_price
                                                        $discountType = $get('discount_type');
                                                        if ($discountType === 'percentage') {
                                                            $quantity = (float) ($get('quantity') ?: 1);
                                                            $subtotalBeforeDiscount = $unitPrice * abs($quantity);
                                                            $discountPercentage = (float) ($get('discount_percentage') ?? 0);

                                                            // Recalculate discount amount from percentage
                                                            $discountAmount = ($subtotalBeforeDiscount * $discountPercentage) / 100;
                                                            if ($quantity < 0) {
                                                                $discountAmount = $discountAmount * -1;
                                                            }
                                                            $discountAmount = round($discountAmount, 2);

                                                            // Update discount_amount and unit_discount
                                                            $set('discount_amount', $discountAmount);
                                                            if ($quantity != 0) {
                                                                $unitDiscount = $discountAmount / $quantity;
                                                            } else {
                                                                $unitDiscount = 0;
                                                            }
                                                            $set('unit_discount', round($unitDiscount, 2));

                                                            // Update display value
                                                            if ($quantity < 0) {
                                                                $set('disc', self::formatNumberForState($discountAmount));
                                                            } else {
                                                                $set('disc', self::formatPercentage($discountPercentage));
                                                            }
                                                        }

                                                        SaleForm::recalcPreparableItemLine($get, $set);
                                                    }),
                                                TextInput::make('disc')
                                                    ->label('Disc')
                                                    ->placeholder('Disc (10 or 10%)')
                                                    ->reactive()
                                                    ->live(onBlur: true)
                                                    ->extraInputAttributes([
                                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                        'data-sale-item-input' => 'true',
                                                        'x-on:focus' => '$event.target.select()',
                                                    ])
                                                    ->helperText(function ($get) {
                                                        $quantity = (float) ($get('quantity') ?? 1);
                                                        $disc = $get('disc');

                                                        // Don't show helper text for percentage discounts
                                                        if (is_string($disc) && str_contains($disc, '%')) {
                                                            return null;
                                                        }

                                                        // Only show hint if quantity > 1 and there's a discount
                                                        if (abs($quantity) > 1 && $disc !== null && $disc !== '') {
                                                            // Flat discount - use the discount value directly
                                                            $discountAmount = (float) $disc;

                                                            if ($discountAmount != 0) {
                                                                // Calculate per-unit discount
                                                                $unitDiscount = abs($discountAmount) / abs($quantity);
                                                                // Get smallest integer (floor)
                                                                $unitDiscountInteger = (int) floor($unitDiscount);

                                                                if ($unitDiscountInteger > 0) {
                                                                    return "1x={$unitDiscountInteger}";
                                                                }
                                                            }
                                                        }

                                                        return null;
                                                    })
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $quantity = (float) ($get('quantity') ?: 1);
                                                        $unitPrice = (float) ($get('unit_price') ?: 0);
                                                        $subtotalBeforeDiscount = $unitPrice * abs($quantity);

                                                        // Check if state contains percentage symbol
                                                        if (is_string($state) && str_contains($state, '%')) {
                                                            // Extract the percentage value
                                                            $rawPercentage = str_replace('%', '', trim($state));

                                                            // Validate format - must be numeric
                                                            if (! is_numeric($rawPercentage)) {
                                                                $set('disc', '');
                                                                $set('discount_type', null);
                                                                $set('discount_percentage', null);

                                                                return;
                                                            }

                                                            $percentage = (float) $rawPercentage;

                                                            // Validate percentage range based on quantity
                                                            // For returns (negative quantity), allow -999.999999 to 999.999999
                                                            // For normal sales (positive quantity), allow 0 to 999.999999
                                                            if ($quantity < 0) {
                                                                // Return case: allow negative percentages (-999.999999 to 999.999999)
                                                                if ($percentage < -999.999999) {
                                                                    $percentage = -999.999999;
                                                                } elseif ($percentage > 999.999999) {
                                                                    $percentage = 999.999999;
                                                                }
                                                            } else {
                                                                // Normal sale: allow 0 to 999.999999
                                                                if ($percentage < 0) {
                                                                    $percentage = 0;
                                                                } elseif ($percentage > 999.999999) {
                                                                    $percentage = 999.999999;
                                                                }
                                                            }

                                                            $percentage = round($percentage, 6); // Round to 6 decimals

                                                            // Calculate discount amount from percentage
                                                            // Use absolute value for calculation, then apply sign
                                                            $discountAmount = ($subtotalBeforeDiscount * abs($percentage)) / 100;
                                                            // Apply negative sign if percentage is negative or quantity is negative
                                                            if ($percentage < 0 || $quantity < 0) {
                                                                $discountAmount = $discountAmount * -1;
                                                            }
                                                            $discountAmount = round($discountAmount, 2);

                                                            // Store that this is a percentage discount
                                                            $set('discount_type', 'percentage');
                                                            $set('discount_percentage', $percentage);

                                                            // Update the display value - show percentage format
                                                            $set('disc', self::formatPercentage($percentage));

                                                            // Update unit_discount - preserve sign when quantity is negative
                                                            if ($quantity != 0) {
                                                                $unitDiscount = $discountAmount / $quantity;
                                                            } else {
                                                                $unitDiscount = 0;
                                                            }
                                                            $set('unit_discount', round($unitDiscount, 2));
                                                            // Store the calculated amount in a hidden field for calculations
                                                            $set('discount_amount', $discountAmount);
                                                        } else {
                                                            // Treat as flat discount amount and round to 2 decimal places
                                                            // Automatically set discount to negative when quantity is negative (return case)
                                                            $discountAmount = (float) $state;
                                                            if ($quantity < 0) {
                                                                // For negative quantity, automatically make discount negative
                                                                $discountAmount = abs($discountAmount) * -1;
                                                            } else {
                                                                // For positive quantity, ensure discount is non-negative
                                                                $discountAmount = max(0, $discountAmount);
                                                            }
                                                            $discountAmount = round($discountAmount, 2);
                                                            $set('disc', self::formatNumberForState($discountAmount));

                                                            // Store that this is a flat discount
                                                            $set('discount_type', 'flat');
                                                            $set('discount_percentage', null);

                                                            // Update unit_discount - preserve sign when quantity is negative
                                                            if ($quantity != 0) {
                                                                $unitDiscount = $discountAmount / $quantity;
                                                            } else {
                                                                $unitDiscount = 0;
                                                            }
                                                            $set('unit_discount', round($unitDiscount, 2));
                                                            $set('discount_amount', $discountAmount);
                                                        }

                                                        // Recalculate item line and parent variation
                                                        SaleForm::recalcPreparableItemLine($get, $set);
                                                    }),
                                                TextInput::make('total')
                                                    ->label('Total')
                                                    ->numeric()
                                                    ->default(50.00)
                                                    ->live(onBlur: true)
                                                    ->extraInputAttributes([
                                                        'class' => 'text-xs py-0.5 px-1.5 h-7',
                                                        'data-sale-item-input' => 'true',
                                                        'x-on:focus' => '$event.target.select()',
                                                    ])
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        // When total is changed, calculate new discount
                                                        $quantity = (float) ($get('quantity') ?: 1);
                                                        $rawTotal = round((float) $state, 2);
                                                        $newTotal = $quantity >= 0 ? round(max(0, $rawTotal), 2) : $rawTotal;
                                                        $set('total', $newTotal);

                                                        $unitPrice = (float) ($get('unit_price') ?: 0);

                                                        // Calculate what the discount should be: (unitPrice * quantity) - newTotal
                                                        $calculatedDisc = ($unitPrice * $quantity) - $newTotal;
                                                        // Allow negative discount when quantity is negative
                                                        if ($quantity >= 0) {
                                                            $calculatedDisc = max(0, $calculatedDisc);
                                                        }
                                                        $calculatedDisc = round($calculatedDisc, 2);

                                                        $set('disc', self::formatNumberForState($calculatedDisc));

                                                        // Update unit_discount based on quantity
                                                        if ($quantity != 0) {
                                                            $unitDiscount = $calculatedDisc / $quantity;
                                                        } else {
                                                            $unitDiscount = 0;
                                                        }
                                                        $set('unit_discount', round($unitDiscount, 2));

                                                        // Recalculate item line and parent variation
                                                        SaleForm::recalcPreparableItemLine($get, $set);
                                                    }),
                                            ])
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(4)
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan(7),
                        Grid::make()
                            ->schema([
                                Section::make()
                                    ->schema([
                                        TextInput::make('subtotal')
                                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                                            ->inlineLabel()
                                            ->disabled(),
                                        TextInput::make('total_tax')
                                            ->label('Tax')
                                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                                            ->inlineLabel()
                                            ->disabled()
                                            ->visible(fn ($record) => ($record?->store ?? Filament::getTenant())?->tax_enabled ?? false),
                                        Hidden::make('sale_discount_type'),
                                        Hidden::make('sale_discount_percentage'),
                                        Hidden::make('sale_discount_amount'), // Store the calculated discount amount
                                        TextInput::make('discount')
                                            ->label('Discount')
                                            ->placeholder('100 or 10%')
                                            ->live(onBlur: true)
                                            ->inlineLabel()
                                            ->extraInputAttributes([
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->afterStateHydrated(function ($state, $set, $get) {
                                                // When loading existing data, format percentage discounts correctly
                                                $saleDiscountType = $get('sale_discount_type');
                                                if ($saleDiscountType === 'percentage') {
                                                    $discountPercentage = (float) ($get('sale_discount_percentage') ?? 0);
                                                    if ($discountPercentage != 0) {
                                                        $set('discount', self::formatPercentage($discountPercentage));
                                                    }
                                                }
                                            })
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                // Check if any variation has negative quantity (return case)
                                                $variations = $get('variations') ?? [];
                                                $hasNegativeQuantity = false;
                                                foreach ($variations as $variation) {
                                                    if (isset($variation['quantity']) && (float) $variation['quantity'] < 0) {
                                                        $hasNegativeQuantity = true;
                                                        break;
                                                    }
                                                }

                                                // Check if state contains percentage symbol
                                                if (is_string($state) && str_contains($state, '%')) {
                                                    // Extract the percentage value
                                                    $rawPercentage = str_replace('%', '', trim($state));

                                                    // Validate format - must be numeric
                                                    if (! is_numeric($rawPercentage)) {
                                                        $set('discount', '');
                                                        $set('sale_discount_type', null);
                                                        $set('sale_discount_percentage', null);

                                                        return;
                                                    }

                                                    $percentage = (float) $rawPercentage;

                                                    // Validate percentage range based on whether there are negative quantities
                                                    // For returns (negative quantity items), allow -999.999999 to 999.999999
                                                    // For normal sales (all positive quantities), allow 0 to 999.999999
                                                    if ($hasNegativeQuantity) {
                                                        // Return case: allow negative percentages (-999.999999 to 999.999999)
                                                        if ($percentage < -999.999999) {
                                                            $percentage = -999.999999;
                                                        } elseif ($percentage > 999.999999) {
                                                            $percentage = 999.999999;
                                                        }
                                                    } else {
                                                        // Normal sale: allow 0 to 999.999999
                                                        if ($percentage < 0) {
                                                            $percentage = 0;
                                                        } elseif ($percentage > 999.999999) {
                                                            $percentage = 999.999999;
                                                        }
                                                    }

                                                    $percentage = round($percentage, 6);

                                                    // Store percentage discount type
                                                    $set('sale_discount_type', 'percentage');
                                                    $set('sale_discount_percentage', $percentage);

                                                    // Calculate discount amount from subtotal
                                                    $subtotal = (float) ($get('subtotal') ?? 0);
                                                    // Use absolute value for calculation, then apply sign
                                                    $discountAmount = ($subtotal * abs($percentage)) / 100;
                                                    // Apply negative sign if percentage is negative
                                                    if ($percentage < 0) {
                                                        $discountAmount = $discountAmount * -1;
                                                    }

                                                    // Allow negative discount when any item has negative quantity
                                                    if (! $hasNegativeQuantity && $discountAmount < 0) {
                                                        $discountAmount = 0;
                                                    }

                                                    $discountAmount = round($discountAmount, 2);

                                                    // Store the calculated discount amount for saving to DB
                                                    $set('sale_discount_amount', $discountAmount);

                                                    // Update display - preserve percentage format
                                                    $set('discount', self::formatPercentage($percentage));

                                                    // Recalculate totals applying global discount
                                                    SaleForm::recalcSummary($get, $set);

                                                    // Ensure percentage format is preserved after recalculation
                                                    $set('discount', self::formatPercentage($percentage));

                                                    return;
                                                }

                                                // Flat discount amount
                                                $discount = (float) $state;
                                                if (! $hasNegativeQuantity) {
                                                    $discount = max(0, $discount);
                                                }
                                                $discount = round($discount, 2);

                                                // Store the discount amount for saving to DB
                                                $set('sale_discount_amount', $discount);
                                                $set('discount', self::formatNumberForState($discount));

                                                // Clear percentage discount type
                                                $set('sale_discount_type', 'flat');
                                                $set('sale_discount_percentage', null);

                                                // Recalculate totals applying global discount
                                                SaleForm::recalcSummary($get, $set);
                                            }),
                                        TextInput::make('freight_fare')
                                            ->label('Freight Fare')
                                            ->prefix(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                                            ->live(onBlur: true)
                                            ->inlineLabel()
                                            ->default(0)
                                            ->numeric()
                                            ->extraInputAttributes([
                                                'x-on:focus' => '$event.target.select()',
                                            ])
                                            ->afterStateUpdated(function ($state, $set, $get) {
                                                $freightFare = round(max(0, (float) $state), 2);
                                                $set('freight_fare', $freightFare);
                                                // Recalculate totals including freight fare
                                                SaleForm::recalcSummary($get, $set);
                                            }),
                                        TextInput::make('total')
                                            ->label('Total')
                                            ->prefix(fn ($record) => $record?->store?->currency?->code ?? Filament::getTenant()?->currency->code ?? 'PKR')
                                            ->inlineLabel()
                                            ->disabled(),
                                    ])
                                    ->columnSpanFull(),
                                Section::make()
                                    ->schema([
                                        Select::make('customer_id')
                                            ->relationship('customer', 'name')
                                            ->searchable(['name', 'phone'])
                                            ->preload()
                                            ->getOptionLabelFromRecordUsing(fn (Model $record) => "$record->name - $record->phone")
                                            ->createOptionForm(fn (Schema $schema) => CustomerForm::configure($schema))
                                            ->createOptionModalHeading('New Customer'),
                                        \Filament\Forms\Components\Textarea::make('note')
                                            ->label('Note')
                                            ->placeholder('Add a note for this sale (optional)')
                                            ->rows(3)
                                            ->maxLength(1000)
                                            ->extraInputAttributes([
                                                'x-on:focus' => '$event.target.select()',
                                            ]),
                                    ])
                                    ->columnSpanFull(),
                                Section::make()
                                    ->schema([
                                        Select::make('payment_status')
                                            ->label('Payment Status')
                                            ->options(fn () => collect(SalePaymentStatus::cases())
                                                ->filter(fn ($status) => $status !== SalePaymentStatus::Refunded)
                                                ->mapWithKeys(fn ($status) => [$status->value => $status->getLabel()])
                                                ->toArray())
                                            ->default(SalePaymentStatus::default())
                                            ->searchable()
                                            ->preload(),
                                        Select::make('payment_method')
                                            ->label('Payment Method')
                                            ->options(SalePaymentMethod::class)
                                            ->default(SalePaymentMethod::default())
                                            ->required(fn ($get) => (self::getPaymentStatusValue($get) === SalePaymentStatus::Paid->value))
                                            ->visible(fn ($get) => (self::getPaymentStatusValue($get) === SalePaymentStatus::Paid->value))
                                            ->dehydrated(fn ($get) => (self::getPaymentStatusValue($get) === SalePaymentStatus::Paid->value))
                                            ->searchable()
                                            ->preload(),
                                        Toggle::make('use_fbr')
                                            ->label('Generate FBR Invoice')
                                            ->default(false)
                                            ->visible(function () {
                                                $store = filament()->getTenant();
                                                if (! $store) {
                                                    return false;
                                                }
                                                // Show toggle only if store is in Pakistan AND taxes are enabled AND POSID is configured
                                                if (($store?->country?->code ?? null) !== 'PK') {
                                                    return false;
                                                }
                                                if (! $store->tax_enabled) {
                                                    return false;
                                                }
                                                // Show toggle only if POSID is configured for the current environment
                                                $hasPosId = $store->fbr_environment === \SmartTill\Core\Enums\FbrEnvironment::SANDBOX
                                                    ? ! empty($store->fbr_sandbox_pos_id)
                                                    : ! empty($store->fbr_pos_id);

                                                return $hasPosId;
                                            })
                                            ->helperText('Enable this to generate an FBR invoice for this sale'),
                                    ])
                                    ->columnSpanFull(),
                                Actions::make([
                                    Action::make('complete')
                                        ->color('success')
                                        ->label('Complete')
                                        ->size('md')
                                        ->action(function ($state) {
                                            return app(static::class)->handleCheckout($state, SaleStatus::Completed);
                                        }),
                                    Action::make('pending')
                                        ->color('warning')
                                        ->label('Pending')
                                        ->size('md')
                                        ->action(function ($state) {
                                            return app(static::class)->handleCheckout($state, SaleStatus::Pending);
                                        }),
                                ])
                                    ->columnSpanFull()
                                    ->key('checkout-actions')
                                    ->extraAttributes([
                                        'class' => 'gap-2',
                                    ]),
                            ])
                            ->columnSpan(3),
                    ])
                    ->columns(10)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Search variations from a cached collection.
     */
    private static function searchVariations($variations, string $search)
    {
        $search = trim($search);
        if ($search === '') {
            return $variations;
        }

        $originalTerm = $search;

        // Tokenize search term
        $rawTokens = preg_split('/\s+/', $search);
        $tokens = array_values(array_filter(array_map(function ($t) {
            $clean = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $t ?? '');
            $clean = ltrim($clean, '+-~><()|"');
            $clean = rtrim($clean, '+-~><()|"');
            $clean = trim($clean, '-_');

            if ($clean === '' || preg_match('/^[-_]+$/', $clean)) {
                return null;
            }

            return $clean;
        }, $rawTokens)));

        if (empty($tokens)) {
            return $variations;
        }

        // Check if it looks like a SKU
        $isSingleToken = count($tokens) === 1;
        $hasSkuDelimiter = str_contains($originalTerm, '-') || str_contains($originalTerm, '_');
        $looksLikeSku = $isSingleToken && $hasSkuDelimiter && preg_match('/^[A-Za-z0-9_-]{3,}$/', $originalTerm);

        $searchLower = strtolower($search);
        $originalTermLower = strtolower($originalTerm);

        return $variations->filter(function ($variation) use ($originalTermLower, $tokens, $looksLikeSku) {
            // Use pre-processed lowercase versions from cache
            $sku = $variation['sku_lower'] ?? strtolower($variation['sku'] ?? $variation->sku ?? '');
            $description = $variation['description_lower'] ?? strtolower($variation['description'] ?? $variation->description ?? '');

            // SKU-like search: exact match or starts with
            if ($looksLikeSku) {
                return $sku === $originalTermLower || str_starts_with($sku, $originalTermLower);
            }

            // Check exact matches first (highest priority)
            if ($sku === $originalTermLower || $description === $originalTermLower) {
                return true;
            }

            // Check prefix matches
            if (str_starts_with($sku, $originalTermLower) || str_starts_with($description, $originalTermLower)) {
                return true;
            }

            // Check if all tokens are found in SKU or description
            foreach ($tokens as $token) {
                $tokenLower = strtolower($token);
                $tokenFound = str_contains($sku, $tokenLower) || str_contains($description, $tokenLower);

                // Check dashed description (like P-O-P)
                if (! $tokenFound) {
                    $dashedTerm = str_replace(' ', '', $originalTermLower);
                    if (str_contains($dashedTerm, '-') && str_contains($description, $dashedTerm)) {
                        $tokenFound = true;
                    }
                }

                if (! $tokenFound) {
                    return false;
                }
            }

            return true;
        })->sortBy(function ($variation) use ($originalTermLower) {
            // Use pre-processed lowercase versions from cache
            $sku = $variation['sku_lower'] ?? strtolower($variation['sku'] ?? $variation->sku ?? '');
            $description = $variation['description_lower'] ?? strtolower($variation['description'] ?? $variation->description ?? '');

            // Priority ordering:
            // 1. Exact SKU match (highest priority)
            if ($sku === $originalTermLower) {
                return 0;
            }
            // 2. SKU starts with search term
            if (str_starts_with($sku, $originalTermLower)) {
                return 1;
            }
            // 3. Description exact match
            if ($description === $originalTermLower) {
                return 2;
            }
            // 4. Description starts with search term
            if (str_starts_with($description, $originalTermLower)) {
                return 3;
            }

            // 5. Everything else
            return 4;
        })->values();
    }

    /**
     * Format percentage for display (6 decimals, remove trailing zeros).
     * Examples: 12.123456 -> "12.123456%", 12.000000 -> "12%"
     */
    public static function formatPercentage(float $percentage): string
    {
        $formatted = number_format($percentage, 6, '.', '');
        // Remove trailing zeros and decimal point if not needed
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted.'%';
    }

    /**
     * Format a numeric value as a stable string for Livewire state.
     */
    public static function formatNumberForState(float $value, int $decimals = 2): string
    {
        $formatted = number_format($value, $decimals, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Recalculate and set the overall cart total from a products array path.
     */
    private static function recalcSummary(
        callable $get,
        callable $set,
        string $variationsPath = 'variations',
        string $preparableVariationsPath = 'preparable_variations',
        string $preparableItemsPath = 'preparable_items',
        string $subtotalPath = 'subtotal',
        string $totalPath = 'total',
        string $totalTaxPath = 'total_tax',
        string $discountPath = 'discount',
        string $freightFarePath = 'freight_fare',
    ): void {
        $variations = $get($variationsPath) ?? [];

        // Subtotal: sum of all ((unit_price * quantity) - line discount amount)
        $subtotal = array_sum(array_map(static function ($item) {
            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 1;
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;

            // Use discount_amount for percentage discounts, otherwise use discount field
            // Allow negative discount when quantity is negative (return case)
            if (isset($item['discount_type']) && $item['discount_type'] === 'percentage') {
                // For percentage discounts, use discount_amount directly (already has correct sign)
                $discount = isset($item['discount_amount']) ? (float) $item['discount_amount'] : 0;
                // Only enforce non-negative discount when quantity is positive
                if ($quantity >= 0) {
                    $discount = max(0, $discount);
                }
            } else {
                // For flat discounts, use the discount field directly (already calculated with correct sign)
                $discount = isset($item['discount']) ? (float) $item['discount'] : 0;
                // Only enforce non-negative discount when quantity is positive
                if ($quantity >= 0) {
                    $discount = max(0, $discount);
                }
            }

            // Calculate line total: (unitPrice * quantity) - discount
            // When discount is negative, this effectively adds to the total (return case with fee)
            return ($unitPrice * $quantity) - $discount;
        }, $variations));

        // Calculate preparable products subtotal
        // A sale can have both regular variations AND preparable_variations at the same time
        $preparableSubtotal = 0;

        // Get preparable_variations using the determined path
        $preparableVariations = $get($preparableVariationsPath) ?? [];

        // Ensure it's an array
        if (! is_array($preparableVariations)) {
            $preparableVariations = [];
        }

        // Calculate subtotal from all preparable variations
        foreach ($preparableVariations as $preparableVariation) {
            $preparableQty = isset($preparableVariation['qty']) ? (float) $preparableVariation['qty'] : 1;
            $preparablePrice = isset($preparableVariation['price']) ? (float) $preparableVariation['price'] : 0;

            // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
            $discountType = isset($preparableVariation['discount_type']) ? $preparableVariation['discount_type'] : 'flat';
            if ($discountType === 'percentage') {
                $preparableDisc = isset($preparableVariation['discount_amount']) ? (float) $preparableVariation['discount_amount'] : 0;
            } else {
                $preparableDisc = isset($preparableVariation['disc']) ? (float) $preparableVariation['disc'] : 0;
            }

            // Calculate total from nested preparable_items
            // preparable_items is nested within each preparable_variation array item
            // Use preparableItemsPath as the field name within each preparable_variation
            $preparableItems = $preparableVariation[$preparableItemsPath] ?? [];
            if (! is_array($preparableItems)) {
                $preparableItems = [];
            }

            $itemsTotal = 0;
            foreach ($preparableItems as $item) {
                $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;

                // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                $itemDiscountType = isset($item['discount_type']) ? $item['discount_type'] : 'flat';
                if ($itemDiscountType === 'percentage') {
                    $itemDisc = isset($item['discount_amount']) ? (float) $item['discount_amount'] : 0;
                } else {
                    $itemDisc = isset($item['disc']) ? (float) $item['disc'] : 0;
                }

                // Calculate item total: (unit_price * quantity) - discount
                $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
            }

            // Preparable variation total calculation:
            // Sum of (preparable variation price + nested items total) * quantity, then subtract preparable discount
            // Formula: ((preparable_price + items_total) * preparable_qty) - preparable_disc
            $preparableSubtotal += (($preparablePrice + $itemsTotal) * $preparableQty) - $preparableDisc;
        }

        // Add preparable subtotal to regular subtotal
        $subtotal += $preparableSubtotal;

        $set($subtotalPath, round($subtotal, 2));

        // Total tax: sum of all (tax * quantity)
        $totalTax = array_sum(array_map(static function ($item) {
            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 1;
            $tax = isset($item['tax']) ? (float) $item['tax'] : 0;

            return $tax * $quantity;
        }, $variations));
        $set($totalTaxPath, round($totalTax, 2));

        // Sale-level discount (applied to subtotal after line discounts)
        // Check if any variation has negative quantity (return case)
        $hasNegativeQuantity = false;
        foreach ($variations as $variation) {
            if (isset($variation['quantity']) && (float) $variation['quantity'] < 0) {
                $hasNegativeQuantity = true;
                break;
            }
        }

        // Check if sale-level discount is percentage or flat
        $saleDiscountType = $get('sale_discount_type') ?? 'flat';
        $discountValue = $get($discountPath);

        if ($saleDiscountType === 'percentage') {
            // Percentage discount - calculate from subtotal
            $discountPercentage = (float) ($get('sale_discount_percentage') ?? 0);
            // Use absolute value for calculation, then apply sign
            $discountAmount = ($subtotal * abs($discountPercentage)) / 100;
            // Apply negative sign if percentage is negative
            if ($discountPercentage < 0) {
                $discountAmount = $discountAmount * -1;
            }

            // Allow negative discount when any item has negative quantity
            if (! $hasNegativeQuantity && $discountAmount < 0) {
                $discountAmount = 0;
            }

            // Ensure percentage format is preserved in display
            $set($discountPath, self::formatPercentage($discountPercentage));

            // Store the calculated discount amount for saving to DB
            $set('sale_discount_amount', $discountAmount);
        } else {
            // Flat discount amount
            // Handle both numeric and string (with %) values
            if (is_string($discountValue) && str_contains($discountValue, '%')) {
                // If somehow a percentage string got through, extract it
                $rawPercentage = str_replace('%', '', trim($discountValue));
                if (is_numeric($rawPercentage)) {
                    $discountPercentage = (float) $rawPercentage;
                    if ($discountPercentage < 0) {
                        $discountPercentage = 0;
                    } elseif ($discountPercentage > 100) {
                        $discountPercentage = 100;
                    }
                    $discountPercentage = round($discountPercentage, 6);
                    $discountAmount = ($subtotal * $discountPercentage) / 100;
                } else {
                    $discountAmount = 0;
                }
            } else {
                $discountAmount = (float) $discountValue;
            }

            // Allow negative discount when any item has negative quantity
            if (! $hasNegativeQuantity) {
                $discountAmount = max(0, $discountAmount);
            }
        }

        $discountAmount = round($discountAmount, 2);
        // Freight fare (added after discount) - use provided path or default to 'freight_fare'
        $freightFarePathToUse = $freightFarePath ?? 'freight_fare';
        $freightFare = round(max(0, (float) ($get($freightFarePathToUse) ?? 0)), 2);
        $total = round($subtotal - $discountAmount + $freightFare, 2);
        $set($totalPath, $total);
    }

    /**
     * Recalculate the repeater item's line price based on quantity and discount,
     * then update the overall summary total.
     */
    private static function recalcLine(
        callable $get,
        callable $set,
        string $variationsPath = '../../variations',
        string $subtotalPath = '../../subtotal',
        string $totalPath = '../../total',
        string $totalTaxPath = '../../total_tax',
        string $discountPath = '../../discount',
    ): void {
        $quantity = (float) ($get('quantity') ?: 1); // Allow negative, disallow zero via validation
        $unitDiscount = (float) ($get('unit_discount') ?? 0);

        // Get the actual discount amount to use in calculations
        // For percentage discounts, use discount_amount; otherwise use the discount field value
        $discountType = $get('discount_type');
        if ($discountType === 'percentage') {
            $discount = round((float) ($get('discount_amount') ?? 0), 2);
            // Always preserve percentage format in display (can be negative like -12.344%)
            $discountPercentage = (float) ($get('discount_percentage') ?? 0);
            $set('discount', self::formatPercentage($discountPercentage));
        } else {
            // Use quantity (not abs) to preserve sign when quantity is negative
            $discount = round($unitDiscount * $quantity, 2);
            $set('discount', self::formatNumberForState($discount));
        }

        $set('quantity', $quantity);

        $unitPrice = $get('unit_price');

        $lineTotal = ($unitPrice * $quantity) - $discount;

        $set('total', round($lineTotal, 2));

        // Determine freight_fare path based on variations path (if variations is '../../variations', freight_fare should be '../../freight_fare')
        $freightFarePath = str_contains($variationsPath, '../') ? '../../freight_fare' : 'freight_fare';
        // Determine preparable_variations path based on variations path
        $preparableVariationsPath = str_replace('variations', 'preparable_variations', $variationsPath);
        // preparable_items is nested within each preparable_variation, so field name is always 'preparable_items'
        self::recalcSummary($get, $set, $variationsPath, $preparableVariationsPath, 'preparable_items', $subtotalPath, $totalPath, $totalTaxPath, $discountPath, $freightFarePath);
    }

    /**
     * Recalculate a preparable variation's line total based on its price, qty, disc, and nested items.
     */
    private static function recalcPreparableVariationLine(
        callable $get,
        callable $set,
        string $preparableVariationsPath = '../../preparable_variations',
        string $subtotalPath = '../../subtotal',
        string $totalPath = '../../total',
        string $totalTaxPath = '../../total_tax',
        string $discountPath = '../../discount',
    ): void {
        // Get current field values
        $preparableQty = (float) ($get('qty') ?: 1);
        $preparablePrice = (float) ($get('price') ?: 0);
        $unitDiscount = (float) ($get('unit_discount') ?? 0);

        // Get the actual discount amount to use in calculations
        // For percentage discounts, use discount_amount; otherwise use the discount field value
        $discountType = $get('discount_type');

        if ($discountType === 'percentage') {
            $preparableDisc = round((float) ($get('discount_amount') ?? 0), 2);
            $discountPercentage = (float) ($get('discount_percentage') ?? 0);

            // Always preserve percentage format when it's a percentage discount
            // Show percentage format (can be negative like -12.344%)
            $set('disc', self::formatPercentage($discountPercentage));
        } else {
            // Use quantity (not abs) to preserve sign when quantity is negative
            $preparableDisc = round($unitDiscount * $preparableQty, 2);
            $set('disc', self::formatNumberForState($preparableDisc));
        }

        // Get preparable_variations array
        $preparableVariations = $get($preparableVariationsPath) ?? [];
        if (empty($preparableVariations) || ! is_array($preparableVariations)) {
            return;
        }

        // Find the current preparable variation item in the array
        // Prioritize instance_id matching (most reliable for multiple instances of same variation)
        $currentInstanceId = $get('instance_id');
        $currentVariationId = $get('variation_id');
        $currentDescription = $get('description');
        $currentIndex = null;
        $preparableItems = [];

        foreach ($preparableVariations as $index => $variation) {
            // FIRST: Try to match by instance_id (most reliable)
            if ($currentInstanceId && ($variation['instance_id'] ?? null) == $currentInstanceId) {
                $currentIndex = $index;
                $preparableItems = $variation['preparable_items'] ?? [];
                if (! is_array($preparableItems)) {
                    $preparableItems = [];
                }
                break;
            }
        }

        // SECOND: If instance_id matching failed, try variation_id + description
        if ($currentIndex === null) {
            foreach ($preparableVariations as $index => $variation) {
                if (($variation['variation_id'] ?? null) == $currentVariationId ||
                    ($variation['description'] ?? null) == $currentDescription) {
                    // Only match if this variation has no items yet (to avoid matching wrong instance)
                    $variationItems = $variation['preparable_items'] ?? [];
                    if (empty($variationItems)) {
                        $currentIndex = $index;
                        $preparableItems = [];
                        break;
                    }
                }
            }
        }

        // THIRD: If we still couldn't find the item, use the last one as fallback
        if ($currentIndex === null && ! empty($preparableVariations)) {
            $currentIndex = count($preparableVariations) - 1;
            $preparableItems = $preparableVariations[$currentIndex]['preparable_items'] ?? [];
            if (! is_array($preparableItems)) {
                $preparableItems = [];
            }
        }

        // Calculate total from all nested items
        $itemsTotal = 0;
        foreach ($preparableItems as $item) {
            $itemQty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
            $itemUnitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0;
            $itemDisc = isset($item['disc']) ? (float) $item['disc'] : 0;
            $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
        }

        // Calculate preparable variation total: ((preparable_price + items_total) * preparable_qty) - preparable_disc
        $preparableTotal = round((($preparablePrice + $itemsTotal) * $preparableQty) - $preparableDisc, 2);

        // Update the total field
        $set('total', $preparableTotal);

        // Update the preparable_variations array to persist the values
        if ($currentIndex !== null && isset($preparableVariations[$currentIndex])) {
            $preparableVariations[$currentIndex]['qty'] = $preparableQty;
            $preparableVariations[$currentIndex]['price'] = $preparablePrice;
            // For percentage discounts, store the percentage format in disc field, not the calculated amount
            if ($discountType === 'percentage') {
                $discountPercentage = (float) ($get('discount_percentage') ?? 0);
                $preparableVariations[$currentIndex]['disc'] = self::formatPercentage($discountPercentage);
            } else {
                $preparableVariations[$currentIndex]['disc'] = self::formatNumberForState($preparableDisc);
            }
            $preparableVariations[$currentIndex]['total'] = $preparableTotal;
            if (! isset($preparableVariations[$currentIndex]['preparable_items'])) {
                $preparableVariations[$currentIndex]['preparable_items'] = $preparableItems;
            }
        }

        // Update the entire array
        $set($preparableVariationsPath, array_values($preparableVariations));

        // Determine paths for recalcSummary
        $freightFarePath = str_contains($preparableVariationsPath, '../') ? '../../freight_fare' : 'freight_fare';
        $variationsPath = str_replace('preparable_variations', 'variations', $preparableVariationsPath);

        // Recalculate summary
        self::recalcSummary($get, $set, $variationsPath, $preparableVariationsPath, 'preparable_items', $subtotalPath, $totalPath, $totalTaxPath, $discountPath, $freightFarePath);

        // Final step: Always ensure percentage format is preserved after all calculations
        // This must be the last thing we do to prevent any other operations from overwriting it
        if ($discountType === 'percentage') {
            $discountPercentage = (float) ($get('discount_percentage') ?? 0);
            $set('disc', self::formatPercentage($discountPercentage));
        }
    }

    /**
     * Recalculate a preparable item's line total.
     */
    private static function recalcPreparableItemLine(
        callable $get,
        callable $set,
        string $preparableVariationsPath = '../../../../preparable_variations',
        string $subtotalPath = '../../../../subtotal',
        string $totalPath = '../../../../total',
        string $totalTaxPath = '../../../../total_tax',
        string $discountPath = '../../../../discount',
    ): void {
        $quantity = (float) ($get('quantity') ?: 1);
        $unitPrice = (float) ($get('unit_price') ?: 0);
        $unitDiscount = (float) ($get('unit_discount') ?? 0);

        // Get the actual discount amount to use in calculations
        // For percentage discounts, use discount_amount; otherwise use the discount field value
        $discountType = $get('discount_type');
        if ($discountType === 'percentage') {
            $disc = round((float) ($get('discount_amount') ?? 0), 2);
            $discountPercentage = (float) ($get('discount_percentage') ?? 0);
            // Update display to show negative discount amount when quantity is negative, otherwise show percentage
            if ($quantity < 0 && $disc < 0) {
                $set('disc', self::formatNumberForState($disc));
            } else {
                // Show percentage format when quantity is positive
                $set('disc', self::formatPercentage($discountPercentage));
            }
        } else {
            $discValue = $get('disc');
            if (is_string($discValue) && str_contains($discValue, '%')) {
                $disc = 0.0;
            } elseif ($discValue !== null && $discValue !== '') {
                $disc = round((float) $discValue, 2);
            } else {
                // Use quantity (not abs) to preserve sign when quantity is negative
                $disc = round($unitDiscount * $quantity, 2);
            }
            $set('disc', self::formatNumberForState($disc));
            $set('discount_amount', $disc);
            if ($quantity != 0) {
                $unitDiscount = $disc / $quantity;
                $set('unit_discount', round($unitDiscount, 2));
            }
        }

        // Calculate item total: (unit_price * quantity) - discount
        $total = round(($unitPrice * $quantity) - $disc, 2);
        $set('total', $total);

        // Get preparable_variations array
        $preparableVariations = $get($preparableVariationsPath) ?? [];
        if (empty($preparableVariations) || ! is_array($preparableVariations)) {
            return;
        }

        // NEW APPROACH: Get ALL current item field values as a snapshot BEFORE updating
        // This ensures we have complete data about the item being edited
        $currentItemSnapshot = [
            'item_id' => $get('item_id'),
            'variation_id' => $get('variation_id'),
            'stock_id' => $get('stock_id'),
            'description' => $get('description'),
        ];

        // CRITICAL: If variation_id or stock_id are missing, we can't safely identify the item
        if (empty($currentItemSnapshot['variation_id']) || empty($currentItemSnapshot['stock_id'])) {
            return;
        }

        // DEBUG: Log what we're reading (can be removed later)
        // \Log::info('recalcPreparableItemLine - Reading form fields', [
        //     'item_id' => $currentItemId,
        //     'variation_id' => $currentVariationId,
        //     'stock_id' => $currentStockId,
        //     'description' => $currentDescription,
        // ]);

        // Get the parent preparable variation's identifier
        // We're in preparable_variations.{index}.preparable_items.{itemIndex}
        // So we need to go up two levels to get the preparable variation's instance_id (most reliable)
        $parentInstanceId = $get('../../instance_id');
        $parentPreparableVariationId = $get('../../variation_id');
        $parentPreparableDescription = $get('../../description');

        // Find the specific preparable variation that contains this item
        // Prioritize instance_id matching (most reliable for multiple instances of same variation)
        $found = false;
        $foundParentIndex = null; // Store the index of the parent variation we found
        foreach ($preparableVariations as $variationIndex => &$variation) {
            // First, check if this is the correct parent preparable variation
            $isParentVariation = false;

            // FIRST: Try to match by instance_id (most reliable)
            if ($parentInstanceId && ($variation['instance_id'] ?? null) == $parentInstanceId) {
                $isParentVariation = true;
            } elseif ($parentPreparableVariationId && ($variation['variation_id'] ?? null) == $parentPreparableVariationId) {
                // SECOND: Fallback to variation_id (less reliable when multiple instances exist)
                // Only use this if instance_id matching failed
                $isParentVariation = true;
            } elseif ($parentPreparableDescription && ($variation['description'] ?? null) == $parentPreparableDescription) {
                // THIRD: Fallback to description (least reliable)
                $isParentVariation = true;
            }

            if (! $isParentVariation) {
                continue;
            }

            // Now search only within this preparable variation's items
            $preparableItems = $variation['preparable_items'] ?? [];
            if (! is_array($preparableItems)) {
                continue;
            }

            // Ensure all items in the array have item_id (for items that might have been added before item_id was implemented)
            foreach ($preparableItems as &$item) {
                if (! isset($item['item_id']) || empty($item['item_id'])) {
                    $item['item_id'] = (string) microtime(true);
                }
            }
            unset($item);

            // NEW APPROACH: Update the entire array by finding and updating the matching item
            // Match by item_id first (most reliable), then fallback to variation_id + stock_id
            $updated = false;
            foreach ($preparableItems as $itemIndex => &$item) {
                // Try to match by item_id first
                $matchesById = ! empty($currentItemSnapshot['item_id']) &&
                               isset($item['item_id']) &&
                               $item['item_id'] === $currentItemSnapshot['item_id'];

                // Fallback: match by variation_id + stock_id (unique for different products)
                $matchesByVariation = ($item['variation_id'] ?? null) == $currentItemSnapshot['variation_id'] &&
                                      ($item['stock_id'] ?? null) == $currentItemSnapshot['stock_id'];

                if ($matchesById || ($matchesByVariation && empty($currentItemSnapshot['item_id']))) {
                    // Found the item - preserve ALL identifying fields
                    $preservedVariationId = $item['variation_id'] ?? null;
                    $preservedStockId = $item['stock_id'] ?? null;
                    $preservedDescription = $item['description'] ?? null;
                    $preservedItemId = $item['item_id'] ?? null;

                    // Update the item with new calculated values
                    $item['quantity'] = $quantity;
                    $item['unit_price'] = $unitPrice;
                    $item['unit_discount'] = $unitDiscount;
                    $item['discount_type'] = $discountType;
                    $item['discount_amount'] = $disc;
                    $item['total'] = $total;

                    // Handle percentage discount display
                    if ($discountType === 'percentage') {
                        $discountPercentage = (float) ($get('discount_percentage') ?? 0);
                        $item['disc'] = self::formatPercentage($discountPercentage);
                        $item['discount_percentage'] = $discountPercentage;
                    } else {
                        $item['disc'] = self::formatNumberForState($disc);
                    }

                    // CRITICAL: Always preserve identifying fields to prevent item from changing
                    $item['item_id'] = $preservedItemId ?: (string) microtime(true);
                    $item['variation_id'] = $preservedVariationId;
                    $item['stock_id'] = $preservedStockId;
                    if ($preservedDescription !== null) {
                        $item['description'] = $preservedDescription;
                    }

                    // Update form fields to match the array
                    $set('item_id', $item['item_id']);
                    $set('variation_id', $preservedVariationId);
                    $set('stock_id', $preservedStockId);
                    if ($preservedDescription !== null) {
                        $set('description', $preservedDescription);
                    }

                    $updated = true;

                    break;
                }
            }
            unset($item);

            if ($updated) {
                // Update the entire preparable_items array at once
                $variation['preparable_items'] = array_values($preparableItems);
                $found = true;
                $foundParentIndex = $variationIndex; // Store the index of the parent we found

                break;
            } else {
                // No match found - skip update to prevent errors
                continue; // Try next variation instead of returning
            }
            unset($item);
        }
        unset($variation);

        if ($found && $foundParentIndex !== null) {
            // Update the entire array first
            $set($preparableVariationsPath, array_values($preparableVariations));

            // Recalculate the parent preparable variation total using the stored index
            $variation = &$preparableVariations[$foundParentIndex];
            $preparableItems = $variation['preparable_items'] ?? [];

            // Found the parent variation, recalculate its total
            $preparableQty = isset($variation['qty']) ? (float) $variation['qty'] : 1;
            $preparablePrice = isset($variation['price']) ? (float) $variation['price'] : 0;

            // Get discount - handle percentage discounts
            $preparableDiscountType = isset($variation['discount_type']) ? $variation['discount_type'] : 'flat';
            if ($preparableDiscountType === 'percentage') {
                $preparableDisc = isset($variation['discount_amount']) ? (float) ($variation['discount_amount'] ?? 0) : 0;
            } else {
                $discValue = $variation['disc'] ?? 0;
                if (is_string($discValue) && str_contains($discValue, '%')) {
                    $preparableDisc = 0;
                } else {
                    $preparableDisc = (float) $discValue;
                }
            }

            $itemsTotal = 0;
            foreach ($preparableItems as $prepItem) {
                $itemQty = isset($prepItem['quantity']) ? (float) $prepItem['quantity'] : 1;
                $itemUnitPrice = isset($prepItem['unit_price']) ? (float) $prepItem['unit_price'] : 0;

                // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                $itemDiscountType = isset($prepItem['discount_type']) ? $prepItem['discount_type'] : 'flat';
                if ($itemDiscountType === 'percentage') {
                    $itemDisc = isset($prepItem['discount_amount']) ? (float) ($prepItem['discount_amount'] ?? 0) : 0;
                } else {
                    $itemDiscValue = $prepItem['disc'] ?? 0;
                    if (is_string($itemDiscValue) && str_contains($itemDiscValue, '%')) {
                        $itemDisc = 0;
                    } else {
                        $itemDisc = (float) $itemDiscValue;
                    }
                }

                $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
            }

            $preparableTotal = round((($preparablePrice + $itemsTotal) * $preparableQty) - $preparableDisc, 2);

            $preparableVariations[$foundParentIndex]['total'] = $preparableTotal;
            $set($preparableVariationsPath, array_values($preparableVariations));

            // Also update the total field directly in the form
            $set('../../total', $preparableTotal);
        } elseif ($found) {
            // Fallback: if found but no index, search again (shouldn't happen, but safety)
            // Recalculate the parent preparable variation total
            // We need to find which preparable variation contains this item
            $currentItemId = $currentItemSnapshot['item_id'];
            foreach ($preparableVariations as $index => $variation) {
                $preparableItems = $variation['preparable_items'] ?? [];
                foreach ($preparableItems as $item) {
                    // Use item_id for exact matching only
                    $matchesItemId = isset($item['item_id']) && $item['item_id'] === $currentItemId;

                    if ($matchesItemId) {
                        // Found the parent variation, recalculate its total
                        $preparableQty = isset($variation['qty']) ? (float) $variation['qty'] : 1;
                        $preparablePrice = isset($variation['price']) ? (float) $variation['price'] : 0;
                        $preparableDisc = isset($variation['disc']) ? (float) $variation['disc'] : 0;

                        $itemsTotal = 0;
                        foreach ($preparableItems as $prepItem) {
                            $itemQty = isset($prepItem['quantity']) ? (float) $prepItem['quantity'] : 1;
                            $itemUnitPrice = isset($prepItem['unit_price']) ? (float) $prepItem['unit_price'] : 0;

                            // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                            $itemDiscountType = isset($prepItem['discount_type']) ? $prepItem['discount_type'] : 'flat';
                            if ($itemDiscountType === 'percentage') {
                                $itemDisc = isset($prepItem['discount_amount']) ? (float) $prepItem['discount_amount'] : 0;
                            } else {
                                $itemDisc = isset($prepItem['disc']) ? (float) $prepItem['disc'] : 0;
                            }

                            $itemsTotal += ($itemUnitPrice * $itemQty) - $itemDisc;
                        }

                        $preparableTotal = round((($preparablePrice + $itemsTotal) * $preparableQty) - $preparableDisc, 2);
                        $preparableVariations[$index]['total'] = $preparableTotal;
                        $set($preparableVariationsPath, array_values($preparableVariations));
                        break 2;
                    }
                }
            }
        }

        // Determine paths for recalcSummary
        $freightFarePath = str_contains($preparableVariationsPath, '../') ? '../../freight_fare' : 'freight_fare';
        $variationsPath = str_replace('preparable_variations', 'variations', $preparableVariationsPath);

        // Recalculate summary
        self::recalcSummary($get, $set, $variationsPath, $preparableVariationsPath, 'preparable_items', $subtotalPath, $totalPath, $totalTaxPath, $discountPath, $freightFarePath);

        // Final step: Always ensure percentage format is preserved after all calculations
        // This must be the last thing we do to prevent any other operations from overwriting it
        if ($discountType === 'percentage') {
            $discountPercentage = (float) ($get('discount_percentage') ?? 0);
            $set('disc', self::formatPercentage($discountPercentage));
        }
    }

    /**
     * Insert a product into the cart or increment an existing one, then update the summary.
     */
    private static function upsertProduct(callable $get, callable $set, Stock $barcode): void
    {
        $variations = $get('variations') ?? [];
        $found = false;

        foreach ($variations as $key => &$item) {
            if (($item['variation_id'] ?? null) == $barcode->variation_id) {
                $item['quantity'] = round($item['quantity'] + 1, 4);
                // Recalculate discount based on unit_discount and new quantity
                // Use quantity (not abs) to preserve sign when quantity is negative
                $unitDiscount = $item['unit_discount'] ?? 0;
                $item['discount'] = self::formatNumberForState($unitDiscount * $item['quantity']);
                $item['total'] = round(($item['unit_price'] * $item['quantity']) - $item['discount'], 2);
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            $variation = $barcode->variation;
            $description = $variation->brand_name
                ? $variation->sku.' - '.$variation->brand_name.' - '.$variation->description
                : $variation->sku.' - '.$variation->description;

            $description = $description;

            // Calculate discount amount: original price - sale price (per unit)
            // Always use variation price/sale_price directly, never from barcode
            $basePrice = $variation->price ?? 0;
            $salePrice = $variation->sale_price ?? $basePrice;
            $discountAmount = round($basePrice - $salePrice, 2);

            $store = Filament::getTenant();
            $effectiveTax = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $barcode, $basePrice);

            $variations[] = [
                'variation_id' => $variation->id,
                'stock_id' => $barcode->id,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => round($basePrice, 2),
                'tax' => round($effectiveTax, 2),
                'unit_discount' => $discountAmount, // Store per-unit discount
                'discount' => self::formatNumberForState($discountAmount),
                'discount_type' => 'flat', // Default to flat discount
                'discount_percentage' => null,
                'supplier_price' => round((float) ($barcode->supplier_price ?? 0), 2),
                'total' => round($salePrice, 2),
            ];
        }

        $set('variations', array_values($variations));
        // From upsertProduct: root level
        self::recalcSummary($get, $set);
    }

    private static function upsertCustomProduct(callable $get, callable $set, string $description): void
    {
        $description = trim($description);
        if ($description === '') {
            return;
        }

        $variations = $get('variations') ?? [];
        $found = false;

        foreach ($variations as $key => &$item) {
            $currentDescription = trim((string) ($item['description'] ?? ''));
            if (($item['variation_id'] ?? null) === null && $currentDescription === $description) {
                $item['quantity'] = round(((float) ($item['quantity'] ?? 0)) + 1, 6);

                if (($item['discount_type'] ?? null) === 'percentage') {
                    $unitPrice = (float) ($item['unit_price'] ?? 0);
                    $percentage = (float) ($item['discount_percentage'] ?? 0);
                    $subtotalBeforeDiscount = $unitPrice * abs($item['quantity']);
                    $discountAmount = ($subtotalBeforeDiscount * abs($percentage)) / 100;
                    if ($percentage < 0 || $item['quantity'] < 0) {
                        $discountAmount = $discountAmount * -1;
                    }
                    $discountAmount = round($discountAmount, 2);

                    $item['discount_amount'] = $discountAmount;
                    $item['unit_discount'] = $item['quantity'] != 0 ? round($discountAmount / $item['quantity'], 2) : 0;
                    $item['discount'] = self::formatPercentage($percentage);
                    $item['total'] = round(($unitPrice * $item['quantity']) - $discountAmount, 2);
                } else {
                    $unitDiscount = (float) ($item['unit_discount'] ?? 0);
                    $discountAmount = round($unitDiscount * $item['quantity'], 2);
                    $item['discount_amount'] = $discountAmount;
                    $item['discount'] = self::formatNumberForState($discountAmount);
                    $item['total'] = round(((float) ($item['unit_price'] ?? 0) * $item['quantity']) - $discountAmount, 2);
                }

                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            $variations[] = [
                'variation_id' => null,
                'stock_id' => null,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => 0,
                'tax' => 0,
                'unit_discount' => 0,
                'discount' => self::formatNumberForState(0),
                'discount_type' => 'flat',
                'discount_percentage' => null,
                'discount_amount' => 0,
                'supplier_price' => 0,
                'total' => 0,
            ];
        }

        $set('variations', array_values($variations));
        self::recalcSummary($get, $set);
    }

    private static function upsertPreparableProduct(callable $get, callable $set, Stock $barcode): void
    {
        $preparableVariations = $get('preparable_variations') ?? [];

        $variation = $barcode->variation;
        $description = $variation->brand_name
            ? $variation->sku.' - '.$variation->brand_name.' - '.$variation->description
            : $variation->sku.' - '.$variation->description;

        // Always add as a new item, even if the same variation exists
        // This is because preparable products may have the same base product
        // but different ingredients (nested items)
        // Always use variation price/sale_price directly, never from barcode
        $basePrice = $variation->price ?? 0;
        $salePrice = $variation->sale_price ?? $basePrice;
        $discountAmount = round($basePrice - $salePrice, 2);

        // Generate a unique instance_id for this preparable variation instance
        // This allows us to identify which specific instance we're working with
        // even when multiple instances have the same variation_id
        $instanceId = 'prep_'.microtime(true).'_'.uniqid();

        $preparableVariations[] = [
            'instance_id' => $instanceId,
            'variation_id' => $variation->id,
            'stock_id' => $barcode->id,
            'description' => $description,
            'qty' => 1,
            'price' => round($basePrice, 2),
            'unit_discount' => round($discountAmount, 2), // Per-unit discount
            'disc' => self::formatNumberForState($discountAmount),
            'total' => round($salePrice, 2),
            'preparable_items' => [],
        ];

        $set('preparable_variations', array_values($preparableVariations));
        // From upsertPreparableProduct: root level
        self::recalcSummary($get, $set);
    }

    /**
     * Expand sale items into FIFO barcode lines for positive quantities.
     */
    private static function expandFifoVariations(array $variations): array
    {
        $expanded = [];

        foreach ($variations as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $variationId = $item['variation_id'] ?? null;

            if ($quantity <= 0 || ! $variationId) {
                // Ensure tax is calculated even for items that aren't expanded
                if (($item['tax'] ?? 0) == 0 && $variationId && $quantity > 0) {
                    $stockId = $item['stock_id'] ?? null;
                    if ($stockId) {
                        $barcode = Stock::find($stockId);
                        if ($barcode) {
                            $store = Filament::getTenant();
                            $unitPrice = (float) ($item['unit_price'] ?? 0);
                            $effectiveTax = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $barcode, $unitPrice);
                            $item['tax'] = round($effectiveTax, 2);
                        }
                    }
                }
                $expanded[] = $item;

                continue;
            }

            $barcodes = Stock::query()
                ->where('variation_id', $variationId)
                ->orderBy('created_at')
                ->get(['id', 'stock', 'tax_amount', 'supplier_price']);

            $remaining = $quantity;
            foreach ($barcodes as $barcode) {
                if ($remaining <= 0) {
                    break;
                }

                $available = (float) $barcode->stock;
                if ($available <= 0) {
                    continue;
                }

                $take = min($remaining, $available);
                $store = Filament::getTenant();
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $effectiveTax = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $barcode, $unitPrice);

                $line = $item;
                $line['stock_id'] = $barcode->id;
                $line['quantity'] = $take;
                $line['tax'] = round($effectiveTax, 2);
                $line['supplier_price'] = round((float) ($barcode->supplier_price ?? 0), 2);

                $expanded[] = self::recalcLineItem($line);
                $remaining -= $take;
            }

            if ($remaining > 0) {
                $fallbackBarcode = $barcodes->last();
                if ($fallbackBarcode) {
                    $store = Filament::getTenant();
                    $unitPrice = (float) ($item['unit_price'] ?? 0);
                    $effectiveTax = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $fallbackBarcode, $unitPrice);

                    $line = $item;
                    $line['stock_id'] = $fallbackBarcode->id;
                    $line['quantity'] = $remaining;
                    $line['tax'] = round($effectiveTax, 2);
                    $line['supplier_price'] = round((float) ($fallbackBarcode->supplier_price ?? 0), 2);

                    $expanded[] = self::recalcLineItem($line);
                }
            }
        }

        return $expanded;
    }

    /**
     * Recalculate line totals for a split FIFO item.
     */
    private static function recalcLineItem(array $item): array
    {
        $quantity = (float) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $discountType = $item['discount_type'] ?? 'flat';

        if ($discountType === 'percentage') {
            $percentage = (float) ($item['discount_percentage'] ?? 0);
            $discountAmount = ($unitPrice * abs($quantity)) * ($percentage / 100);
            if ($quantity < 0) {
                $discountAmount *= -1;
            }
            $discountAmount = round($discountAmount, 2);
            $item['discount'] = $discountAmount;
            $item['discount_amount'] = $discountAmount;
            $item['unit_discount'] = $quantity != 0 ? round($discountAmount / $quantity, 2) : 0;
        } else {
            $unitDiscount = (float) ($item['unit_discount'] ?? 0);
            $discountAmount = round($unitDiscount * $quantity, 2);
            $item['discount'] = $discountAmount;
            $item['discount_amount'] = $discountAmount;
        }

        $item['total'] = round(($unitPrice * $quantity) - $item['discount'], 2);

        return $item;
    }

    /**
     * Recalculate subtotal, tax, discount, and total from the sale's products in the database.
     */
    private static function recalcSaleFromDb(Sale $sale): array
    {
        $sale->loadMissing(['variations', 'preparableItems', 'store.currency']);
        $store = $sale->store;
        $isTaxEnabled = $store?->tax_enabled ?? false;
        $products = $sale->variations()->withPivot(['quantity', 'unit_price', 'tax', 'discount', 'is_preparable', 'total'])->get();
        $subtotal = 0;
        $totalTax = 0;
        foreach ($products as $product) {
            $isPreparable = $product->pivot->is_preparable ?? false;
            $quantity = (float) $product->pivot->quantity;
            $unitPrice = (float) $product->pivot->unit_price;
            $discountAmount = (float) ($product->pivot->discount ?? 0);
            $tax = $isTaxEnabled ? ((float) ($product->pivot->tax ?? 0)) : 0;

            if ($isPreparable) {
                // For preparable variations, the total already includes nested items and discounts
                // So we use the total from pivot directly
                $preparableTotal = (float) ($product->pivot->total ?? 0);
                $subtotal += $preparableTotal;
                $totalTax += $tax * $quantity;
            } else {
                // Regular variations
                $subtotal += ($unitPrice * $quantity) - $discountAmount;
                $totalTax += $tax * $quantity;
            }
        }

        $multiplier = $sale->currencyMultiplier();

        $customRows = DB::table('sale_variation')
            ->where('sale_id', $sale->id)
            ->whereNull('variation_id')
            ->where('is_preparable', false)
            ->get(['quantity', 'unit_price', 'tax', 'discount']);

        foreach ($customRows as $row) {
            $quantity = (float) ($row->quantity ?? 0);
            $unitPrice = (float) ($row->unit_price ?? 0) / $multiplier;
            $discountAmount = (float) ($row->discount ?? 0) / $multiplier;
            $tax = $isTaxEnabled ? ((float) ($row->tax ?? 0) / $multiplier) : 0;

            $subtotal += ($unitPrice * $quantity) - $discountAmount;
            $totalTax += $tax * $quantity;
        }

        $preparableItemsTax = $sale->preparableItems->sum(function ($item) use ($isTaxEnabled) {
            return $isTaxEnabled ? ((float) ($item->tax ?? 0) * (float) ($item->quantity ?? 0)) : 0;
        });
        $totalTax += $preparableItemsTax;
        // Use sale-level discount amount if present, otherwise 0
        $saleDiscountAmount = (float) ($sale->discount ?? 0);
        // Freight fare (added after discount)
        $freightFare = (float) ($sale->freight_fare ?? 0);
        $total = $subtotal - $saleDiscountAmount + $freightFare;

        return [
            'subtotal' => round($subtotal, 2),
            'tax' => round($totalTax, 2),
            'discount' => round($saleDiscountAmount, 2),
            'freight_fare' => round($freightFare, 2),
            'total' => round($total, 2),
        ];
    }

    /**
     * Handle the checkout logic, including sale creation and update.
     */
    public static function handleCheckout($state, $status)
    {
        $variations = $state['variations'] ?? [];
        $preparableVariations = $state['preparable_variations'] ?? [];
        $subtotal = $state['subtotal'] ?? null;
        $total = $state['total'] ?? null;

        // Validation: Check if there are any products (regular or preparable)
        if (empty($variations) && empty($preparableVariations)) {
            Notification::make()
                ->title('Please add products to the cart.')
                ->danger()
                ->send();

            return;
        }
        if (! $subtotal || ! $total) {
            Notification::make()
                ->title('Missing required sale information.')
                ->danger()
                ->send();

            return;
        }

        // Validate regular variations
        foreach ($variations as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $variationId = $item['variation_id'] ?? null;
            if (! $variationId) {
                continue;
            }

            $hasStockRows = Stock::query()
                ->where('variation_id', $variationId)
                ->exists();

            if (! $hasStockRows) {
                $label = $item['description'] ?? 'Item';
                Notification::make()
                    ->title('No stock record for '.$label)
                    ->body('Please add a stock entry before selling this product.')
                    ->danger()
                    ->send();

                return;
            }
        }

        // Validate preparable variations
        foreach ($preparableVariations as $preparableVariation) {
            $quantity = (float) ($preparableVariation['qty'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $variationId = $preparableVariation['variation_id'] ?? null;
            if (! $variationId) {
                continue;
            }

            $hasStockRows = Stock::query()
                ->where('variation_id', $variationId)
                ->exists();

            if (! $hasStockRows) {
                $label = $preparableVariation['description'] ?? 'Item';
                Notification::make()
                    ->title('No stock record for '.$label)
                    ->body('Please add a stock entry before selling this product.')
                    ->danger()
                    ->send();

                return;
            }

            // Validate nested items in preparable variations
            $preparableItems = $preparableVariation['preparable_items'] ?? [];
            foreach ($preparableItems as $item) {
                $itemVariationId = $item['variation_id'] ?? null;
                if (! $itemVariationId) {
                    continue;
                }

                $hasStockRows = Stock::query()
                    ->where('variation_id', $itemVariationId)
                    ->exists();

                if (! $hasStockRows) {
                    $itemLabel = $item['description'] ?? 'Item';
                    Notification::make()
                        ->title('No stock record for nested item: '.$itemLabel)
                        ->body('Please add a stock entry before selling this product.')
                        ->danger()
                        ->send();

                    return;
                }
            }
        }

        $customVariations = array_values(array_filter($variations, function ($item) {
            return empty($item['variation_id']);
        }));
        $regularVariations = array_values(array_filter($variations, function ($item) {
            return ! empty($item['variation_id']);
        }));

        $regularVariations = self::expandFifoVariations($regularVariations);

        try {
            $sale = DB::transaction(function () use ($state, $regularVariations, $customVariations, $status) {
                if (! empty($state['sale_id'])) {
                    // Update existing sale
                    $sale = Sale::findOrFail($state['sale_id']);
                    $sale->loadMissing('variations');

                    // Capture old state for transaction reversal
                    $oldStatus = $sale->status;
                    $oldPaymentStatus = $sale->payment_status;
                    $oldTotal = $sale->total;
                    $oldCustomerId = $sale->customer_id; // Capture old customer ID before update
                    $oldVariations = [];
                    foreach ($sale->variations as $variation) {
                        $oldVariations[] = [
                            'variation_id' => $variation->id,
                            'stock_id' => $variation->pivot->stock_id ?? null,
                            'quantity' => $variation->pivot->quantity,
                            'is_preparable' => (bool) ($variation->pivot->is_preparable ?? false),
                        ];
                    }

                    // Ensure payment_status is an enum instance
                    $paymentStatusValue = $state['payment_status'] ?? SalePaymentStatus::default();
                    $paymentStatus = $paymentStatusValue instanceof SalePaymentStatus
                        ? $paymentStatusValue
                        : SalePaymentStatus::from($paymentStatusValue);
                    $paymentMethod = null;
                    if ($paymentStatus === SalePaymentStatus::Paid) {
                        $paymentMethodValue = $state['payment_method'] ?? SalePaymentMethod::default();
                        $paymentMethod = $paymentMethodValue instanceof SalePaymentMethod
                            ? $paymentMethodValue
                            : SalePaymentMethod::from($paymentMethodValue);
                    }

                    // Get discount amount - use sale_discount_amount if available (calculated amount), otherwise parse discount field
                    $discountAmount = isset($state['sale_discount_amount'])
                        ? round((float) $state['sale_discount_amount'], 2)
                        : (is_string($state['discount'] ?? null) && str_contains($state['discount'], '%')
                            ? 0 // If it's a percentage string, we need to calculate it
                            : round((float) ($state['discount'] ?? 0), 2));

                    // If discount is a percentage string, calculate the amount
                    if (is_string($state['discount'] ?? null) && str_contains($state['discount'], '%')) {
                        $saleDiscountType = $state['sale_discount_type'] ?? 'flat';
                        $saleDiscountPercentage = (float) ($state['sale_discount_percentage'] ?? 0);
                        if ($saleDiscountType === 'percentage' && $saleDiscountPercentage != 0) {
                            // Recalculate from subtotal
                            $subtotal = (float) ($state['subtotal'] ?? 0);
                            $discountAmount = round(($subtotal * abs($saleDiscountPercentage)) / 100, 2);
                            if ($saleDiscountPercentage < 0) {
                                $discountAmount = $discountAmount * -1;
                            }
                        }
                    }

                    $updateData = [
                        'customer_id' => $state['customer_id'] ?? null,
                        'payment_status' => $paymentStatus,
                        'payment_method' => $paymentMethod,
                        'use_fbr' => $state['use_fbr'] ?? false,
                        'status' => $status,
                        'discount' => $discountAmount,
                        'discount_type' => $state['sale_discount_type'] ?? 'flat',
                        'discount_percentage' => isset($state['sale_discount_type']) && $state['sale_discount_type'] === 'percentage'
                            ? round((float) ($state['sale_discount_percentage'] ?? 0), 6)
                            : null,
                        'freight_fare' => round((float) ($state['freight_fare'] ?? 0), 2),
                        'note' => $state['note'] ?? null,
                    ];

                    // Set paid_at if payment status is Paid and not already set
                    if ($paymentStatus === SalePaymentStatus::Paid && ! $sale->paid_at) {
                        $updateData['paid_at'] = now();
                    }

                    // Sync products first (regular variations only)
                    // Note: PriceCast will handle multiplication, but needs sale_id to resolve store
                    // We'll use DB::table() to insert directly with multiplied values to bypass cast issues
                    $multiplier = $sale->currencyMultiplier();
                    $syncData = [];
                    foreach ($regularVariations as $variation) {
                        // Use discount_amount if it's a percentage discount, otherwise use discount
                        $discountValue = isset($variation['discount_type']) && $variation['discount_type'] === 'percentage'
                            ? (float) ($variation['discount_amount'] ?? 0)
                            : (float) ($variation['discount'] ?? 0);

                        // Ensure tax is calculated if missing or 0
                        $taxValue = (float) ($variation['tax'] ?? 0);
                        if ($taxValue == 0 && $sale->store?->tax_enabled && isset($variation['stock_id']) && $variation['stock_id']) {
                            $stock = Stock::find($variation['stock_id']);
                            if ($stock) {
                                $unitPrice = (float) ($variation['unit_price'] ?? 0);
                                $taxValue = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($sale->store, $stock, $unitPrice);
                            }
                        }

                        $syncData[$variation['variation_id']] = [
                            'stock_id' => $variation['stock_id'] ?? null,
                            'description' => $variation['description'],
                            'quantity' => $variation['quantity'],
                            'unit_price' => round((float) $variation['unit_price'] * $multiplier),
                            'tax' => round($taxValue * $multiplier),
                            'discount' => round($discountValue * $multiplier),
                            'discount_type' => $variation['discount_type'] ?? 'flat',
                            'discount_percentage' => isset($variation['discount_type']) && $variation['discount_type'] === 'percentage'
                                ? round((float) ($variation['discount_percentage'] ?? 0), 6)
                                : null,
                            'total' => round((float) $variation['total'] * $multiplier),
                            'supplier_price' => round((float) $variation['supplier_price'] * $multiplier),
                            'supplier_total' => round((float) $variation['supplier_price'] * $variation['quantity'] * $multiplier),
                            'is_preparable' => false,
                        ];
                    }

                    // CRITICAL: Delete existing preparable variations FIRST before syncing
                    // This is necessary because sync() can't handle multiple instances of the same variation_id
                    // and will remove preparable variations if we attach them before syncing
                    $sale->variations()->wherePivot('is_preparable', true)->detach();

                    // Delete existing regular variations first
                    $sale->variations()->wherePivot('is_preparable', false)->detach();

                    // Insert regular variations directly using DB to bypass PriceCast issues with pivot models
                    // PriceCast can't resolve store during sync/attach, so we multiply manually
                    foreach ($syncData as $variationId => $data) {
                        DB::table('sale_variation')->insert([
                            'sale_id' => $sale->id,
                            'variation_id' => $variationId,
                            'stock_id' => $data['stock_id'],
                            'description' => $data['description'],
                            'quantity' => $data['quantity'],
                            'unit_price' => $data['unit_price'],
                            'tax' => $data['tax'],
                            'discount' => $data['discount'],
                            'discount_type' => $data['discount_type'],
                            'discount_percentage' => $data['discount_percentage'],
                            'total' => $data['total'],
                            'supplier_price' => $data['supplier_price'],
                            'supplier_total' => $data['supplier_total'],
                            'is_preparable' => false,
                        ]);
                    }

                    DB::table('sale_variation')
                        ->where('sale_id', $sale->id)
                        ->whereNull('variation_id')
                        ->where('is_preparable', false)
                        ->delete();

                    // Now add preparable variations using attach() instead of sync()
                    // This allows multiple instances of the same variation_id
                    $preparableVariations = $state['preparable_variations'] ?? [];
                    foreach ($preparableVariations as $preparableVariation) {
                        $preparableVariationId = $preparableVariation['variation_id'] ?? null;
                        if (! $preparableVariationId) {
                            continue;
                        }

                        // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                        $preparableDiscountType = isset($preparableVariation['discount_type']) ? $preparableVariation['discount_type'] : 'flat';
                        if ($preparableDiscountType === 'percentage') {
                            $preparableDisc = isset($preparableVariation['discount_amount']) ? (float) ($preparableVariation['discount_amount'] ?? 0) : 0;
                        } else {
                            $discValue = $preparableVariation['disc'] ?? 0;
                            if (is_string($discValue) && str_contains($discValue, '%')) {
                                $preparableDisc = 0;
                            } else {
                                $preparableDisc = (float) $discValue;
                            }
                        }

                        $preparableQty = (float) ($preparableVariation['qty'] ?? 1);
                        $preparablePrice = round((float) ($preparableVariation['price'] ?? 0), 2);
                        $preparableTax = round((float) ($preparableVariation['tax'] ?? 0), 2);
                        $preparableTotal = round((float) ($preparableVariation['total'] ?? 0), 2);

                        // Get stock_id and supplier price
                        $stockId = $preparableVariation['stock_id'] ?? null;
                        $stock = $stockId ? Stock::find($stockId) : null;
                        $supplierPrice = $stock ? (float) ($stock->supplier_price ?? 0) : 0;

                        // Insert preparable variations directly using DB to bypass PriceCast issues
                        // This allows multiple instances of the same variation_id
                        $multiplier = $sale->currencyMultiplier();
                        DB::table('sale_variation')->insert([
                            'sale_id' => $sale->id,
                            'variation_id' => $preparableVariationId,
                            'stock_id' => $stockId,
                            'description' => $preparableVariation['description'] ?? '',
                            'quantity' => $preparableQty,
                            'unit_price' => round($preparablePrice * $multiplier),
                            'tax' => round($preparableTax * $multiplier),
                            'discount' => round($preparableDisc * $multiplier),
                            'discount_type' => $preparableDiscountType,
                            'discount_percentage' => isset($preparableVariation['discount_percentage']) ? round((float) $preparableVariation['discount_percentage'], 6) : null,
                            'total' => round($preparableTotal * $multiplier),
                            'supplier_price' => round($supplierPrice * $multiplier),
                            'supplier_total' => round($supplierPrice * $preparableQty * $multiplier),
                            'is_preparable' => true,
                        ]);
                    }

                    if (! empty($customVariations)) {
                        $sale->loadMissing('store.currency');
                        $multiplier = $sale->currencyMultiplier();

                        $customRows = array_map(function ($item) use ($sale, $multiplier) {
                            $discountValue = isset($item['discount_type']) && $item['discount_type'] === 'percentage'
                                ? (float) ($item['discount_amount'] ?? 0)
                                : (float) ($item['discount'] ?? 0);

                            $quantity = (float) ($item['quantity'] ?? 0);
                            $lineTotal = (float) ($item['total'] ?? 0);
                            $supplierPrice = $quantity != 0 ? $lineTotal / $quantity : 0.0;

                            return [
                                'sale_id' => $sale->id,
                                'variation_id' => null,
                                'stock_id' => null,
                                'description' => $item['description'] ?? 'Custom item',
                                'quantity' => $quantity,
                                'unit_price' => round((float) ($item['unit_price'] ?? 0) * $multiplier),
                                'tax' => round((float) ($item['tax'] ?? 0) * $multiplier),
                                'discount' => round($discountValue * $multiplier),
                                'discount_type' => $item['discount_type'] ?? 'flat',
                                'discount_percentage' => isset($item['discount_type']) && $item['discount_type'] === 'percentage'
                                    ? round((float) ($item['discount_percentage'] ?? 0), 6)
                                    : null,
                                'total' => round($lineTotal * $multiplier),
                                'supplier_price' => round($supplierPrice * $multiplier),
                                'supplier_total' => round($lineTotal * $multiplier),
                                'is_preparable' => false,
                            ];
                        }, $customVariations);

                        DB::table('sale_variation')->insert($customRows);
                    }

                    // Handle preparable items for update
                    $preparableVariations = $state['preparable_variations'] ?? [];

                    // Delete existing preparable items for this sale
                    SalePreparableItem::where('sale_id', $sale->id)->delete();

                    // Save new preparable items
                    if (! empty($preparableVariations) && is_array($preparableVariations)) {
                        foreach ($preparableVariations as $sequence => $preparableVariation) {
                            $preparableVariationId = $preparableVariation['variation_id'] ?? null;
                            if (! $preparableVariationId) {
                                continue;
                            }

                            $preparableItems = $preparableVariation['preparable_items'] ?? [];
                            if (! is_array($preparableItems) || empty($preparableItems)) {
                                continue;
                            }

                            foreach ($preparableItems as $item) {
                                $variationId = $item['variation_id'] ?? null;
                                if (! $variationId) {
                                    continue;
                                }

                                // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                                $itemDiscountType = isset($item['discount_type']) ? $item['discount_type'] : 'flat';
                                $itemDiscountPercentage = $itemDiscountType === 'percentage'
                                    ? (float) ($item['discount_percentage'] ?? 0)
                                    : null;
                                if ($itemDiscountType === 'percentage') {
                                    $itemDisc = round(($unitPrice * abs($quantity)) * (abs($itemDiscountPercentage) / 100), 2);
                                    if ($itemDiscountPercentage < 0 || $quantity < 0) {
                                        $itemDisc = $itemDisc * -1;
                                    }
                                } else {
                                    $discValue = $item['disc'] ?? ($item['discount_amount'] ?? 0);
                                    if (is_string($discValue) && str_contains($discValue, '%')) {
                                        $itemDisc = 0;
                                    } else {
                                        $itemDisc = (float) $discValue;
                                    }
                                }

                                // Get stock_id and calculate supplier price and tax
                                $stockId = $item['stock_id'] ?? null;
                                $stock = $stockId ? Stock::find($stockId) : null;
                                $supplierPrice = $stock ? (float) ($stock->supplier_price ?? 0) : 0;
                                $quantity = (float) ($item['quantity'] ?? 1);
                                $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

                                // Calculate tax similar to regular variations - use effective tax amount (0 if taxes disabled)
                                $store = Filament::getTenant();
                                $lineTax = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $stock, $unitPrice);

                                SalePreparableItem::create([
                                    'sale_id' => $sale->id,
                                    'sequence' => $sequence, // Store sequence to uniquely identify this preparable variation instance
                                    'preparable_variation_id' => $preparableVariationId,
                                    'variation_id' => $variationId,
                                    'stock_id' => $stockId,
                                    'quantity' => $quantity,
                                    'unit_price' => $unitPrice,
                                    'tax' => $lineTax,
                                    'discount' => round($itemDisc, 2),
                                    'discount_type' => $itemDiscountType,
                                    'discount_percentage' => $itemDiscountPercentage,
                                    'total' => round((float) ($item['total'] ?? 0), 2),
                                    'supplier_price' => round($supplierPrice, 2),
                                    'supplier_total' => round($supplierPrice * $quantity, 2),
                                ]);
                            }
                        }
                    }

                    // Handle transaction reversals BEFORE updating sale to prevent observer conflicts
                    // Use withoutEvents to prevent SaleObserver from interfering with our manual transaction handling
                    $newTotal = $oldTotal; // Initialize
                    if ($oldStatus !== SaleStatus::Pending || $status === SaleStatus::Completed) {
                        // Update sale without triggering observer to prevent duplicate transaction handling
                        Sale::withoutEvents(function () use ($sale, $updateData, &$newTotal) {
                            // Update sale data without triggering observer
                            $sale->update($updateData);

                            // Refresh sale to get latest variations and data
                            $sale->refresh();

                            // Recalculate and update sale summary fields from DB
                            $recalculated = self::recalcSaleFromDb($sale);
                            $sale->update($recalculated);

                            // Refresh to get the new total
                            $sale->refresh();
                            $newTotal = $sale->total;
                        });

                        // Now handle all transactions manually (prevents observer conflicts)
                        $saleTransactionService = app(\SmartTill\Core\Services\SaleTransactionService::class);
                        $saleTransactionService->handleSaleEdit(
                            $sale,
                            $oldVariations,
                            $oldTotal,
                            $oldPaymentStatus,
                            $paymentStatus,
                            $newTotal,
                            $oldStatus,
                            $status,
                            $oldCustomerId
                        );
                    } else {
                        // For pending sales, update normally (observer can handle it)
                        $sale->update($updateData);
                        $sale->refresh();
                        $recalculated = self::recalcSaleFromDb($sale);
                        $sale->update($recalculated);
                        $sale->refresh();
                        $newTotal = $sale->total;
                    }
                } else {
                    // Create new sale
                    // Ensure payment_status is an enum instance
                    $paymentStatusValue = $state['payment_status'] ?? SalePaymentStatus::default();
                    $paymentStatus = $paymentStatusValue instanceof SalePaymentStatus
                        ? $paymentStatusValue
                        : SalePaymentStatus::from($paymentStatusValue);
                    $paymentMethod = null;
                    if ($paymentStatus === SalePaymentStatus::Paid) {
                        $paymentMethodValue = $state['payment_method'] ?? SalePaymentMethod::default();
                        $paymentMethod = $paymentMethodValue instanceof SalePaymentMethod
                            ? $paymentMethodValue
                            : SalePaymentMethod::from($paymentMethodValue);
                    }

                    // Get discount amount - use sale_discount_amount if available (calculated amount), otherwise parse discount field
                    $discountAmount = isset($state['sale_discount_amount'])
                        ? round((float) $state['sale_discount_amount'], 2)
                        : (is_string($state['discount'] ?? null) && str_contains($state['discount'], '%')
                            ? 0 // If it's a percentage string, we need to calculate it
                            : round((float) ($state['discount'] ?? 0), 2));

                    // If discount is a percentage string, calculate the amount
                    if (is_string($state['discount'] ?? null) && str_contains($state['discount'], '%')) {
                        $saleDiscountType = $state['sale_discount_type'] ?? 'flat';
                        $saleDiscountPercentage = (float) ($state['sale_discount_percentage'] ?? 0);
                        if ($saleDiscountType === 'percentage' && $saleDiscountPercentage != 0) {
                            // Recalculate from subtotal
                            $subtotal = (float) ($state['subtotal'] ?? 0);
                            $discountAmount = round(($subtotal * abs($saleDiscountPercentage)) / 100, 2);
                            if ($saleDiscountPercentage < 0) {
                                $discountAmount = $discountAmount * -1;
                            }
                        }
                    }

                    $saleData = [
                        'customer_id' => $state['customer_id'] ?? null,
                        'payment_status' => $paymentStatus,
                        'payment_method' => $paymentMethod,
                        'use_fbr' => $state['use_fbr'] ?? false,
                        'discount' => $discountAmount,
                        'discount_type' => $state['sale_discount_type'] ?? 'flat',
                        'discount_percentage' => isset($state['sale_discount_type']) && $state['sale_discount_type'] === 'percentage'
                            ? round((float) ($state['sale_discount_percentage'] ?? 0), 6)
                            : null,
                        'freight_fare' => round((float) ($state['freight_fare'] ?? 0), 2),
                        'status' => $status,
                        'note' => $state['note'] ?? null,
                    ];

                    // Set paid_at if payment status is Paid
                    if ($paymentStatus === SalePaymentStatus::Paid) {
                        $saleData['paid_at'] = now();
                    }

                    $sale = Sale::create($saleData);

                    // Insert regular variations directly using DB to bypass PriceCast issues with pivot models
                    // PriceCast can't resolve store during attach, so we multiply manually
                    $multiplier = $sale->currencyMultiplier();
                    foreach ($regularVariations as $variation) {
                        // Use discount_amount if it's a percentage discount, otherwise use discount
                        $discountValue = isset($variation['discount_type']) && $variation['discount_type'] === 'percentage'
                            ? (float) ($variation['discount_amount'] ?? 0)
                            : (float) ($variation['discount'] ?? 0);

                        // Ensure tax is calculated if missing or 0
                        $taxValue = (float) ($variation['tax'] ?? 0);
                        if ($taxValue == 0 && $sale->store?->tax_enabled && isset($variation['stock_id']) && $variation['stock_id']) {
                            $stock = Stock::find($variation['stock_id']);
                            if ($stock) {
                                $unitPrice = (float) ($variation['unit_price'] ?? 0);
                                $taxValue = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($sale->store, $stock, $unitPrice);
                            }
                        }

                        DB::table('sale_variation')->insert([
                            'sale_id' => $sale->id,
                            'variation_id' => $variation['variation_id'],
                            'stock_id' => $variation['stock_id'] ?? null,
                            'description' => $variation['description'],
                            'quantity' => $variation['quantity'],
                            'unit_price' => round((float) $variation['unit_price'] * $multiplier),
                            'tax' => round($taxValue * $multiplier),
                            'discount' => round($discountValue * $multiplier),
                            'discount_type' => $variation['discount_type'] ?? 'flat',
                            'discount_percentage' => isset($variation['discount_type']) && $variation['discount_type'] === 'percentage'
                                ? round((float) ($variation['discount_percentage'] ?? 0), 6)
                                : null,
                            'total' => round((float) $variation['total'] * $multiplier),
                            'supplier_price' => round((float) $variation['supplier_price'] * $multiplier),
                            'supplier_total' => round((float) $variation['supplier_price'] * $variation['quantity'] * $multiplier),
                            'is_preparable' => false,
                        ]);
                    }

                    // Attach preparable variations
                    $preparableVariations = $state['preparable_variations'] ?? [];
                    foreach ($preparableVariations as $sequence => $preparableVariation) {
                        $preparableVariationId = $preparableVariation['variation_id'] ?? null;
                        if (! $preparableVariationId) {
                            continue;
                        }

                        // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                        $preparableDiscountType = isset($preparableVariation['discount_type']) ? $preparableVariation['discount_type'] : 'flat';
                        if ($preparableDiscountType === 'percentage') {
                            $preparableDisc = isset($preparableVariation['discount_amount']) ? (float) ($preparableVariation['discount_amount'] ?? 0) : 0;
                        } else {
                            $discValue = $preparableVariation['disc'] ?? 0;
                            if (is_string($discValue) && str_contains($discValue, '%')) {
                                $preparableDisc = 0;
                            } else {
                                $preparableDisc = (float) $discValue;
                            }
                        }

                        $preparableQty = (float) ($preparableVariation['qty'] ?? 1);
                        $preparablePrice = round((float) ($preparableVariation['price'] ?? 0), 2);
                        $preparableTax = round((float) ($preparableVariation['tax'] ?? 0), 2);
                        $preparableTotal = round((float) ($preparableVariation['total'] ?? 0), 2);

                        // Get stock_id and supplier price
                        $stockId = $preparableVariation['stock_id'] ?? null;
                        $stock = $stockId ? Stock::find($stockId) : null;
                        $supplierPrice = $stock ? (float) ($stock->supplier_price ?? 0) : 0;

                        $sale->variations()->attach($preparableVariationId, [
                            'stock_id' => $stockId,
                            'description' => $preparableVariation['description'] ?? '',
                            'quantity' => $preparableQty,
                            'unit_price' => $preparablePrice,
                            'tax' => $preparableTax,
                            'discount' => round($preparableDisc, 2),
                            'discount_type' => $preparableDiscountType,
                            'discount_percentage' => isset($preparableVariation['discount_percentage']) ? round((float) $preparableVariation['discount_percentage'], 6) : null,
                            'total' => $preparableTotal,
                            'supplier_price' => round($supplierPrice, 2),
                            'supplier_total' => round($supplierPrice * $preparableQty, 2),
                            'is_preparable' => true,
                        ]);
                    }

                    if (! empty($customVariations)) {
                        $sale->loadMissing('store.currency');
                        $multiplier = $sale->currencyMultiplier();

                        $customRows = array_map(function ($item) use ($sale, $multiplier) {
                            $discountValue = isset($item['discount_type']) && $item['discount_type'] === 'percentage'
                                ? (float) ($item['discount_amount'] ?? 0)
                                : (float) ($item['discount'] ?? 0);

                            $quantity = (float) ($item['quantity'] ?? 0);
                            $lineTotal = (float) ($item['total'] ?? 0);
                            $supplierPrice = $quantity != 0 ? $lineTotal / $quantity : 0.0;

                            return [
                                'sale_id' => $sale->id,
                                'variation_id' => null,
                                'stock_id' => null,
                                'description' => $item['description'] ?? 'Custom item',
                                'quantity' => $quantity,
                                'unit_price' => round((float) ($item['unit_price'] ?? 0) * $multiplier),
                                'tax' => round((float) ($item['tax'] ?? 0) * $multiplier),
                                'discount' => round($discountValue * $multiplier),
                                'discount_type' => $item['discount_type'] ?? 'flat',
                                'discount_percentage' => isset($item['discount_type']) && $item['discount_type'] === 'percentage'
                                    ? round((float) ($item['discount_percentage'] ?? 0), 6)
                                    : null,
                                'total' => round($lineTotal * $multiplier),
                                'supplier_price' => round($supplierPrice * $multiplier),
                                'supplier_total' => round($lineTotal * $multiplier),
                                'is_preparable' => false,
                            ];
                        }, $customVariations);

                        DB::table('sale_variation')->insert($customRows);
                    }

                    // Save preparable items
                    $preparableVariations = $state['preparable_variations'] ?? [];
                    if (! empty($preparableVariations) && is_array($preparableVariations)) {
                        foreach ($preparableVariations as $sequence => $preparableVariation) {
                            $preparableVariationId = $preparableVariation['variation_id'] ?? null;
                            if (! $preparableVariationId) {
                                continue;
                            }

                            $preparableItems = $preparableVariation['preparable_items'] ?? [];
                            if (! is_array($preparableItems) || empty($preparableItems)) {
                                continue;
                            }

                            foreach ($preparableItems as $item) {
                                $variationId = $item['variation_id'] ?? null;
                                if (! $variationId) {
                                    continue;
                                }

                                // Get discount amount - use discount_amount for percentage discounts, otherwise use disc field
                                $itemDiscountType = isset($item['discount_type']) ? $item['discount_type'] : 'flat';
                                $itemDiscountPercentage = $itemDiscountType === 'percentage'
                                    ? (float) ($item['discount_percentage'] ?? 0)
                                    : null;
                                if ($itemDiscountType === 'percentage') {
                                    $itemDisc = round(($unitPrice * abs($quantity)) * (abs($itemDiscountPercentage) / 100), 2);
                                    if ($itemDiscountPercentage < 0 || $quantity < 0) {
                                        $itemDisc = $itemDisc * -1;
                                    }
                                } else {
                                    $discValue = $item['disc'] ?? ($item['discount_amount'] ?? 0);
                                    if (is_string($discValue) && str_contains($discValue, '%')) {
                                        $itemDisc = 0;
                                    } else {
                                        $itemDisc = (float) $discValue;
                                    }
                                }

                                // Get stock_id and calculate supplier price and tax
                                $stockId = $item['stock_id'] ?? null;
                                $stock = $stockId ? Stock::find($stockId) : null;
                                $supplierPrice = $stock ? (float) ($stock->supplier_price ?? 0) : 0;
                                $quantity = (float) ($item['quantity'] ?? 1);
                                $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

                                // Calculate tax similar to regular variations - use effective tax amount (0 if taxes disabled)
                                $store = Filament::getTenant();
                                $lineTax = app(CoreStoreSettingsService::class)->getEffectiveTaxAmount($store, $stock, $unitPrice);

                                SalePreparableItem::create([
                                    'sale_id' => $sale->id,
                                    'sequence' => $sequence, // Store sequence to uniquely identify this preparable variation instance
                                    'preparable_variation_id' => $preparableVariationId,
                                    'variation_id' => $variationId,
                                    'stock_id' => $stockId,
                                    'quantity' => $quantity,
                                    'unit_price' => $unitPrice,
                                    'tax' => $lineTax,
                                    'discount' => round($itemDisc, 2),
                                    'discount_type' => $itemDiscountType,
                                    'discount_percentage' => $itemDiscountPercentage,
                                    'total' => round((float) ($item['total'] ?? 0), 2),
                                    'supplier_price' => round($supplierPrice, 2),
                                    'supplier_total' => round($supplierPrice * $quantity, 2),
                                ]);
                            }
                        }
                    }

                    // Refresh sale to ensure variations are loaded before recalculation
                    $sale->refresh();
                    // Recalculate and update sale summary fields from DB
                    $recalculated = self::recalcSaleFromDb($sale);
                    $sale->update($recalculated);
                }

                return $sale;
            });
        } catch (Throwable $e) {
            Notification::make()
                ->title(($state['sale_id'] ? 'Failed to update sale: ' : 'Failed to create sale: ').$e->getMessage())
                ->danger()
                ->send();

            return redirect()->to($state['sale_id'] ? SaleResource::getUrl('edit', ['record' => $state['sale_id']]) : SaleResource::getUrl('create'));
        }
        Notification::make()
            ->title($state['sale_id'] ? 'Sale updated successfully' : 'Sale created successfully')
            ->success()
            ->send();

        // Only redirect to receipt if status is Completed
        // Note: handleSaleOnCompleted is NOT called here for edits because handleSaleEdit already handles transactions
        // It's only needed for new sales that weren't handled by handleSaleEdit
        if ($status === SaleStatus::Completed && empty($state['sale_id'])) {
            // Refresh sale to ensure all relationships (variations, preparableItems) are loaded
            $sale->refresh();
            app(SaleTransactionService::class)->handleSaleOnCompleted($sale);

            $sale->loadMissing('store');

            return redirect()->route('public.receipt', [
                'store' => $sale->store->slug,
                'reference' => $sale->reference,
                'print' => 1,
                'next' => SaleResource::getUrl('create'),
            ])->with([
                'print.next' => SaleResource::getUrl('create'),
                'print.mode' => true,
            ]);
        }

        if ($status === SaleStatus::Completed && ! empty($state['sale_id'])) {
            $sale->loadMissing('store');

            return redirect()->route('public.receipt', [
                'store' => $sale->store->slug,
                'reference' => $sale->reference,
                'print' => 1,
                'next' => SaleResource::getUrl('edit', ['record' => $sale->id]),
            ])->with([
                'print.next' => SaleResource::getUrl('edit', ['record' => $sale->id]),
                'print.mode' => true,
            ]);
        }

        // Otherwise, just go to create page
        return redirect()->to(SaleResource::getUrl('create'));
    }
}
