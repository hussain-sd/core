<?php

namespace SmartTill\Core\Services;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use SmartTill\Core\Enums\CashTransactionType;
use SmartTill\Core\Enums\SalePaymentStatus;
use SmartTill\Core\Enums\SaleStatus;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\SalePreparableItem;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\Variation;

class SaleTransactionService
{
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

        $firstTransaction = $customer->transactions()
            ->orderBy('id', 'asc')
            ->first();

        $baseBalance = $firstTransaction
            ? ($firstTransaction->amount_balance - $firstTransaction->amount)
            : 0;

        $remainingTransactions = $customer->transactions()
            ->orderBy('id', 'asc')
            ->get();

        $calculatedBalance = $baseBalance;
        foreach ($remainingTransactions as $remaining) {
            $calculatedBalance += $remaining->amount ?? 0;
            $remaining->amount_balance = $calculatedBalance;
            $remaining->saveQuietly();
        }
    }

    public function handleSaleOnCompleted(Sale $sale): void
    {
        if ($sale->status !== SaleStatus::Completed) {
            return;
        }

        // Use safe transaction to ensure data consistency
        DB::transaction(function () use ($sale) {
            // Check if stock transactions already exist to prevent duplicates
            $rows = $this->getSaleVariationRows($sale);
            $variations = $this->loadVariationsById($rows);

            foreach ($rows as $row) {
                $variation = $variations[$row->variation_id] ?? null;
                if (! $variation) {
                    continue;
                }

                // Process ALL variations (including preparable variations)
                // Stock will be deducted from preparable variations AND their nested items
                // We don't skip preparable variations anymore - they should also deduct stock

                $quantity = (float) ($row->quantity ?? 0);
                // Check is_preparable flag directly from the row, not from a list
                // This ensures each row is treated correctly even if the same variation_id appears both as preparable and regular
                $isPreparable = (bool) ($row->is_preparable ?? false);

                // For preparable variations, use FIFO to distribute quantity across multiple stocks
                // For regular variations, use the stock_id from pivot (already expanded via expandFifoVariations)
                if ($isPreparable && $quantity != 0) {
                    // Use FIFO for sales (positive quantity) - oldest stock first
                    // Use LIFO for returns (negative quantity) - newest stock first
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

                        // For returns (negative quantity), we can restore to any stock
                        // For sales (positive quantity), only use available stock
                        if ($quantity > 0) {
                            $available = (float) $barcode->stock;
                            if ($available <= 0) {
                                continue;
                            }
                            $take = min($remaining, $available);
                        } else {
                            // For returns, we can restore stock even if current stock is 0
                            // Take the full remaining amount or what fits
                            $take = $remaining;
                        }

                        $takeQuantity = $quantity >= 0 ? $take : -$take; // Preserve sign

                        // Check if transaction already exists for this specific stock
                        $existingTransaction = $variation->transactions()
                            ->where('referenceable_type', Sale::class)
                            ->where('referenceable_id', $sale->id)
                            ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                            ->where('meta->stock_id', $barcode->id)
                            ->whereNull('meta->preparable_item_id') // Only preparable variation transactions, not nested items
                            ->first();

                        if ($existingTransaction) {
                            $remaining -= $take;

                            continue;
                        }

                        $newVariationBalance = $lastBalance - $takeQuantity;

                        $variation->transactions()
                            ->create([
                                'store_id' => $sale->store_id,
                                'referenceable_type' => Sale::class,
                                'referenceable_id' => $sale->id,
                                'type' => ($takeQuantity > 0) ? 'variation_stock_out' : 'variation_stock_in',
                                'quantity' => $takeQuantity * -1,
                                'quantity_balance' => $newVariationBalance,
                                'note' => ($takeQuantity > 0) ? 'Stock out from preparable variation sale' : 'Stock in from preparable variation return',
                                'meta' => [
                                    'stock_id' => $barcode->id,
                                    'barcode' => $barcode->barcode,
                                    'batch_number' => $barcode->batch_number,
                                    'is_preparable' => true,
                                ],
                            ]);

                        $barcode->stock = (float) $barcode->stock - $takeQuantity;
                        $barcode->saveQuietly();

                        $lastBalance = $newVariationBalance;
                        $remaining -= $take;
                    }
                } else {
                    // Regular variations: use stock_id from pivot (already FIFO expanded)
                    $barcodeId = $row->stock_id ?? null;
                    $barcode = $this->resolveBarcode($variation->id, $barcodeId ? (int) $barcodeId : null);

                    // Check if transaction already exists
                    $existingTransaction = $variation->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                        ->when($barcodeId, fn ($query) => $query->where('meta->stock_id', (int) $barcodeId))
                        ->first();

                    if ($existingTransaction) {
                        continue; // Skip if already exists
                    }

                    $lastBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;
                    $newVariationBalance = $lastBalance - $quantity;

                    $variation->transactions()
                        ->create([
                            'store_id' => $sale->store_id,
                            'referenceable_type' => Sale::class,
                            'referenceable_id' => $sale->id,
                            'type' => ($quantity > 0) ? 'variation_stock_out' : 'variation_stock_in',
                            'quantity' => $quantity * -1,
                            'quantity_balance' => $newVariationBalance,
                            'note' => ($quantity > 0) ? 'Stock out from sale' : 'Stock in from return',
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

            // Handle stock removal for nested preparable items using FIFO
            $preparableItems = SalePreparableItem::where('sale_id', $sale->id)->get();

            // Get preparable variation quantities from pivot table
            // IMPORTANT: Use Eloquent relationship to maintain insertion order
            // This handles multiple instances of the same variation_id correctly
            $preparableVariations = $sale->variations()
                ->wherePivot('is_preparable', true)
                ->get()
                ->map(fn ($v) => (object) [
                    'variation_id' => $v->id,
                    'quantity' => $v->pivot->quantity,
                ]);

            // Create a mapping: variation_id -> [quantities by sequence/index]
            // Since sale_variation doesn't have sequence, we use the order of insertion
            // We'll match preparable items using their sequence as the index
            $preparableVariationQuantitiesBySequence = [];
            $sequenceByVariationId = [];

            foreach ($preparableVariations as $pv) {
                $vid = $pv->variation_id;
                if (! isset($sequenceByVariationId[$vid])) {
                    $sequenceByVariationId[$vid] = 0;
                }
                $sequence = $sequenceByVariationId[$vid];
                $preparableVariationQuantitiesBySequence[$vid][$sequence] = (float) $pv->quantity;
                $sequenceByVariationId[$vid]++;
            }

            // Load variations by their IDs - need to convert collection of IDs to array
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

                // IMPORTANT: Match preparable variation quantity using both variation_id AND sequence
                // This correctly handles multiple instances of the same preparable variation
                $preparableVariationId = $item->preparable_variation_id;
                $sequence = $item->sequence ?? 0;
                $preparableVariationQty = (float) ($preparableVariationQuantitiesBySequence[$preparableVariationId][$sequence] ?? 1);
                $itemQty = (float) ($item->quantity ?? 0);
                $totalQuantity = $preparableVariationQty * $itemQty;

                // Use FIFO for sales (positive quantity) - oldest stock first
                // Use LIFO for returns (negative quantity) - newest stock first
                $orderDirection = $totalQuantity > 0 ? 'asc' : 'desc';
                $barcodes = Stock::query()
                    ->where('variation_id', $variation->id)
                    ->orderBy('created_at', $orderDirection)
                    ->get(['id', 'stock', 'tax_amount', 'supplier_price', 'barcode', 'batch_number']);

                $remaining = abs($totalQuantity);
                $lastBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;

                foreach ($barcodes as $barcode) {
                    if ($remaining <= 0) {
                        break;
                    }

                    // For returns (negative quantity), we can restore to any stock
                    // For sales (positive quantity), only use available stock
                    if ($totalQuantity > 0) {
                        $available = (float) $barcode->stock;
                        if ($available <= 0) {
                            continue;
                        }
                        $take = min($remaining, $available);
                    } else {
                        // For returns, we can restore stock even if current stock is 0
                        // Take the full remaining amount
                        $take = $remaining;
                    }

                    $quantity = $totalQuantity >= 0 ? $take : -$take; // Preserve sign

                    // Check if transaction already exists for this specific stock
                    $existingTransaction = $variation->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                        ->where('meta->stock_id', $barcode->id)
                        ->where('meta->preparable_item_id', $item->id)
                        ->first();

                    if ($existingTransaction) {
                        $remaining -= $take;

                        continue;
                    }

                    $newVariationBalance = $lastBalance - $quantity;

                    $variation->transactions()
                        ->create([
                            'store_id' => $sale->store_id,
                            'referenceable_type' => Sale::class,
                            'referenceable_id' => $sale->id,
                            'type' => ($quantity > 0) ? 'variation_stock_out' : 'variation_stock_in',
                            'quantity' => $quantity * -1,
                            'quantity_balance' => $newVariationBalance,
                            'note' => ($quantity > 0) ? 'Stock out from preparable product sale' : 'Stock in from preparable product return',
                            'meta' => [
                                'stock_id' => $barcode->id,
                                'barcode' => $barcode->barcode,
                                'batch_number' => $barcode->batch_number,
                                'preparable_item_id' => $item->id,
                                'preparable_variation_id' => $item->preparable_variation_id,
                                'sequence' => $item->sequence ?? null,
                            ],
                        ]);

                    $barcode->stock = (float) $barcode->stock - $quantity;
                    $barcode->saveQuietly();

                    $lastBalance = $newVariationBalance;
                    $remaining -= $take;
                }
            }

            // Handle customer transaction if sale is on credit
            if ($sale->payment_status === SalePaymentStatus::Credit && $sale->customer) {
                $customer = $sale->customer;

                // Check if customer transaction already exists
                $existingCustomerTransaction = $customer->transactions()
                    ->where('referenceable_type', Sale::class)
                    ->where('referenceable_id', $sale->id)
                    ->whereIn('type', ['customer_debit', 'customer_credit'])
                    ->first();

                if (! $existingCustomerTransaction) {
                    $lastBalance = $customer->transactions()->latest('id')->value('amount_balance') ?? 0;
                    $newBalance = $lastBalance + $sale->total;

                    $customer->transactions()
                        ->create([
                            'store_id' => $sale->store_id,
                            'referenceable_type' => Sale::class,
                            'referenceable_id' => $sale->id,
                            'type' => ($sale->total > 0) ? 'customer_debit' : 'customer_credit',
                            'amount' => $sale->total,
                            'amount_balance' => $newBalance,
                            'note' => ($sale->total > 0) ? 'Sale completed: customer debit' : 'Sale returned: customer credit',
                        ]);
                }
            }

            // Generate FBR invoice after sale completion
            $this->generateFbrInvoice($sale);
        });
    }

    public function handleSaleOnCancelled(Sale $sale): void
    {
        $sale->status = SaleStatus::Cancelled;
        $wasPaid = $sale->payment_status === SalePaymentStatus::Paid;

        // Use safe transaction to ensure data consistency
        DB::transaction(function () use ($wasPaid, $sale) {
            $rows = $this->getSaleVariationRows($sale);
            $variations = $this->loadVariationsById($rows);

            foreach ($rows as $row) {
                $variation = $variations[$row->variation_id] ?? null;
                if (! $variation) {
                    continue;
                }

                // Check is_preparable flag directly from the row, not from a list
                // This ensures each row is treated correctly even if the same variation_id appears both as preparable and regular
                $isPreparable = (bool) ($row->is_preparable ?? false);

                // For preparable variations, find all transactions created for this sale
                // and restore stock to the exact stocks that were deducted (FIFO)
                if ($isPreparable) {
                    // Find all transactions for this preparable variation (not nested items)
                    $preparableVariationTransactions = $variation->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                        ->where(function ($query) {
                            $query->whereNull('meta->preparable_item_id')
                                ->orWhere('meta->is_preparable', true);
                        })
                        ->get();

                    $lastVariationBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;

                    foreach ($preparableVariationTransactions as $transaction) {
                        $meta = $transaction->meta ?? [];
                        $stockId = $meta['stock_id'] ?? null;
                        if (! $stockId) {
                            continue;
                        }

                        $barcode = Stock::find($stockId);
                        if (! $barcode) {
                            continue;
                        }

                        // Reverse the transaction: if it was stock out (-5), restore (+5)
                        // Transaction quantity is negative for stock out, positive for stock in
                        $originalQuantity = (float) ($transaction->quantity ?? 0);
                        $reverseQuantity = -$originalQuantity; // Reverse the sign

                        $newVariationBalance = $lastVariationBalance + $reverseQuantity;

                        // Create reverse transaction
                        $variation->transactions()->create([
                            'store_id' => $sale->store_id,
                            'referenceable_type' => Sale::class,
                            'referenceable_id' => $sale->id,
                            'type' => ($reverseQuantity > 0) ? 'variation_stock_in' : 'variation_stock_out',
                            'quantity' => $reverseQuantity,
                            'quantity_balance' => $newVariationBalance,
                            'note' => ($reverseQuantity > 0) ? 'Stock restored on preparable variation cancellation' : 'Stock deducted on preparable variation return cancellation',
                            'meta' => [
                                'stock_id' => $barcode->id,
                                'barcode' => $barcode->barcode,
                                'batch_number' => $barcode->batch_number,
                                'is_preparable' => true,
                            ],
                        ]);

                        // Restore stock to the exact stock that was deducted
                        $barcode->stock = (float) $barcode->stock + (float) $reverseQuantity;
                        $barcode->saveQuietly();

                        $lastVariationBalance = $newVariationBalance;
                    }
                } else {
                    // Regular variations: use stock_id from pivot (already FIFO expanded)
                    $barcodeId = $row->stock_id ?? null;
                    $barcode = $this->resolveBarcode($variation->id, $barcodeId ? (int) $barcodeId : null);

                    $lastVariationBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;
                    $quantity = (float) ($row->quantity ?? 0);

                    // Reverse the quantity: if original was +5 (stock out), we add +5 (stock in)
                    // If original was -5 (stock in/return), we add -5 (stock out)
                    $reverseQuantity = $quantity; // Keep the sign - positive quantity restores stock, negative quantity deducts it
                    $newVariationBalance = $lastVariationBalance + $reverseQuantity;

                    // Determine transaction type: if quantity is positive, it was a sale (stock out), so cancellation is stock in
                    // If quantity is negative, it was a return (stock in), so cancellation is stock out
                    $transactionType = ($quantity > 0) ? 'variation_stock_in' : 'variation_stock_out';
                    $note = ($quantity > 0)
                        ? 'Stock restored on sale cancellation'
                        : 'Stock deducted on return cancellation';

                    $variation->transactions()->create([
                        'store_id' => $sale->store_id,
                        'referenceable_type' => Sale::class,
                        'referenceable_id' => $sale->id,
                        'type' => $transactionType,
                        'quantity' => $reverseQuantity,
                        'quantity_balance' => $newVariationBalance,
                        'note' => $note,
                        'meta' => $barcode ? [
                            'stock_id' => $barcode->id,
                            'barcode' => $barcode->barcode,
                            'batch_number' => $barcode->batch_number,
                        ] : null,
                    ]);

                    if ($barcode) {
                        // Restore stock: if quantity was positive (sale), add it back
                        // If quantity was negative (return), subtract it (reverse the return)
                        $barcode->stock = (float) $barcode->stock + (float) $reverseQuantity;
                        $barcode->saveQuietly();
                    }
                }
            }

            // Restore stock for nested preparable items
            // IMPORTANT: Find all transactions created for preparable items and restore stock
            // to the exact stocks that were deducted (FIFO)
            $preparableItems = SalePreparableItem::where('sale_id', $sale->id)->get();

            // Load variations by their IDs - need to convert collection of IDs to array
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

                // Find all transactions for this specific preparable item
                // These transactions were created with FIFO, so we restore to the exact stocks
                $preparableItemTransactions = $variation->transactions()
                    ->where('referenceable_type', Sale::class)
                    ->where('referenceable_id', $sale->id)
                    ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                    ->where('meta->preparable_item_id', $item->id)
                    ->get();

                $lastVariationBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;

                foreach ($preparableItemTransactions as $transaction) {
                    $meta = $transaction->meta ?? [];
                    $stockId = $meta['stock_id'] ?? null;
                    if (! $stockId) {
                        continue;
                    }

                    $barcode = Stock::find($stockId);
                    if (! $barcode) {
                        continue;
                    }

                    // Reverse the transaction: if it was stock out (-5), restore (+5)
                    // Transaction quantity is negative for stock out, positive for stock in
                    $originalQuantity = (float) ($transaction->quantity ?? 0);
                    $reverseQuantity = -$originalQuantity; // Reverse the sign

                    $newVariationBalance = $lastVariationBalance + $reverseQuantity;

                    // Create reverse transaction
                    $variation->transactions()->create([
                        'store_id' => $sale->store_id,
                        'referenceable_type' => Sale::class,
                        'referenceable_id' => $sale->id,
                        'type' => ($reverseQuantity > 0) ? 'variation_stock_in' : 'variation_stock_out',
                        'quantity' => $reverseQuantity,
                        'quantity_balance' => $newVariationBalance,
                        'note' => ($reverseQuantity > 0) ? 'Stock restored on preparable product sale cancellation' : 'Stock deducted on preparable product return cancellation',
                        'meta' => [
                            'stock_id' => $barcode->id,
                            'barcode' => $barcode->barcode,
                            'batch_number' => $barcode->batch_number,
                            'preparable_item_id' => $item->id,
                            'preparable_variation_id' => $item->preparable_variation_id,
                            'sequence' => $item->sequence ?? null,
                        ],
                    ]);

                    // Restore stock to the exact stock that was deducted
                    $barcode->stock = (float) $barcode->stock + (float) $reverseQuantity;
                    $barcode->saveQuietly();

                    $lastVariationBalance = $newVariationBalance;
                }
            }

            // Reverse customer transaction if sale was on credit
            if ($sale->payment_status === SalePaymentStatus::Credit && $sale->customer) {
                $customer = $sale->customer;

                $lastCustomerBalance = $customer->transactions()->latest('id')->value('amount_balance') ?? 0;
                $amount = abs($sale->total);

                $newCustomerBalance = $lastCustomerBalance - $amount;

                $customer->transactions()->create([
                    'store_id' => $sale->store_id,
                    'referenceable_type' => Sale::class,
                    'referenceable_id' => $sale->id,
                    'type' => 'customer_credit',
                    'amount' => -$amount,
                    'amount_balance' => $newCustomerBalance,
                    'note' => 'Sale cancelled: customer credit reversed',
                ]);
            }

            // Update payment status to Refunded if it was Paid
            if ($sale->payment_status === SalePaymentStatus::Paid) {
                $sale->payment_status = SalePaymentStatus::Refunded;
            }

            $sale->save();

            // Decrease cash in hand if sale was paid
            if ($wasPaid) {
                try {
                    $cashService = app(\SmartTill\Core\Services\CashService::class);
                    $user = Filament::auth()->user() ?? \Illuminate\Support\Facades\Auth::user();
                    if ($user) {
                        $cashService->decreaseFromSaleRefund($user, $sale);
                    } else {
                        Log::warning('SaleTransactionService::handleSaleOnCancelled - No authenticated user for cash refund', [
                            'sale_id' => $sale->id,
                        ]);
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail the cancellation process
                    Log::error('SaleTransactionService::handleSaleOnCancelled - Failed to decrease cash from sale refund', [
                        'sale_id' => $sale->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // Generate FBR refund invoice if original sale had FBR invoice
            $this->generateFbrRefundInvoice($sale);
        });
    }

    /**
     * Generate FBR refund invoice for cancelled sale
     */
    public function generateFbrRefundInvoice(Sale $sale): void
    {
        // Only generate refund invoice if:
        // 1. Original sale had FBR enabled
        // 2. Original sale has an FBR invoice number
        if (! $sale->use_fbr || ! $sale->fbr_invoice_number) {
            return;
        }

        try {
            $fbrService = new FbrPosService($sale->store);
            $result = $fbrService->generateRefundInvoice($sale);

            if ($result['success']) {
                // Generate QR code for the FBR refund invoice number
                $refundInvoiceNumber = $result['invoice_number'];

                // Only generate QR code if we have a valid refund invoice number
                $qrCodeSvg = null;
                if ($refundInvoiceNumber) {
                    $qrCodeSvg = QrCode::size(50)->format('svg')->generate($refundInvoiceNumber);
                }

                Log::info('FBR Refund Invoice generated successfully', [
                    'sale_id' => $sale->id,
                    'original_invoice' => $sale->fbr_invoice_number,
                    'refund_invoice' => $refundInvoiceNumber,
                ]);

                // Update sale with refund invoice info
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

    /**
     * Generate FBR invoice for a sale
     */
    public function generateFbrInvoice(Sale $sale): void
    {
        if (! $this->shouldGenerateFbrInvoice($sale)) {
            return;
        }

        try {
            $fbrService = new FbrPosService($sale->store);
            $result = $fbrService->generateInvoice($sale);

            if ($result['success']) {
                // Generate QR code for the FBR invoice number
                $invoiceNumber = $result['invoice_number'];

                // Only generate QR code if we have a valid invoice number
                $qrCodeSvg = null;
                if ($invoiceNumber) {
                    $qrCodeSvg = QrCode::size(50)->format('svg')->generate($invoiceNumber);
                }

                // Update sale with FBR information
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
                // Save error response to database so user can see it in UI
                $sale->update([
                    'fbr_response' => $result,
                ]);

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

    /**
     * Check if FBR invoice should be generated
     */
    private function shouldGenerateFbrInvoice(Sale $sale): bool
    {
        // First check if user wants to generate FBR invoice for this sale
        if (! $sale->use_fbr) {
            return false;
        }

        // Check if we have the appropriate POS ID for the current environment
        $hasPosId = $sale->store->fbr_environment === \SmartTill\Core\Enums\FbrEnvironment::SANDBOX
            ? $sale->store->fbr_sandbox_pos_id
            : $sale->store->fbr_pos_id;

        if (! $hasPosId) {
            return false;
        }

        // For sandbox, we use static token, so no bearer token check needed
        if ($sale->store->fbr_environment === \SmartTill\Core\Enums\FbrEnvironment::SANDBOX) {
            return true;
        }

        // For production, check if bearer token is configured
        return ! empty($sale->store->fbr_bearer_token);
    }

    /**
     * Safely handle editing any sale by reversing old transactions and applying new ones
     * This handles all edge cases: payment status changes, stock changes, cash adjustments
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
        DB::transaction(function () use ($sale, $oldVariations, $oldPaymentStatus, $newPaymentStatus, $newTotal, $oldStatus, $newStatus, $oldCustomerId) {
            // Step 1: Reverse old stock transactions (only if sale was completed/cancelled)
            if ($oldStatus === SaleStatus::Completed || $oldStatus === SaleStatus::Cancelled) {
                $rows = collect($oldVariations);
                $variations = $this->loadVariationsById($rows);

                foreach ($rows as $row) {
                    $variationId = is_array($row) ? ($row['variation_id'] ?? null) : ($row->variation_id ?? null);
                    $variation = $variationId ? ($variations[$variationId] ?? null) : null;
                    if (! $variation) {
                        continue;
                    }

                    // Check is_preparable flag directly from the row, not from a list
                    // This ensures each row is treated correctly even if the same variation_id appears both as preparable and regular
                    $isPreparable = (bool) (is_array($row) ? ($row['is_preparable'] ?? false) : ($row->is_preparable ?? false));

                    // Process ALL variations (including preparable variations)
                    // Stock will be reversed for preparable variations AND their nested items
                    // We don't skip preparable variations anymore - they should also reverse stock

                    $stockId = is_array($row) ? ($row['stock_id'] ?? null) : ($row->stock_id ?? null);
                    $quantity = (float) (is_array($row) ? ($row['quantity'] ?? 0) : ($row->quantity ?? 0));

                    $stockTransactions = $variation->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                        ->when($stockId, fn ($query) => $query->where('meta->stock_id', (int) $stockId))
                        ->get();

                    if ($stockTransactions->isNotEmpty()) {
                        $stockTransactions->each->delete();

                        $firstTransaction = $variation->transactions()
                            ->orderBy('id', 'asc')
                            ->first();

                        $baseStock = $firstTransaction
                            ? ($firstTransaction->quantity_balance - $firstTransaction->quantity)
                            : 0;

                        $remainingTransactions = $variation->transactions()
                            ->orderBy('id', 'asc')
                            ->get();

                        $calculatedBalance = $baseStock;
                        foreach ($remainingTransactions as $transaction) {
                            $calculatedBalance += $transaction->quantity ?? 0;
                            $transaction->quantity_balance = $calculatedBalance;
                            $transaction->saveQuietly();
                        }
                    }

                    if (! empty($stockId)) {
                        $barcode = $this->resolveBarcode($variation->id, (int) $stockId);
                        if ($barcode) {
                            $barcode->stock = (float) $barcode->stock + $quantity;
                            $barcode->saveQuietly();
                        }
                    }
                }

                // Reverse stock transactions for nested preparable items
                // IMPORTANT: Since preparable items are deleted and recreated during edit,
                // we need to find ALL transactions for this sale that have preparable_variation_id in meta
                // and reverse them, then we'll create new ones based on the current preparable items

                // Get all variation IDs that have preparable items
                $preparableVariationIds = collect($oldVariations)
                    ->filter(fn ($row) => (bool) ($row['is_preparable'] ?? false))
                    ->pluck('variation_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                // Find ALL transactions for this sale that are related to preparable items
                // We'll reverse all of them, then create new ones based on current preparable items
                $allVariations = Variation::whereIn('id', $preparableVariationIds)->get();

                foreach ($allVariations as $variation) {
                    // Find all preparable item transactions for this variation and sale
                    $stockTransactions = $variation->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                        ->whereNotNull('meta->preparable_variation_id')
                        ->get();

                    if ($stockTransactions->isNotEmpty()) {
                        // Calculate total quantity to restore for each stock_id
                        // IMPORTANT: Reverse the sign of transaction quantity to restore stock correctly
                        // If transaction was -5 (stock out), we add +5 (stock in)
                        // If transaction was +5 (stock in/return), we add -5 (stock out)
                        $stockQuantities = [];
                        foreach ($stockTransactions as $transaction) {
                            $meta = $transaction->meta ?? [];
                            $stockId = $meta['stock_id'] ?? null;
                            // Reverse the sign: transaction quantity is negative for stock out, positive for stock in
                            // To reverse, we negate it: -(-5) = +5, or -(+5) = -5
                            $quantity = -((float) ($transaction->quantity ?? 0));

                            if ($stockId) {
                                $stockQuantities[$stockId] = ($stockQuantities[$stockId] ?? 0) + $quantity;
                            }
                        }

                        // Delete all old transactions
                        $stockTransactions->each->delete();

                        // Recalculate balances
                        $firstTransaction = $variation->transactions()
                            ->orderBy('id', 'asc')
                            ->first();

                        $baseStock = $firstTransaction
                            ? ($firstTransaction->quantity_balance - $firstTransaction->quantity)
                            : 0;

                        $remainingTransactions = $variation->transactions()
                            ->orderBy('id', 'asc')
                            ->get();

                        $calculatedBalance = $baseStock;
                        foreach ($remainingTransactions as $transaction) {
                            $calculatedBalance += $transaction->quantity ?? 0;
                            $transaction->quantity_balance = $calculatedBalance;
                            $transaction->saveQuietly();
                        }

                        // Restore stock for each barcode
                        foreach ($stockQuantities as $stockId => $quantity) {
                            $barcode = $this->resolveBarcode($variation->id, (int) $stockId);
                            if ($barcode) {
                                $barcode->stock = (float) $barcode->stock + $quantity;
                                $barcode->saveQuietly();
                            }
                        }
                    }
                }

                // Note: Preparable items transactions have been reversed above
                // New transactions will be created based on current preparable items in Step 4
            }

            // Step 2: Reverse old customer transaction if sale was on credit
            // Use oldCustomerId to find and reverse transaction from the old customer
            // This handles the case where customer was changed
            if ($oldPaymentStatus === SalePaymentStatus::Credit && $oldCustomerId) {
                $oldCustomer = \SmartTill\Core\Models\Customer::find($oldCustomerId);

                if ($oldCustomer) {
                    $oldCustomerTransaction = $oldCustomer->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['customer_debit', 'customer_credit'])
                        ->latest('id')
                        ->first();

                    if ($oldCustomerTransaction) {
                        $oldCustomerTransaction->delete();

                        // Recalculate old customer balance
                        $firstTransaction = $oldCustomer->transactions()
                            ->orderBy('id', 'asc')
                            ->first();

                        $baseBalance = $firstTransaction
                            ? ($firstTransaction->amount_balance - $firstTransaction->amount)
                            : 0;

                        $remainingTransactions = $oldCustomer->transactions()
                            ->orderBy('id', 'asc')
                            ->get();

                        $calculatedBalance = $baseBalance;
                        foreach ($remainingTransactions as $transaction) {
                            $calculatedBalance += $transaction->amount ?? 0;
                            $transaction->amount_balance = $calculatedBalance;
                            $transaction->saveQuietly();
                        }
                    }
                }
            }

            // Step 3: Reverse old cash transactions
            if (\Illuminate\Support\Facades\Auth::check()) {
                $user = \Illuminate\Support\Facades\Auth::user();
                if ($user) {
                    $oldCashTransactions = \SmartTill\Core\Models\CashTransaction::where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', [
                            CashTransactionType::SalePaid->value,
                            CashTransactionType::SaleCancelled->value,
                            CashTransactionType::SaleRefunded->value,
                        ])
                        ->get();

                    foreach ($oldCashTransactions as $oldCashTransaction) {
                        $oldAmount = abs($oldCashTransaction->amount);

                        // Reverse cash transaction
                        if ($oldCashTransaction->type === CashTransactionType::SalePaid->value) {
                            // Was added, so subtract
                            if ($oldAmount > 0) {
                                app(UserStoreCashService::class)->decrementCashInHandForStore($user, $sale->store_id, $oldAmount);
                            }
                        } else {
                            // Was subtracted (cancelled/refunded), so add back
                            if ($oldAmount > 0) {
                                app(UserStoreCashService::class)->incrementCashInHandForStore($user, $sale->store_id, $oldAmount);
                            }
                        }

                        $oldCashTransaction->delete();
                    }
                }
            }

            // Step 4: Apply new transactions based on new state
            // Only apply if sale is being completed
            // Note: Stock transactions should be applied even if total is 0 or negative (for returns)
            if ($newStatus === SaleStatus::Completed) {
                // Apply new stock transactions
                $sale->refresh();

                $rows = $this->getSaleVariationRows($sale);
                $variations = $this->loadVariationsById($rows);

                foreach ($rows as $row) {
                    $variation = $variations[$row->variation_id] ?? null;
                    if (! $variation) {
                        continue;
                    }

                    // Process ALL variations (including preparable variations)
                    // Stock will be deducted from preparable variations AND their nested items
                    // We don't skip preparable variations anymore - they should also deduct stock

                    $quantity = (float) ($row->quantity ?? 0);
                    // Check is_preparable flag directly from the row, not from a list
                    // This ensures each row is treated correctly even if the same variation_id appears both as preparable and regular
                    $isPreparable = (bool) ($row->is_preparable ?? false);

                    // For preparable variations, use FIFO to distribute quantity across multiple stocks
                    // For regular variations, use the stock_id from pivot (already expanded via expandFifoVariations)
                    if ($isPreparable && $quantity != 0) {
                        // Use FIFO for sales (positive quantity) - oldest stock first
                        // Use LIFO for returns (negative quantity) - newest stock first
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

                            // For returns (negative quantity), we can restore to any stock
                            // For sales (positive quantity), only use available stock
                            if ($quantity > 0) {
                                $available = (float) $barcode->stock;
                                if ($available <= 0) {
                                    continue;
                                }
                                $take = min($remaining, $available);
                            } else {
                                // For returns, we can restore stock even if current stock is 0
                                // Take the full remaining amount or what fits
                                $take = $remaining;
                            }

                            $takeQuantity = $quantity >= 0 ? $take : -$take; // Preserve sign

                            // Check if transaction already exists for this specific stock
                            $existingTransaction = $variation->transactions()
                                ->where('referenceable_type', Sale::class)
                                ->where('referenceable_id', $sale->id)
                                ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                                ->where('meta->stock_id', $barcode->id)
                                ->whereNull('meta->preparable_item_id') // Only preparable variation transactions, not nested items
                                ->first();

                            if ($existingTransaction) {
                                $remaining -= $take;

                                continue;
                            }

                            $newVariationBalance = $lastBalance - $takeQuantity;

                            $variation->transactions()
                                ->create([
                                    'store_id' => $sale->store_id,
                                    'referenceable_type' => Sale::class,
                                    'referenceable_id' => $sale->id,
                                    'type' => ($takeQuantity > 0) ? 'variation_stock_out' : 'variation_stock_in',
                                    'quantity' => $takeQuantity * -1,
                                    'quantity_balance' => $newVariationBalance,
                                    'note' => ($takeQuantity > 0) ? 'Stock out from preparable variation sale' : 'Stock in from preparable variation return',
                                    'meta' => [
                                        'stock_id' => $barcode->id,
                                        'barcode' => $barcode->barcode,
                                        'batch_number' => $barcode->batch_number,
                                        'is_preparable' => true,
                                    ],
                                ]);

                            $barcode->stock = (float) $barcode->stock - $takeQuantity;
                            $barcode->saveQuietly();

                            $lastBalance = $newVariationBalance;
                            $remaining -= $take;
                        }
                    } else {
                        // Regular variations: use stock_id from pivot (already FIFO expanded)
                        $barcodeId = $row->stock_id ?? null;
                        $existingTransaction = $variation->transactions()
                            ->where('referenceable_type', Sale::class)
                            ->where('referenceable_id', $sale->id)
                            ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                            ->when($barcodeId, fn ($query) => $query->where('meta->stock_id', (int) $barcodeId))
                            ->first();

                        if ($existingTransaction) {
                            continue; // Skip if already exists
                        }

                        $lastBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;
                        $newVariationBalance = $lastBalance - $quantity;

                        $barcode = $this->resolveBarcode($variation->id, $barcodeId ? (int) $barcodeId : null);

                        $variation->transactions()
                            ->create([
                                'store_id' => $sale->store_id,
                                'referenceable_type' => Sale::class,
                                'referenceable_id' => $sale->id,
                                'type' => ($quantity > 0) ? 'variation_stock_out' : 'variation_stock_in',
                                'quantity' => $quantity * -1,
                                'quantity_balance' => $newVariationBalance,
                                'note' => ($quantity > 0) ? 'Stock out from sale' : 'Stock in from return',
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

                // Handle stock removal for nested preparable items
                $preparableItems = SalePreparableItem::where('sale_id', $sale->id)->get();

                // Get preparable variation quantities from pivot table
                // IMPORTANT: Use Eloquent relationship to maintain insertion order
                // This handles multiple instances of the same variation_id correctly
                $preparableVariations = $sale->variations()
                    ->wherePivot('is_preparable', true)
                    ->get()
                    ->map(fn ($v) => (object) [
                        'variation_id' => $v->id,
                        'quantity' => $v->pivot->quantity,
                    ]);

                // Create a mapping: variation_id -> [quantities by sequence/index]
                // Since sale_variation doesn't have sequence, we use the order of insertion
                // We'll match preparable items using their sequence as the index
                $preparableVariationQuantitiesBySequence = [];
                $sequenceByVariationId = [];

                foreach ($preparableVariations as $pv) {
                    $vid = $pv->variation_id;
                    if (! isset($sequenceByVariationId[$vid])) {
                        $sequenceByVariationId[$vid] = 0;
                    }
                    $sequence = $sequenceByVariationId[$vid];
                    $preparableVariationQuantitiesBySequence[$vid][$sequence] = (float) $pv->quantity;
                    $sequenceByVariationId[$vid]++;
                }

                // Load variations by their IDs - need to convert collection of IDs to array
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

                    $barcodeId = $item->stock_id ?? null;
                    $barcode = $this->resolveBarcode($variation->id, $barcodeId ? (int) $barcodeId : null);

                    // Check if transaction already exists
                    // Match by variation_id, stock_id, sale_id, and preparable_variation_id
                    // Also check sequence if available to ensure we match the correct instance
                    $existingTransaction = $variation->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                        ->when($barcodeId, fn ($query) => $query->where('meta->stock_id', (int) $barcodeId))
                        ->where('meta->preparable_variation_id', $item->preparable_variation_id)
                        ->when($item->sequence !== null, fn ($query) => $query->where('meta->sequence', $item->sequence))
                        ->where('meta->preparable_item_id', $item->id)
                        ->first();

                    // If no exact match, try without preparable_item_id (for cases where items were recreated)
                    if (! $existingTransaction) {
                        $existingTransaction = $variation->transactions()
                            ->where('referenceable_type', Sale::class)
                            ->where('referenceable_id', $sale->id)
                            ->whereIn('type', ['product_stock_out', 'product_stock_in', 'variation_stock_out', 'variation_stock_in'])
                            ->when($barcodeId, fn ($query) => $query->where('meta->stock_id', (int) $barcodeId))
                            ->where('meta->preparable_variation_id', $item->preparable_variation_id)
                            ->when($item->sequence !== null, fn ($query) => $query->where('meta->sequence', $item->sequence))
                            ->first();
                    }

                    if ($existingTransaction) {
                        continue; // Skip if already exists
                    }

                    $lastBalance = $variation->transactions()->latest('id')->value('quantity_balance') ?? 0;

                    // IMPORTANT: Match preparable variation quantity using both variation_id AND sequence
                    // This correctly handles multiple instances of the same preparable variation
                    $preparableVariationId = $item->preparable_variation_id;
                    $sequence = $item->sequence ?? 0;
                    $preparableVariationQty = (float) ($preparableVariationQuantitiesBySequence[$preparableVariationId][$sequence] ?? 1);
                    $itemQty = (float) ($item->quantity ?? 0);
                    $totalQuantity = $preparableVariationQty * $itemQty;

                    // For returns (negative quantity), use FIFO/LIFO logic instead of stock_id
                    // For sales (positive quantity), use stock_id if available, otherwise FIFO
                    if ($totalQuantity < 0 || ($totalQuantity > 0 && ! $barcodeId)) {
                        // Use FIFO for sales (positive quantity) - oldest stock first
                        // Use LIFO for returns (negative quantity) - newest stock first
                        $orderDirection = $totalQuantity > 0 ? 'asc' : 'desc';
                        $barcodes = Stock::query()
                            ->where('variation_id', $variation->id)
                            ->orderBy('created_at', $orderDirection)
                            ->get(['id', 'stock', 'tax_amount', 'supplier_price', 'barcode', 'batch_number']);

                        $remaining = abs($totalQuantity);
                        $quantity = 0;

                        foreach ($barcodes as $stockBarcode) {
                            if ($remaining <= 0) {
                                break;
                            }

                            // For returns (negative quantity), we can restore to any stock
                            // For sales (positive quantity), only use available stock
                            if ($totalQuantity > 0) {
                                $available = (float) $stockBarcode->stock;
                                if ($available <= 0) {
                                    continue;
                                }
                                $take = min($remaining, $available);
                            } else {
                                // For returns, we can restore stock even if current stock is 0
                                $take = $remaining;
                            }

                            $takeQuantity = $totalQuantity >= 0 ? $take : -$take;
                            $quantity += $takeQuantity;
                            $remaining -= $take;

                            // Create transaction for this stock
                            $newVariationBalance = $lastBalance - $takeQuantity;
                            $lastBalance = $newVariationBalance;

                            $variation->transactions()
                                ->create([
                                    'store_id' => $sale->store_id,
                                    'referenceable_type' => Sale::class,
                                    'referenceable_id' => $sale->id,
                                    'type' => ($takeQuantity > 0) ? 'variation_stock_out' : 'variation_stock_in',
                                    'quantity' => $takeQuantity * -1,
                                    'quantity_balance' => $newVariationBalance,
                                    'note' => ($takeQuantity > 0) ? 'Stock out from preparable product sale' : 'Stock in from preparable product return',
                                    'meta' => [
                                        'stock_id' => $stockBarcode->id,
                                        'barcode' => $stockBarcode->barcode,
                                        'batch_number' => $stockBarcode->batch_number,
                                        'preparable_item_id' => $item->id,
                                        'preparable_variation_id' => $item->preparable_variation_id,
                                        'sequence' => $item->sequence ?? null,
                                    ],
                                ]);

                            $stockBarcode->stock = (float) $stockBarcode->stock - $takeQuantity;
                            $stockBarcode->saveQuietly();
                        }
                    } else {
                        // Use stock_id for positive quantities when stock_id is available
                        $quantity = $totalQuantity;
                        $newVariationBalance = $lastBalance - $quantity;

                        $variation->transactions()
                            ->create([
                                'store_id' => $sale->store_id,
                                'referenceable_type' => Sale::class,
                                'referenceable_id' => $sale->id,
                                'type' => ($quantity > 0) ? 'variation_stock_out' : 'variation_stock_in',
                                'quantity' => $quantity * -1,
                                'quantity_balance' => $newVariationBalance,
                                'note' => ($quantity > 0) ? 'Stock out from preparable product sale' : 'Stock in from preparable product return',
                                'meta' => array_merge($barcode ? [
                                    'stock_id' => $barcode->id,
                                    'barcode' => $barcode->barcode,
                                    'batch_number' => $barcode->batch_number,
                                ] : [], [
                                    'preparable_item_id' => $item->id,
                                    'preparable_variation_id' => $item->preparable_variation_id,
                                    'sequence' => $item->sequence ?? null,
                                ]),
                            ]);

                        if ($barcode) {
                            $barcode->stock = (float) $barcode->stock - $quantity;
                            $barcode->saveQuietly();
                        }
                    }

                    if ($barcode) {
                        $barcode->stock = (float) $barcode->stock - $quantity;
                        $barcode->saveQuietly();
                    }
                }

                // Apply new customer transaction if sale is on credit
                if ($newPaymentStatus === SalePaymentStatus::Credit && $sale->customer) {
                    $customer = $sale->customer;

                    // Check if transaction already exists
                    $existingCustomerTransaction = $customer->transactions()
                        ->where('referenceable_type', Sale::class)
                        ->where('referenceable_id', $sale->id)
                        ->whereIn('type', ['customer_debit', 'customer_credit'])
                        ->first();

                    if (! $existingCustomerTransaction) {
                        $lastBalance = $customer->transactions()->latest('id')->value('amount_balance') ?? 0;
                        $newBalance = $lastBalance + $newTotal;

                        $customer->transactions()
                            ->create([
                                'store_id' => $sale->store_id,
                                'referenceable_type' => Sale::class,
                                'referenceable_id' => $sale->id,
                                'type' => ($newTotal > 0) ? 'customer_debit' : 'customer_credit',
                                'amount' => $newTotal,
                                'amount_balance' => $newBalance,
                                'note' => ($newTotal > 0) ? 'Sale completed: customer debit' : 'Sale returned: customer credit',
                            ]);
                    }
                }

                // Apply new cash transaction if sale is paid
                // Only add cash if total is positive (negative totals are returns, don't add cash)
                if ($newPaymentStatus === SalePaymentStatus::Paid && \Illuminate\Support\Facades\Auth::check() && $newTotal > 0) {
                    $user = \Illuminate\Support\Facades\Auth::user();
                    if ($user) {
                        // Check if cash transaction already exists
                        $existingCashTransaction = \SmartTill\Core\Models\CashTransaction::where('referenceable_type', Sale::class)
                            ->where('referenceable_id', $sale->id)
                            ->where('type', CashTransactionType::SalePaid->value)
                            ->first();

                        if (! $existingCashTransaction) {
                            $cashService = app(\SmartTill\Core\Services\CashService::class);
                            $cashService->increaseFromSale($user, $sale);
                        }
                    }
                }
            }
        });
    }

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

    private function loadVariationsById($rows): array
    {
        $ids = $rows->pluck('variation_id')->filter()->unique()->values()->all();
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
