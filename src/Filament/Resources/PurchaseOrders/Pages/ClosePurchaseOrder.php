<?php

namespace SmartTill\Core\Filament\Resources\PurchaseOrders\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use SmartTill\Core\Enums\PurchaseOrderStatus;
use SmartTill\Core\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use SmartTill\Core\Filament\Resources\PurchaseOrders\Schemas\ReceiveForm;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Services\PurchaseOrderTransactionService;

class ClosePurchaseOrder extends Page implements HasSchemas
{
    use InteractsWithRecord, InteractsWithSchemas;

    protected static string $resource = PurchaseOrderResource::class;

    // Form data properties
    public array $purchaseOrderProducts = [];

    public string $supplierName = '';

    public string $reference = '';

    public string $status = '';

    // Summary calculation properties
    public float $total_received_quantity = 0;

    public float $total_received_unit_price = 0;

    public float $total_received_tax_amount = 0;

    public float $total_received_supplier_price = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->load('supplier');

        // Load purchase order details
        $this->supplierName = $this->record->supplier?->name ?? '';
        $this->reference = $this->record->reference ?? '';

        // Load purchase order products with variation data
        // PriceCast already divides by 100 when accessing pivot values, so values are already in display format
        // Refresh the relationship to ensure PurchaseOrderProduct model is used
        $this->record->load('variations');
        $store = \Filament\Facades\Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;
        $this->purchaseOrderProducts = $this->record->variations()
            ->with('product')
            ->get()
            ->map(function ($variation) use ($decimalPlaces) {
                $pivot = $variation->pivot;

                // PriceCast returns null if value is null, so we can use ?? operator properly
                $receivedUnitPrice = $pivot->received_unit_price;
                if ($receivedUnitPrice === null) {
                    $receivedUnitPrice = $pivot->requested_unit_price;
                }

                $receivedTaxAmount = $pivot->received_tax_amount;
                if ($receivedTaxAmount === null) {
                    $receivedTaxAmount = $pivot->requested_tax_amount;
                }

                $receivedSupplierPrice = $pivot->received_supplier_price;
                if ($receivedSupplierPrice === null) {
                    $receivedSupplierPrice = $pivot->requested_supplier_price;
                }

                $receivedTaxPercentage = $pivot->received_tax_percentage ?? $pivot->requested_tax_percentage;
                $receivedTaxInput = null;
                if (is_numeric($receivedTaxPercentage) && (float) $receivedTaxPercentage > 0) {
                    $receivedTaxInput = rtrim(rtrim(number_format((float) $receivedTaxPercentage, 6, '.', ''), '0'), '.').'%';
                }

                $receivedSupplierPercentage = $pivot->received_supplier_percentage ?? $pivot->requested_supplier_percentage;
                $receivedSupplierIsPercentage = $pivot->received_supplier_is_percentage ?? $pivot->requested_supplier_is_percentage;
                $receivedSupplierInput = null;
                if ($receivedSupplierIsPercentage === true && is_numeric($receivedSupplierPercentage)) {
                    $receivedSupplierInput = rtrim(rtrim(number_format((float) $receivedSupplierPercentage, 6, '.', ''), '0'), '.').'%';
                } elseif ($receivedSupplierIsPercentage === false && is_numeric($receivedSupplierPrice)) {
                    $receivedSupplierInput = rtrim(rtrim(number_format((float) $receivedSupplierPrice, $decimalPlaces, '.', ''), '0'), '.');
                } elseif (is_numeric($receivedSupplierPercentage) && (float) $receivedSupplierPercentage > 0) {
                    $receivedSupplierInput = rtrim(rtrim(number_format((float) $receivedSupplierPercentage, 6, '.', ''), '0'), '.').'%';
                } elseif (is_numeric($receivedSupplierPrice)) {
                    $receivedSupplierInput = rtrim(rtrim(number_format((float) $receivedSupplierPrice, $decimalPlaces, '.', ''), '0'), '.');
                }

                $lastBarcode = Stock::query()
                    ->where('variation_id', $variation->id)
                    ->latest('id')
                    ->value('barcode');

                return [
                    'variation_id' => $variation->id,
                    'description' => $variation->sku.' - '.$variation->description,
                    'requested_quantity' => $pivot->requested_quantity,
                    'requested_unit_id' => $pivot->requested_unit_id,
                    'requested_unit_price' => $pivot->requested_unit_price, // Already divided by 100 via PriceCast
                    'requested_tax_percentage' => $pivot->requested_tax_percentage,
                    'requested_tax_amount' => $pivot->requested_tax_amount, // Already divided by 100 via PriceCast
                    'requested_supplier_percentage' => $pivot->requested_supplier_percentage,
                    'requested_supplier_is_percentage' => $pivot->requested_supplier_is_percentage,
                    'requested_supplier_price' => $pivot->requested_supplier_price, // Already divided by 100 via PriceCast
                    'received_quantity' => $pivot->received_quantity ?? $pivot->requested_quantity,
                    'received_unit_id' => $pivot->received_unit_id ?? $pivot->requested_unit_id,
                    'received_unit_price' => $receivedUnitPrice, // Already divided by 100 via PriceCast
                    'received_tax_percentage' => $pivot->received_tax_percentage ?? $pivot->requested_tax_percentage,
                    'received_tax_amount' => $receivedTaxAmount, // Already divided by 100 via PriceCast
                    'received_tax_input' => $receivedTaxInput,
                    'received_supplier_percentage' => $pivot->received_supplier_percentage ?? $pivot->requested_supplier_percentage,
                    'received_supplier_is_percentage' => $pivot->received_supplier_is_percentage ?? $pivot->requested_supplier_is_percentage,
                    'received_supplier_price' => $receivedSupplierPrice, // Already divided by 100 via PriceCast
                    'received_supplier_input' => $receivedSupplierInput,
                    'barcode' => $lastBarcode,
                ];
            })
            ->toArray();

