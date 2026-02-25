<?php

namespace SmartTill\Core\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SmartTill\Core\Enums\CashTransactionType;
use SmartTill\Core\Models\CashTransaction;
use SmartTill\Core\Models\Payment;
use SmartTill\Core\Models\Sale;

class CashService
{
    /**
     * Increase cash in hand for a user when a sale is paid
     */
    public function increaseFromSale(User $user, Sale $sale): void
    {
        // Validation checks
        if (! $user || ! $sale) {
            Log::error('CashService::increaseFromSale called with null user or sale', [
                'user_id' => $user?->id,
                'sale_id' => $sale?->id,
            ]);

            return;
        }

        if ($sale->payment_status !== \SmartTill\Core\Enums\SalePaymentStatus::Paid) {
            return;
        }

        if (! $sale->store_id) {
            Log::error('CashService::increaseFromSale called with sale missing store_id', [
                'user_id' => $user->id,
                'sale_id' => $sale->id,
            ]);

            return;
        }

        $amount = $sale->total ?? 0;
        if ($amount <= 0) {
            Log::warning('CashService::increaseFromSale called with invalid amount', [
                'user_id' => $user->id,
                'sale_id' => $sale->id,
                'amount' => $amount,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($user, $sale, $amount) {
                $userStoreCashService = app(UserStoreCashService::class);

                // Get current cash in hand for this store
                $currentBalance = $userStoreCashService->getCashInHandForStore($user, $sale->store_id);
                $newBalance = $currentBalance + $amount;

                // Check if cash transaction already exists to prevent duplicates
                $existingTransaction = CashTransaction::where('referenceable_type', Sale::class)
                    ->where('referenceable_id', $sale->id)
                    ->where('type', CashTransactionType::SalePaid->value)
                    ->first();

                if ($existingTransaction) {
                    Log::warning('CashService::increaseFromSale - Cash transaction already exists for sale', [
                        'user_id' => $user->id,
                        'sale_id' => $sale->id,
                        'existing_transaction_id' => $existingTransaction->id,
                    ]);

                    return;
                }

                // Update user's cash in hand for this store
                $userStoreCashService->incrementCashInHandForStore($user, $sale->store_id, $amount);

                // Create cash transaction record
                CashTransaction::create([
                    'user_id' => $user->id,
                    'store_id' => $sale->store_id,
                    'type' => CashTransactionType::SalePaid->value,
                    'amount' => $amount,
                    'cash_balance' => $newBalance,
                    'referenceable_type' => Sale::class,
                    'referenceable_id' => $sale->id,
                    'note' => "Sale #{$sale->reference} - Payment received",
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to increase cash from sale', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'sale_id' => $sale->id,
                'sale_total' => $amount,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Increase cash in hand for a user when payment is received (only for cash payments)
     */
    public function increaseFromPayment(User $user, Payment $payment): void
    {
        // Validation checks
        if (! $user || ! $payment) {
            Log::error('CashService::increaseFromPayment called with null user or payment', [
                'user_id' => $user?->id,
                'payment_id' => $payment?->id,
            ]);

            return;
        }

        if ($payment->payment_method !== \SmartTill\Core\Enums\PaymentMethod::Cash) {
            return;
        }

        if (! $payment->store_id) {
            Log::error('CashService::increaseFromPayment called with payment missing store_id', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $amount = $payment->amount ?? 0;
        if ($amount <= 0) {
            Log::warning('CashService::increaseFromPayment called with invalid amount', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($user, $payment, $amount) {
                $userStoreCashService = app(UserStoreCashService::class);

                // Get current cash in hand for this store
                $currentBalance = $userStoreCashService->getCashInHandForStore($user, $payment->store_id);
                $newBalance = $currentBalance + $amount;

                // Check if cash transaction already exists to prevent duplicates
                $existingTransaction = CashTransaction::where('referenceable_type', Payment::class)
                    ->where('referenceable_id', $payment->id)
                    ->where('type', CashTransactionType::PaymentReceived->value)
                    ->first();

                if ($existingTransaction) {
                    Log::warning('CashService::increaseFromPayment - Cash transaction already exists for payment', [
                        'user_id' => $user->id,
                        'payment_id' => $payment->id,
                        'existing_transaction_id' => $existingTransaction->id,
                    ]);

                    return;
                }

                // Update user's cash in hand for this store
                $userStoreCashService->incrementCashInHandForStore($user, $payment->store_id, $amount);

                // Create cash transaction record
                CashTransaction::create([
                    'user_id' => $user->id,
                    'store_id' => $payment->store_id,
                    'type' => CashTransactionType::PaymentReceived->value,
                    'amount' => $amount,
                    'cash_balance' => $newBalance,
                    'referenceable_type' => Payment::class,
                    'referenceable_id' => $payment->id,
                    'note' => $payment->reference ? "Payment received - Reference: {$payment->reference}" : 'Customer payment received',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to increase cash from payment', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'payment_amount' => $amount,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Decrease cash in hand when a sale is refunded or cancelled
     */
    public function decreaseFromSaleRefund(User $user, Sale $sale): void
    {
        // Validation checks
        if (! $user || ! $sale) {
            Log::error('CashService::decreaseFromSaleRefund called with null user or sale', [
                'user_id' => $user?->id,
                'sale_id' => $sale?->id,
            ]);

            return;
        }

        if ($sale->payment_status !== \SmartTill\Core\Enums\SalePaymentStatus::Paid &&
            $sale->payment_status !== \SmartTill\Core\Enums\SalePaymentStatus::Refunded) {
            return;
        }

        if (! $sale->store_id) {
            Log::error('CashService::decreaseFromSaleRefund called with sale missing store_id', [
                'user_id' => $user->id,
                'sale_id' => $sale->id,
            ]);

            return;
        }

        $amount = abs($sale->total ?? 0);
        if ($amount <= 0) {
            Log::warning('CashService::decreaseFromSaleRefund called with invalid amount', [
                'user_id' => $user->id,
                'sale_id' => $sale->id,
                'amount' => $amount,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($user, $sale, $amount) {
                $userStoreCashService = app(UserStoreCashService::class);

                // Get current cash in hand for this store
                $currentBalance = $userStoreCashService->getCashInHandForStore($user, $sale->store_id);
                $newBalance = max(0, $currentBalance - $amount); // Ensure balance doesn't go negative

                // Determine transaction type
                $transactionType = $sale->status === \SmartTill\Core\Enums\SaleStatus::Cancelled
                    ? CashTransactionType::SaleCancelled->value
                    : CashTransactionType::SaleRefunded->value;

                // Check if cash transaction already exists to prevent duplicates
                $existingTransaction = CashTransaction::where('referenceable_type', Sale::class)
                    ->where('referenceable_id', $sale->id)
                    ->where('type', $transactionType)
                    ->first();

                if ($existingTransaction) {
                    Log::warning('CashService::decreaseFromSaleRefund - Cash transaction already exists for sale', [
                        'user_id' => $user->id,
                        'sale_id' => $sale->id,
                        'transaction_type' => $transactionType,
                        'existing_transaction_id' => $existingTransaction->id,
                    ]);

                    return;
                }

                // Calculate how much to actually decrement (can't go below 0)
                $decrementAmount = min($amount, $currentBalance);

                // Update user's cash in hand for this store
                if ($decrementAmount > 0) {
                    $userStoreCashService->decrementCashInHandForStore($user, $sale->store_id, $decrementAmount);
                }

                // Create cash transaction record
                CashTransaction::create([
                    'user_id' => $user->id,
                    'store_id' => $sale->store_id,
                    'type' => $transactionType,
                    'amount' => -$amount,
                    'cash_balance' => $newBalance,
                    'referenceable_type' => Sale::class,
                    'referenceable_id' => $sale->id,
                    'note' => $sale->status === \SmartTill\Core\Enums\SaleStatus::Cancelled
                        ? "Sale #{$sale->reference} - Cancelled"
                        : "Sale #{$sale->reference} - Refunded",
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to decrease cash from sale refund', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'sale_id' => $sale->id,
                'sale_total' => $amount,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Collect cash from a user (admin function - resets cash_in_hand to 0)
     */
    public function collectCash(User $user, User $collectedBy, ?string $note = null, ?Store $store = null): CashTransaction
    {
        // Validation checks
        if (! $user || ! $collectedBy) {
            $error = 'CashService::collectCash called with null user or collectedBy';
            Log::error($error, [
                'user_id' => $user?->id,
                'collected_by_id' => $collectedBy?->id,
            ]);
            throw new \Exception($error);
        }

        try {
            return DB::transaction(function () use ($user, $collectedBy, $note, $store) {
                $userStoreCashService = app(UserStoreCashService::class);

                // Get store from parameter, Filament context, or user's first store
                $targetStore = $store ?? \Filament\Facades\Filament::getTenant() ?? $user->stores()->first();

                if (! $targetStore) {
                    $error = "No store found for cash collection from user {$user->id}";
                    Log::error($error);
                    throw new \Exception('No store found for cash collection');
                }

                // Get current cash in hand for this store
                $currentBalance = $userStoreCashService->getCashInHandForStore($user, $targetStore);

                if ($currentBalance <= 0) {
                    $error = "User {$user->id} has no cash to collect for store {$targetStore->id} (balance: {$currentBalance})";
                    Log::warning($error);
                    throw new \Exception('User has no cash to collect');
                }

                // Reset user's cash in hand to 0 for this store
                $userStoreCashService->updateCashInHandForStore($user, $targetStore, 0);

                // Create cash transaction record
                $cashTransaction = CashTransaction::create([
                    'user_id' => $user->id,
                    'store_id' => $targetStore->id,
                    'type' => CashTransactionType::CashCollected->value,
                    'amount' => -$currentBalance,
                    'cash_balance' => 0,
                    'note' => $note ?? "Cash collected by {$collectedBy->name}",
                    'collected_by' => $collectedBy->id,
                ]);

                return $cashTransaction;
            });
        } catch (\Exception $e) {
            Log::error('Failed to collect cash', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'collected_by_id' => $collectedBy->id,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
