<?php

namespace SmartTill\Core\Services;

use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use SmartTill\Core\Enums\CashTransactionType;
use SmartTill\Core\Enums\FbrEnvironment;
use SmartTill\Core\Enums\SalePaymentStatus;
use SmartTill\Core\Enums\SaleStatus;
use SmartTill\Core\Models\CashTransaction;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\SalePreparableItem;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\Variation;

class SaleTransactionService
{
    public function __construct(
        private readonly CashService $cashService,
        private readonly UserStoreCashService $userStoreCashService,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function removeCustomerTransactionForSale(Sale $sale, ?int $customerId = null): void
    {
        $customerId = $customerId ?? $sale->customer_id;
        if (! $customerId) {
            return;
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }

        $transaction = $customer->transactions()
            ->where('referenceable_type', Sale::class)
            ->where('referenceable_id', $sale->id)
            ->whereIn('type', ['customer_debit', 'customer_credit'])
            ->latest('id')
            ->first();

        if (! $transaction) {
            return;
        }

        $transaction->delete();

        $this->recalculateAmountBalances($customer);
    }

    public function handleSaleOnCompleted(Sale $sale): void
    {
        if ($sale->status !== SaleStatus::Completed) {
            return;
        }

        DB::transaction(function () use ($sale): void {
            $rows = $this->getSaleVariationRows($sale);
            $variations = $this->loadVariationsById($rows);

            $this->applyVariationStockTransactions($sale, $rows, $variations);
            $this->applyPreparableItemsStockTransactions($sale);
            $this->applyCustomerCreditTransaction($sale, $sale->payment_status);
            $this->generateFbrInvoice($sale);
        });
    }

    public function handleSaleOnCancelled(Sale $sale): void
    {
        $sale->status = SaleStatus::Cancelled;
        $wasPaid = $sale->payment_status === SalePaymentStatus::Paid;

        DB::transaction(function () use ($wasPaid, $sale): void {
            $rows = $this->getSaleVariationRows($sale);
            $variations = $this->loadVariationsById($rows);

            $this->reverseVariationStockTransactions($sale, $rows, $variations);
            $this->reversePreparableItemsStockTransactions($sale);
            $this->reverseCustomerCreditTransaction($sale, $sale->customer_id, $sale->payment_status);

            if ($sale->payment_status === SalePaymentStatus::Paid) {
                $sale->payment_status = SalePaymentStatus::Refunded;
            }

            $sale->save();

            if ($wasPaid) {
                $this->decreaseCashFromRefund($sale);
            }

            $this->generateFbrRefundInvoice($sale);
        });
    }

    /**
     * Safely handle editing a sale: reverse old transactions and apply new ones.
     */
    public function handleSaleEdit(
        Sale $sale,
        array $oldVariations,
        float $oldTotal,
        SalePaymentStatus $oldPaymentStatus,
        SalePaymentStatus $newPaymentStatus,
        float $newTotal,
        SaleStatus $oldStatus,
        SaleStatus $newStatus,
        ?int $oldCustomerId = null
    ): void {
        DB::transaction(function () use (
            $sale, $oldVariations, $oldPaymentStatus, $newPaymentStatus,
            $newTotal, $oldStatus, $newStatus, $oldCustomerId
        ): void {
            // Step 1: Reverse old stock transactions
            if ($oldStatus === SaleStatus::Completed || $oldStatus === SaleStatus::Cancelled) {
                $rows = collect($oldVariations);
                $variations = $this->loadVariationsById($rows);

                $this->reverseVariationStockTransactions($sale, $rows, $variations);

                $preparableVariationIds = collect($oldVariations)
                    ->filter(fn ($row) => (bool) ($row['is_preparable'] ?? false))
                    ->pluck('variation_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                $this->reversePreparableItemsStockTransactions($sale, $preparableVariationIds);
            }

            // Step 2: Reverse old customer transaction
            if ($oldPaymentStatus === SalePaymentStatus::Credit && $oldCustomerId) {
                $this->reverseCustomerCreditTransaction($sale, $oldCustomerId, $oldPaymentStatus);
            }

            // Step 3: Reverse old cash transactions
            $this->reverseOldCashTransactions($sale);

            // Step 4: Apply new transactions if the sale is being completed
            if ($newStatus === SaleStatus::Completed) {
                $sale->refresh();
                $rows = $this->getSaleVariationRows($sale);
                $variations = $this->loadVariationsById($rows);

                $this->applyVariationStockTransactions($sale, $rows, $variations);
                $this->applyPreparableItemsStockTransactions($sale);
                $this->applyCustomerCreditTransaction($sale, $newPaymentStatus);

                if ($newPaymentStatus === SalePaymentStatus::Paid && Auth::check() && $newTotal > 0) {
                    $user = Auth::user();
                    if ($user) {
                        $existing = CashTransaction::where('referenceable_type', Sale::class)
                            ->where('referenceable_id', $sale->id)
                            ->where('type', CashTransactionType::SalePaid->value)
                            ->first();

                        if (! $existing) {
                            $this->cashService->increaseFromSale($user, $sale);
                        }
                    }
                }
            }
        });
    }

    public function generateFbrInvoice(Sale $sale): void
    {
        if (! $this->shouldGenerateFbrInvoice($sale)) {
            return;
        }

        try {
            $fbrService = new FbrPosService($sale->store);
            $result = $fbrService->generateInvoice($sale);

            if ($result['success']) {
                $invoiceNumber = $result['invoice_number'];
                $qrCodeSvg = $invoiceNumber ? QrCode::size(50)->format('svg')->generate($invoiceNumber) : null;

                $sale->update([
                    'fbr_invoice_number' => $invoiceNumber,
                    'fbr_qr_code' => $qrCodeSvg,
                    'fbr_synced_at' => now(),
                    'fbr_response' => $result['data'],
                ]);

                Log::info('FBR Invoice generated successfully', [
                    'sale_id' => $sale->id,
                    'fbr_invoice_number' => $invoiceNumber,
                    'response_message' => $result['response_message'] ?? null,
                ]);
            } else {
                $sale->update(['fbr_response' => $result]);

                Log::error('FBR Invoice generation failed', [
                    'sale_id' => $sale->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'code' => $result['code'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('FBR Invoice generation failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function generateFbrRefundInvoice(Sale $sale): void
    {
        if (! $sale->use_fbr || ! $sale->fbr_invoice_number) {
            return;
        }

        try {
            $fbrService = new FbrPosService($sale->store);
            $result = $fbrService->generateRefundInvoice($sale);

            if ($result['success']) {
                $refundInvoiceNumber = $result['invoice_number'];
                $qrCodeSvg = $refundInvoiceNumber ? QrCode::size(50)->format('svg')->generate($refundInvoiceNumber) : null;

                Log::info('FBR Refund Invoice generated successfully', [
                    'sale_id' => $sale->id,
                    'original_invoice' => $sale->fbr_invoice_number,
                    'refund_invoice' => $refundInvoiceNumber,
                ]);

                $sale->update([
                    'fbr_refund_invoice_number' => $refundInvoiceNumber,
                    'fbr_refund_qr_code' => $qrCodeSvg,
                    'fbr_refund_synced_at' => now(),
                    'fbr_response' => array_merge($sale->fbr_response ?? [], [
                        'refund_invoice_number' => $refundInvoiceNumber,
                        'refund_response' => $result['data'],
                        'refunded_at' => now(),
                    ]),
                ]);
            } else {
                Log::error('FBR Refund Invoice generation failed', [
                    'sale_id' => $sale->id,
                    'original_invoice' => $sale->fbr_invoice_number,
                    'error' => $result['error'] ?? 'Unknown error',
                    'code' => $result['code'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('FBR Refund Invoice generation failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Stock transaction helpers — apply (completed sale)
    // -------------------------------------------------------------------------

    /**
     * Apply stock transactions for all regular and preparable variation rows.
     */
    private function applyVariationStockTransactions(Sale $sale, $rows, array $variations): void
    {
        foreach ($rows as $row) {
            $variation = $variations[$row->variation_id] ?? null;
            if (! $variation) {
                continue;
            }

            $quantity = (float) ($row->quantity ?? 0);
            $isPreparable = (bool) ($row->is_preparable ?? false);

            if ($isPreparable && $quantity != 0) {
                $this->applyFifoStockTransactions($sale, $variation, $quantity, [
                    'is_preparable' => true,
                ], null);
            } else {
                $barcodeId = $row->stock_id ?? null;

                $existing = $variation->transactions()
                    ->where('referenceable_type', Sale::class)
                    ->where('referenceable_id', $sale->id)
                    ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                    ->when($barcodeId, fn ($q) => $q->where('meta->stock_id', (int) $barcodeId))
                    ->first();

                if ($existing) {
                    continue;
                }

                $barcode = $this->resolveBarcode($variation->id, $barcodeId ? (int) $barcodeId : null);
                $lastBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;
                $newBalance = $lastBalance - $quantity;

                $variation->transactions()->create([
                    'store_id' => $sale->store_id,
                    'referenceable_type' => Sale::class,
                    'referenceable_id' => $sale->id,
                    'type' => $quantity > 0 ? 'variation_stock_out' : 'variation_stock_in',
                    'quantity' => $quantity * -1,
                    'quantity_balance' => $newBalance,
                    'note' => $quantity > 0 ? 'Stock out from sale' : 'Stock in from return',
                    'meta' => $barcode ? [
                        'stock_id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'batch_number' => $barcode->batch_number,
                    ] : null,
                ]);

                if ($barcode) {
                    $barcode->stock = (float) $barcode->stock - $quantity;
                    $barcode->saveQuietly();
                }
            }
        }
    }

    /**
     * Apply FIFO stock transactions for a preparable variation (or nested item).
     *
     * @param  array<string, mixed>  $extraMeta  Additional meta fields merged into the transaction.
     */
    private function applyFifoStockTransactions(
        Sale $sale,
        Variation $variation,
        float $quantity,
        array $extraMeta = [],
        ?SalePreparableItem $preparableItem = null,
    ): void {
        $orderDirection = $quantity > 0 ? 'asc' : 'desc';
        $barcodes = Stock::query()
            ->where('variation_id', $variation->id)
            ->orderBy('created_at', $orderDirection)
            ->get(['id', 'stock', 'tax_amount', 'supplier_price', 'barcode', 'batch_number']);

        $remaining = abs($quantity);
        $lastBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;

        foreach ($barcodes as $barcode) {
            if ($remaining <= 0) {
                break;
            }

            if ($quantity > 0) {
                $available = (float) $barcode->stock;
                if ($available <= 0) {
                    continue;
                }
                $take = min($remaining, $available);
            } else {
                $take = $remaining;
            }

            $takeQuantity = $quantity >= 0 ? $take : -$take;

            // Skip if transaction already exists for this stock
            $existingQuery = $variation->transactions()
                ->where('referenceable_type', Sale::class)
                ->where('referenceable_id', $sale->id)
                ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                ->where('meta->stock_id', $barcode->id);

            if ($preparableItem) {
                $existingQuery->where('meta->preparable_item_id', $preparableItem->id);
            } else {
                $existingQuery->whereNull('meta->preparable_item_id');
            }

            if ($existingQuery->exists()) {
                $remaining -= $take;

                continue;
            }

            $newBalance = $lastBalance - $takeQuantity;

            $meta = array_merge([
                'stock_id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'batch_number' => $barcode->batch_number,
            ], $extraMeta);

            if ($preparableItem) {
                $meta['preparable_item_id'] = $preparableItem->id;
                $meta['preparable_variation_id'] = $preparableItem->preparable_variation_id;
                $meta['sequence'] = $preparableItem->sequence ?? null;
            }

            $variation->transactions()->create([
                'store_id' => $sale->store_id,
                'referenceable_type' => Sale::class,
                'referenceable_id' => $sale->id,
                'type' => $takeQuantity > 0 ? 'variation_stock_out' : 'variation_stock_in',
                'quantity' => $takeQuantity * -1,
                'quantity_balance' => $newBalance,
                'note' => $this->stockTransactionNote($takeQuantity, $preparableItem !== null),
                'meta' => $meta,
            ]);

            $barcode->stock = (float) $barcode->stock - $takeQuantity;
            $barcode->saveQuietly();

            $lastBalance = $newBalance;
            $remaining -= $take;
        }
    }

    /**
     * Apply stock transactions for nested preparable items on the sale.
     */
    private function applyPreparableItemsStockTransactions(Sale $sale): void
    {
        $preparableItems = SalePreparableItem::where('sale_id', $sale->id)->get();
        if ($preparableItems->isEmpty()) {
            return;
        }

        $preparableVariationQuantitiesBySequence = $this->buildPreparableQuantityMap($sale);

        $variationIds = $preparableItems->pluck('variation_id')->filter()->unique()->values()->all();
        $nestedVariations = empty($variationIds) ? [] : Variation::query()
            ->whereIn('id', $variationIds)
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($preparableItems as $item) {
            $variation = $nestedVariations[$item->variation_id] ?? null;
            if (! $variation) {
                continue;
            }

            $preparableVariationId = $item->preparable_variation_id;
            $sequence = $item->sequence ?? 0;
            $preparableVariationQty = (float) ($preparableVariationQuantitiesBySequence[$preparableVariationId][$sequence] ?? 1);
            $itemQty = (float) ($item->quantity ?? 0);
            $totalQuantity = $preparableVariationQty * $itemQty;

            $barcodeId = $item->stock_id ?? null;

            // Check if transaction already exists
            $existingQuery = $variation->transactions()
                ->where('referenceable_type', Sale::class)
                ->where('referenceable_id', $sale->id)
                ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                ->where('meta->preparable_variation_id', $preparableVariationId)
                ->when($item->sequence !== null, fn ($q) => $q->where('meta->sequence', $item->sequence));

            if ($existingQuery->where('meta->preparable_item_id', $item->id)->exists()) {
                continue;
            }

            // For returns or when no barcodeId, use FIFO
            if ($totalQuantity < 0 || ($totalQuantity > 0 && ! $barcodeId)) {
                $this->applyFifoStockTransactions($sale, $variation, $totalQuantity, [], $item);
            } else {
                // Positive quantity with a known stock_id
                $barcode = $this->resolveBarcode($variation->id, $barcodeId ? (int) $barcodeId : null);
                $lastBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;
                $newBalance = $lastBalance - $totalQuantity;

                $meta = array_merge($barcode ? [
                    'stock_id' => $barcode->id,
                    'barcode' => $barcode->barcode,
                    'batch_number' => $barcode->batch_number,
                ] : [], [
                    'preparable_item_id' => $item->id,
                    'preparable_variation_id' => $item->preparable_variation_id,
                    'sequence' => $item->sequence ?? null,
                ]);

                $variation->transactions()->create([
                    'store_id' => $sale->store_id,
                    'referenceable_type' => Sale::class,
                    'referenceable_id' => $sale->id,
                    'type' => $totalQuantity > 0 ? 'variation_stock_out' : 'variation_stock_in',
                    'quantity' => $totalQuantity * -1,
                    'quantity_balance' => $newBalance,
                    'note' => $this->stockTransactionNote($totalQuantity, true),
                    'meta' => $meta,
                ]);

                if ($barcode) {
                    $barcode->stock = (float) $barcode->stock - $totalQuantity;
                    $barcode->saveQuietly();
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Stock transaction helpers — reverse (cancelled / edited sale)
    // -------------------------------------------------------------------------

    /**
     * Reverse stock transactions for regular and preparable variations.
     */
    private function reverseVariationStockTransactions(Sale $sale, $rows, array $variations): void
    {
        foreach ($rows as $row) {
            $variationId = is_array($row) ? ($row['variation_id'] ?? null) : ($row->variation_id ?? null);
            $variation = $variationId ? ($variations[$variationId] ?? null) : null;
            if (! $variation) {
                continue;
            }

            $stockId = is_array($row) ? ($row['stock_id'] ?? null) : ($row->stock_id ?? null);
            $quantity = (float) (is_array($row) ? ($row['quantity'] ?? 0) : ($row->quantity ?? 0));

            $stockTransactions = $variation->transactions()
                ->where('referenceable_type', Sale::class)
                ->where('referenceable_id', $sale->id)
                ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                ->when($stockId, fn ($q) => $q->where('meta->stock_id', (int) $stockId))
                ->get();

            if ($stockTransactions->isNotEmpty()) {
                $stockTransactions->each->delete();
                $this->recalculateQuantityBalances($variation);
            }

            if (! empty($stockId)) {
                $barcode = $this->resolveBarcode($variation->id, (int) $stockId);
                if ($barcode) {
                    $barcode->stock = (float) $barcode->stock + $quantity;
                    $barcode->saveQuietly();
                }
            }
        }
    }

    /**
     * Reverse stock transactions for nested preparable items.
     *
     * @param  array<int, int>  $preparableVariationIds  Optional filter; empty = auto-detect from sale's transactions.
     */
    private function reversePreparableItemsStockTransactions(Sale $sale, array $preparableVariationIds = []): void
    {
        if (empty($preparableVariationIds)) {
            // Detect from existing transactions on the sale
            $preparableVariationIds = Variation::whereHas('transactions', function ($q) use ($sale): void {
                $q->where('referenceable_type', Sale::class)
                    ->where('referenceable_id', $sale->id)
                    ->whereNotNull('meta->preparable_variation_id');
            })->pluck('id')->all();
        }

        if (empty($preparableVariationIds)) {
            return;
        }

        $allVariations = Variation::whereIn('id', $preparableVariationIds)->get();

        foreach ($allVariations as $variation) {
            $stockTransactions = $variation->transactions()
                ->where('referenceable_type', Sale::class)
                ->where('referenceable_id', $sale->id)
                ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                ->whereNotNull('meta->preparable_variation_id')
                ->get();

            if ($stockTransactions->isEmpty()) {
                continue;
            }

            // Calculate per-stock restoration quantities before deleting
            /** @var array<int, float> $stockQuantities */
            $stockQuantities = [];
            foreach ($stockTransactions as $transaction) {
                $meta = $transaction->meta ?? [];
                $stockId = $meta['stock_id'] ?? null;
                // Negate: a stored -5 (stock-out) restores +5
                $restore = -((float) ($transaction->quantity ?? 0));
                if ($stockId) {
                    $stockQuantities[(int) $stockId] = ($stockQuantities[(int) $stockId] ?? 0) + $restore;
                }
            }

            $stockTransactions->each->delete();
            $this->recalculateQuantityBalances($variation);

            foreach ($stockQuantities as $stockId => $restoreQty) {
                $barcode = $this->resolveBarcode($variation->id, $stockId);
                if ($barcode) {
                    $barcode->stock = (float) $barcode->stock + $restoreQty;
                    $barcode->saveQuietly();
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Customer transaction helpers
    // -------------------------------------------------------------------------

    private function applyCustomerCreditTransaction(Sale $sale, SalePaymentStatus $paymentStatus): void
    {
        if ($paymentStatus !== SalePaymentStatus::Credit || ! $sale->customer) {
            return;
        }

        $customer = $sale->customer;

        $exists = $customer->transactions()
            ->where('referenceable_type', Sale::class)
            ->where('referenceable_id', $sale->id)
            ->whereIn('type', ['customer_debit', 'customer_credit'])
            ->exists();

        if ($exists) {
            return;
        }

        $ledgerAmount = $sale->ledgerTotalAmount();
        $lastBalance = $customer->transactions()->latest('id')->value('amount_balance') ?? 0;
        $newBalance = $lastBalance + $ledgerAmount;

        $customer->transactions()->create([
            'store_id' => $sale->store_id,
            'referenceable_type' => Sale::class,
            'referenceable_id' => $sale->id,
            'type' => $ledgerAmount > 0 ? 'customer_debit' : 'customer_credit',
            'amount' => $ledgerAmount,
            'amount_balance' => $newBalance,
            'note' => $ledgerAmount > 0 ? 'Sale completed: customer debit' : 'Sale returned: customer credit',
        ]);
    }

    private function reverseCustomerCreditTransaction(
        Sale $sale,
        ?int $customerId,
        SalePaymentStatus $oldPaymentStatus,
    ): void {
        if ($oldPaymentStatus !== SalePaymentStatus::Credit || ! $customerId) {
            return;
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }

        $transaction = $customer->transactions()
            ->where('referenceable_type', Sale::class)
            ->where('referenceable_id', $sale->id)
            ->whereIn('type', ['customer_debit', 'customer_credit'])
            ->latest('id')
            ->first();

        if (! $transaction) {
            // Cancelled but paid — create a credit reversal
            if ($sale->payment_status !== SalePaymentStatus::Credit) {
                $lastBalance = $customer->transactions()->latest('id')->value('amount_balance') ?? 0;
                $amount = abs($sale->total);
                $customer->transactions()->create([
                    'store_id' => $sale->store_id,
                    'referenceable_type' => Sale::class,
                    'referenceable_id' => $sale->id,
                    'type' => 'customer_credit',
                    'amount' => -$amount,
                    'amount_balance' => $lastBalance - $amount,
                    'note' => 'Sale cancelled: customer credit reversed',
                ]);
            }

            return;
        }

        $transaction->delete();
        $this->recalculateAmountBalances($customer);
    }

    // -------------------------------------------------------------------------
    // Cash helpers
    // -------------------------------------------------------------------------

    private function decreaseCashFromRefund(Sale $sale): void
    {
        try {
            $user = Filament::auth()->user() ?? Auth::user();
            if ($user) {
                $this->cashService->decreaseFromSaleRefund($user, $sale);
            } else {
                Log::warning('SaleTransactionService: no authenticated user for cash refund', [
                    'sale_id' => $sale->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SaleTransactionService: failed to decrease cash from sale refund', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function reverseOldCashTransactions(Sale $sale): void
    {
        if (! Auth::check()) {
            return;
        }

        $user = Auth::user();
        if (! $user) {
            return;
        }

        $oldCashTransactions = CashTransaction::where('referenceable_type', Sale::class)
            ->where('referenceable_id', $sale->id)
            ->whereIn('type', [
                CashTransactionType::SalePaid->value,
                CashTransactionType::SaleCancelled->value,
                CashTransactionType::SaleRefunded->value,
            ])
            ->get();

        foreach ($oldCashTransactions as $cashTransaction) {
            $oldAmount = abs($cashTransaction->amount);

            if ($cashTransaction->type === CashTransactionType::SalePaid->value) {
                if ($oldAmount > 0) {
                    $this->userStoreCashService->decrementCashInHandForStore($user, $sale->store_id, $oldAmount);
                }
            } else {
                if ($oldAmount > 0) {
                    $this->userStoreCashService->incrementCashInHandForStore($user, $sale->store_id, $oldAmount);
                }
            }

            $cashTransaction->delete();
        }
    }

    // -------------------------------------------------------------------------
    // FBR helpers
    // -------------------------------------------------------------------------

    private function shouldGenerateFbrInvoice(Sale $sale): bool
    {
        if (! $sale->use_fbr) {
            return false;
        }

        $hasPosId = $sale->store->fbr_environment === FbrEnvironment::SANDBOX
            ? $sale->store->fbr_sandbox_pos_id
            : $sale->store->fbr_pos_id;

        if (! $hasPosId) {
            return false;
        }

        if ($sale->store->fbr_environment === FbrEnvironment::SANDBOX) {
            return true;
        }

        return ! empty($sale->store->fbr_bearer_token);
    }

    // -------------------------------------------------------------------------
    // Balance recalculation helpers
    // -------------------------------------------------------------------------

    /**
     * Recalculate running quantity_balance for a variation after transaction deletions.
     */
    private function recalculateQuantityBalances(Variation $variation): void
    {
        $first = $variation->transactions()->orderBy('id')->first();
        $base = $first ? ($first->quantity_balance - $first->quantity) : 0;

        $calculated = $base;
        foreach ($variation->transactions()->orderBy('id')->get() as $tx) {
            $calculated += $tx->quantity ?? 0;
            $tx->quantity_balance = $calculated;
            $tx->saveQuietly();
        }
    }

    /**
     * Recalculate running amount_balance for a customer after transaction deletions.
     */
    private function recalculateAmountBalances(Customer $customer): void
    {
        $first = $customer->transactions()->orderBy('id')->first();
        $base = $first ? ($first->amount_balance - $first->amount) : 0;

        $calculated = $base;
        foreach ($customer->transactions()->orderBy('id')->get() as $tx) {
            $calculated += $tx->amount ?? 0;
            $tx->amount_balance = $calculated;
            $tx->saveQuietly();
        }
    }

    // -------------------------------------------------------------------------
    // Utility helpers
    // -------------------------------------------------------------------------

    /**
     * Build a variation_id → sequence → quantity mapping for preparable variations on a sale.
     *
     * @return array<int, array<int, float>>
     */
    private function buildPreparableQuantityMap(Sale $sale): array
    {
        $preparableVariations = $sale->variations()
            ->wherePivot('is_preparable', true)
            ->get()
            ->map(fn ($v) => (object) [
                'variation_id' => $v->id,
                'quantity' => $v->pivot->quantity,
            ]);

        $map = [];
        $sequenceCounters = [];

        foreach ($preparableVariations as $pv) {
            $vid = $pv->variation_id;
            $seq = $sequenceCounters[$vid] ?? 0;
            $map[$vid][$seq] = (float) $pv->quantity;
            $sequenceCounters[$vid] = $seq + 1;
        }

        return $map;
    }

    private function stockTransactionNote(float $quantity, bool $isPreparable): string
    {
        $direction = $quantity > 0 ? 'out' : 'in';
        $context = $isPreparable ? 'preparable product sale' : 'sale';

        return $direction === 'out'
            ? "Stock out from {$context}"
            : "Stock in from {$context} return";
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function getSaleVariationRows(Sale $sale)
    {
        return DB::table('sale_variation')
            ->where('sale_id', $sale->id)
            ->selectRaw('variation_id, stock_id, is_preparable, SUM(quantity) as quantity')
            ->groupBy('variation_id', 'stock_id', 'is_preparable')
            ->orderBy('variation_id')
            ->orderBy('stock_id')
            ->get();
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return array<int, Variation>
     */
    private function loadVariationsById($rows): array
    {
        $ids = collect($rows)->pluck('variation_id')->filter()->unique()->values()->all();
        if (empty($ids)) {
            return [];
        }

        return Variation::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function resolveBarcode(int $variationId, ?int $barcodeId): ?Stock
    {
        if ($barcodeId) {
            $barcode = Stock::query()
                ->where('variation_id', $variationId)
                ->whereKey($barcodeId)
                ->first();

            if ($barcode) {
                return $barcode;
            }
        }

        return Stock::query()
            ->where('variation_id', $variationId)
            ->orderByRaw("case when batch_number = 'M-1' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }
}
