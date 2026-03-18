<?php

namespace SmartTill\Core\Filament\Resources\Sales\Pages;

use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Enums\SaleStatus;
use SmartTill\Core\Filament\Resources\Sales\SaleResource;
use SmartTill\Core\Filament\Resources\Sales\Schemas\SaleForm;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function beforeFill(): void
    {
        $sale = $this->getRecord();

        // Show warning based on sale status
        if ($sale->status === SaleStatus::Completed) {
            if ($sale->fbr_invoice_number) {
                Notification::make()
                    ->title('Warning: Editing Sale with FBR Invoice')
                    ->body('This sale has an FBR invoice. Editing may affect tax compliance. Stock, cash, and customer transactions will be automatically adjusted.')
                    ->warning()
                    ->persistent()
                    ->send();
            } else {
                Notification::make()
                    ->title('Warning: Editing Completed Sale')
                    ->body('You are editing a completed sale. Stock, cash, and customer transactions will be automatically adjusted.')
                    ->warning()
                    ->persistent()
                    ->send();
            }
        } elseif ($sale->status === SaleStatus::Cancelled) {
            Notification::make()
                ->title('Warning: Editing Cancelled Sale')
                ->body('You are editing a cancelled sale. All transactions will be reversed and re-applied based on new values.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $sale = $this->getRecord();
        $sale->loadMissing(['preparableItems', 'store.currency']);
        $multiplier = $sale->currencyMultiplier();

        // Separate regular variations from preparable variations
        $variations = [];
        $preparableVariations = [];

        // Pre-load all preparable items grouped by preparable_variation_id and sequence
        // This ensures we match correctly regardless of variation order
        $allPreparableItems = $sale->preparableItems()
            ->with('variation')
            ->get()
            ->groupBy(function ($item) {
                return $item->preparable_variation_id.'_'.$item->sequence;
            });

        // Track sequence per variation_id to match correctly
        $sequenceByVariationId = [];

        $saleVariationRows = DB::table('sale_variation')
            ->where('sale_id', $sale->id)
            ->whereNotNull('variation_id')
            ->orderBy('is_preparable')
            ->orderBy('variation_id')
            ->orderBy('stock_id')
            ->orderBy('description')
            ->get();

        foreach ($saleVariationRows as $saleVariationRow) {
            $isPreparable = (bool) ($saleVariationRow->is_preparable ?? false);

            if ($isPreparable) {
                // Load preparable variation
                $description = $saleVariationRow->description;
                $qty = (float) ($saleVariationRow->quantity ?? 1);
                $price = (float) ($saleVariationRow->unit_price ?? 0) / $multiplier;
                $tax = (float) ($saleVariationRow->tax ?? 0) / $multiplier;
                $discount = (float) ($saleVariationRow->discount ?? 0) / $multiplier;
                $total = (float) ($saleVariationRow->total ?? 0) / $multiplier;
                $discountType = $saleVariationRow->discount_type ?? 'flat';
                if ($discountType === 'percent') {
                    $discountType = 'percentage';
                }
                $discountPercentage = $saleVariationRow->discount_percentage ?? null;
                $unitDiscount = $qty != 0 ? round((float) $discount / (float) $qty, 2) : 0;

                // Determine sequence for this variation_id instance
                // Sequence is per variation_id, so we track it per variation_id
                $variationId = (int) $saleVariationRow->variation_id;
                if (! isset($sequenceByVariationId[$variationId])) {
                    $sequenceByVariationId[$variationId] = 0;
                }
                $sequence = $sequenceByVariationId[$variationId];
                $sequenceByVariationId[$variationId]++;

                // Load nested items for this preparable variation instance using stored sequence
                // Match by both preparable_variation_id AND sequence from database
                $preparableItems = [];
                $key = $variationId.'_'.$sequence;
                $nestedItems = $allPreparableItems->get($key, collect());

                foreach ($nestedItems as $item) {
                    $itemVariation = $item->variation;
                    $itemDescription = $itemVariation ? ($itemVariation->brand_name
                        ? $itemVariation->sku.' - '.$itemVariation->brand_name.' - '.$itemVariation->description
                        : $itemVariation->sku.' - '.$itemVariation->description) : 'Unknown';

                    $itemDiscountType = $item->discount_type ?? 'flat';
                    if ($itemDiscountType === 'percent') {
                        $itemDiscountType = 'percentage';
                    }
                    $itemDiscountPercentage = $item->discount_percentage ?? null;
                    $itemDiscount = (float) ($item->discount ?? 0);
                    $itemQuantity = (float) ($item->quantity ?? 1);
                    $itemUnitDiscount = $itemQuantity != 0 ? round($itemDiscount / $itemQuantity, 2) : 0;
                    $itemDisc = $itemDiscountType === 'percentage' && $itemDiscountPercentage !== null
                        ? SaleForm::formatPercentage((float) $itemDiscountPercentage)
                        : SaleForm::formatNumberForState($itemDiscount);

                    // Generate unique item_id for each nested item
                    $itemId = SaleForm::makePreparableItemId(
                        $sale->id,
                        $item->id,
                        $item->variation_id,
                        $item->stock_id,
                        $sequence
                    );

                    $preparableItems[] = [
                        'item_id' => $itemId,
                        'variation_id' => $item->variation_id,
                        'stock_id' => $item->stock_id ?? null,
                        'description' => $itemDescription,
                        'quantity' => $itemQuantity,
                        'unit_price' => round((float) ($item->unit_price ?? 0), 2),
                        'tax' => round((float) ($item->tax ?? 0), 2), // Include tax field
                        'unit_discount' => $itemUnitDiscount,
                        'disc' => $itemDisc,
                        'discount_type' => $itemDiscountType,
                        'discount_percentage' => $itemDiscountPercentage,
                        'discount_amount' => $itemDiscount,
                        'total' => round((float) ($item->total ?? 0), 2),
                    ];
                }

                // Format discount for display
                $discDisplay = SaleForm::formatNumberForState((float) $discount);
                if ($discountType === 'percentage' && $discountPercentage !== null) {
                    $discDisplay = SaleForm::formatPercentage((float) $discountPercentage);
                }

                // Generate a unique instance_id for this preparable variation instance
                // This allows us to identify which specific instance we're working with
                // even when multiple instances have the same variation_id
                $instanceId = SaleForm::makePreparableVariationInstanceId($sale->id, $variation->id, $sequence);

                $preparableVariations[] = [
                    'instance_id' => $instanceId,
                    'variation_id' => $variationId,
                    'stock_id' => $saleVariationRow->stock_id ?? null,
                    'description' => $description,
                    'qty' => $qty,
                    'price' => round((float) $price, 2),
                    'tax' => round((float) $tax, 2),
                    'unit_discount' => $unitDiscount,
                    'disc' => $discDisplay,
                    'discount_type' => $discountType,
                    'discount_percentage' => $discountPercentage,
                    'discount_amount' => round((float) $discount, 2),
                    'total' => round((float) $total, 2),
                    'preparable_items' => $preparableItems,
                ];
            } else {
                // Regular variation
                $description = $saleVariationRow->description;
                $quantity = (float) ($saleVariationRow->quantity ?? 1);
                $unitPrice = (float) ($saleVariationRow->unit_price ?? 0) / $multiplier;
                $tax = (float) ($saleVariationRow->tax ?? 0) / $multiplier;
                $discountAmount = (float) ($saleVariationRow->discount ?? 0) / $multiplier;
                $total = (float) ($saleVariationRow->total ?? 0) / $multiplier;
                $supplierPrice = (float) ($saleVariationRow->supplier_price ?? 0) / $multiplier;
                $discountType = $saleVariationRow->discount_type ?? 'flat';
                if ($discountType === 'percent') {
                    $discountType = 'percentage';
                }
                $discountPercentage = $saleVariationRow->discount_percentage ?? null;
                $unitDiscount = $quantity != 0 ? round((float) $discountAmount / (float) $quantity, 2) : 0;

                $discountDisplay = $discountType === 'percentage' && $discountPercentage !== null
                    ? SaleForm::formatPercentage((float) $discountPercentage)
                    : SaleForm::formatNumberForState((float) $discountAmount);

                $variations[] = [
                    'variation_id' => (int) $saleVariationRow->variation_id,
                    'stock_id' => $saleVariationRow->stock_id ?? null,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax' => $tax,
                    'unit_discount' => $unitDiscount,
                    'discount' => $discountDisplay,
                    'discount_amount' => round((float) $discountAmount, 2),
                    'discount_type' => $discountType,
                    'discount_percentage' => $discountPercentage,
                    'total' => $total,
                    'supplier_price' => $supplierPrice,
                ];
            }
        }

        $customVariations = DB::table('sale_variation')
            ->where('sale_id', $sale->id)
            ->whereNull('variation_id')
            ->where('is_preparable', false)
            ->get();

        foreach ($customVariations as $row) {
            $discountType = $row->discount_type ?? 'flat';
            if ($discountType === 'percent') {
                $discountType = 'percentage';
            }
            $discountPercentage = $row->discount_percentage ?? null;
            $discountAmount = (float) ($row->discount ?? 0) / $multiplier;
            $quantity = (float) ($row->quantity ?? 1);
            $unitDiscount = $quantity != 0 ? round($discountAmount / $quantity, 2) : 0;

            $discountDisplay = $discountType === 'percentage' && $discountPercentage !== null
                ? SaleForm::formatPercentage((float) $discountPercentage)
                : SaleForm::formatNumberForState($discountAmount);

            $variations[] = [
                'variation_id' => null,
                'stock_id' => null,
                'description' => $row->description ?? 'Custom item',
                'quantity' => $quantity,
                'unit_price' => round((float) ($row->unit_price ?? 0) / $multiplier, 2),
                'tax' => round((float) ($row->tax ?? 0) / $multiplier, 2),
                'unit_discount' => $unitDiscount,
                'discount' => $discountDisplay,
                'discount_amount' => round($discountAmount, 2),
                'discount_type' => $discountType,
                'discount_percentage' => $discountPercentage,
                'total' => round((float) ($row->total ?? 0) / $multiplier, 2),
                'supplier_price' => round((float) ($row->supplier_price ?? 0) / $multiplier, 2),
            ];
        }

        // Calculate subtotal including both regular and preparable variations
        $subtotal = 0;
        $totalTax = 0;

        // Regular variations
        foreach ($variations as $item) {
            $discountAmountValue = (float) ($item['discount_amount'] ?? 0);
            $subtotal += ($item['unit_price'] * $item['quantity']) - $discountAmountValue;
            $totalTax += (float) $item['tax'] * (float) $item['quantity'];
        }

        // Preparable variations
        // The 'total' field already includes nested items and discounts
        // Formula: ((preparable_price + items_total) * preparable_qty) - preparable_disc
        foreach ($preparableVariations as $item) {
            // Use the total field directly as it already includes nested items
            $preparableTotal = (float) ($item['total'] ?? 0);
            $subtotal += $preparableTotal;

            // Calculate tax: preparable variation tax * qty + nested items tax
            $preparableTax = (float) ($item['tax'] ?? 0) * (float) ($item['qty'] ?? 1);

            // Add nested items tax
            $preparableItems = $item['preparable_items'] ?? [];
            $nestedItemsTax = 0;
            foreach ($preparableItems as $nestedItem) {
                $nestedQty = (float) ($nestedItem['quantity'] ?? 1);
                $nestedTax = (float) ($nestedItem['tax'] ?? 0);
                $nestedItemsTax += $nestedTax * $nestedQty;
            }

            $totalTax += $preparableTax + $nestedItemsTax;
        }

        $discountAmount = (float) ($sale->discount ?? 0);
        $freightFare = ($data['freight_fare'] ?? $sale->freight_fare ?? 0);
        $total = $subtotal - $discountAmount + $freightFare;

        // Load discount type and percentage from database
        $discountType = $sale->discount_type ?? 'flat';
        $discountPercentage = $sale->discount_percentage ?? null;

        // Format discount field based on type - if percentage, set to percentage value for formatting
        // The afterStateHydrated hook will format it to "10%" display format
        if ($discountType === 'percentage' && $discountPercentage !== null) {
            $data['discount'] = SaleForm::formatPercentage((float) $discountPercentage);
        } else {
            $data['discount'] = SaleForm::formatNumberForState((float) $discountAmount);
        }

        $data['variations'] = $variations;
        $data['preparable_variations'] = $preparableVariations;
        $data['subtotal'] = round($subtotal, 2);
        $data['total_tax'] = round($totalTax, 2);
        $data['sale_discount_type'] = $discountType;
        $data['sale_discount_percentage'] = $discountPercentage;
        $data['sale_discount_amount'] = round($discountAmount, 2); // Store the calculated amount for saving
        $data['freight_fare'] = round($freightFare, 2);
        $data['total'] = round($total, 2);
        $data['sale_id'] = $sale->id;

        return $data;
    }
}