        // Pre-calculate summary totals for initial render.
        $store = \Filament\Facades\Filament::getTenant();
        $currency = $store?->currency;
        $decimalPlaces = $currency->decimal_places ?? 2;
        $itemsCount = collect($this->purchaseOrderProducts)
            ->filter(fn ($item) => isset($item['received_quantity']) && (float) ($item['received_quantity'] ?? 0) > 0)
            ->count();
        $sumUnit = 0;
        $sumTax = 0;
        $sumSupplier = 0;
        foreach ($this->purchaseOrderProducts as $item) {
            $qty = (float) ($item['received_quantity'] ?? 0);
            $unitPrice = (float) ($item['received_unit_price'] ?? 0);
            $taxAmount = (float) ($item['received_tax_amount'] ?? 0);
            $supplierPercentage = $item['received_supplier_percentage'] ?? null;
            $supplierPriceValue = $item['received_supplier_price'] ?? null;
            $inputIsPercent = $item['received_supplier_is_percentage'] ?? null;

            $supplierPrice = 0.0;
            if (is_numeric($supplierPriceValue)) {
                $supplierPrice = (float) $supplierPriceValue;
            } elseif ($inputIsPercent === true && is_numeric($supplierPercentage) && $unitPrice > 0) {
                $supplierPrice = round($unitPrice - ($unitPrice * ((float) $supplierPercentage / 100)), $decimalPlaces);
            } elseif (is_numeric($supplierPercentage) && $unitPrice > 0) {
                $supplierPrice = round($unitPrice - ($unitPrice * ((float) $supplierPercentage / 100)), $decimalPlaces);
            }

            $sumUnit += $qty * $unitPrice;
            if ($store?->tax_enabled && $taxAmount > 0) {
                $sumTax += $qty * $taxAmount;
            }
            $sumSupplier += $qty * $supplierPrice;
        }

        $this->total_received_quantity = $itemsCount;
        $this->total_received_unit_price = round($sumUnit, $decimalPlaces);
        if ($store?->tax_enabled) {
            $this->total_received_tax_amount = round($sumTax, $decimalPlaces);
        } else {
            $this->total_received_tax_amount = 0;
        }
        $this->total_received_supplier_price = round($sumSupplier, $decimalPlaces);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel')
                ->color('gray')
                ->url(PurchaseOrderResource::getUrl('index')),

            Action::make('close')
                ->label('Mark as Closed')
                ->color('success')
                ->icon(Heroicon::OutlinedCheck)
                ->visible(fn () => \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Close Purchase Orders'))
                ->authorize(fn () => \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Close Purchase Orders'))
                ->action('save')
                ->requiresConfirmation()
                ->modalHeading('Close Purchase Order')
                ->modalDescription('Are you sure you want to mark this purchase order as closed? This action cannot be undone.')
                ->modalSubmitActionLabel('Yes, Close'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return ReceiveForm::configure($schema);
    }

    public function save(): void
    {
        $receivedBarcodesByVariation = [];

        // Update received quantities and prices for each variation
        foreach ($this->purchaseOrderProducts as $item) {
            $variation = $this->record->variations()
                ->where('variation_id', $item['variation_id'])
                ->first();

            if ($variation) {
                $pivot = $variation->pivot;
                // PriceCast automatically multiplies by 100 in its set method, so we pass display format values
                $pivot->received_quantity = $item['received_quantity'] ?? 0;
                $pivot->received_unit_id = $item['received_unit_id'] ?? $pivot->requested_unit_id;
                $pivot->received_unit_price = $item['received_unit_price'] ?? 0;
                $pivot->received_tax_percentage = $item['received_tax_percentage'] ?? 0;
                $pivot->received_tax_amount = $item['received_tax_amount'] ?? 0;
                $pivot->received_supplier_percentage = $item['received_supplier_percentage'] ?? 0;
                $pivot->received_supplier_is_percentage = $item['received_supplier_is_percentage']
                    ?? $item['requested_supplier_is_percentage']
                    ?? null;
                $pivot->received_supplier_price = $item['received_supplier_price'] ?? 0;
                $pivot->save();

                $barcode = trim((string) ($item['barcode'] ?? ''));

                if ($barcode !== '') {
                    $receivedBarcodesByVariation[$variation->id] = $barcode;
                }
            }
        }

        // Mark purchase order as closed and update totals
        $this->record->status = PurchaseOrderStatus::Closed;
        $this->record->save();
        $this->record->recalculateTotals();

        // Handle financial transactions
        app(PurchaseOrderTransactionService::class)->handlePurchaseOrderClosed(
            $this->record,
            $receivedBarcodesByVariation
        );

        // Show success notification and redirect
        Notification::make()
            ->title('Purchase order closed')
            ->body('Purchase order details saved successfully.')
            ->success();

        $this->redirect(PurchaseOrderResource::getUrl('index'));
    }
}
