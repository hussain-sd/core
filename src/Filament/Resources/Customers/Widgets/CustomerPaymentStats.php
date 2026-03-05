<?php

namespace SmartTill\Core\Filament\Resources\Customers\Widgets;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Filament\Concerns\FormatsCurrency;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Transaction;

class CustomerPaymentStats extends StatsOverviewWidget
{
    use FormatsCurrency;

    private const CUSTOMER_MORPH_TYPES = [
        Customer::class,
        'App\\Models\\Customer',
        'customer',
    ];

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Customer Payment Stats Widget');
    }

    protected function getStats(): array
    {
        $store = Filament::getTenant();

        // Get latest transactions for all customers in this store
        $latestTransactions = Transaction::query()
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::CUSTOMER_MORPH_TYPES)
            ->whereIn('id', function ($query) use ($store) {
                $query->selectRaw('MAX(id)')
                    ->from('transactions')
                    ->where('store_id', $store->id)
                    ->whereIn('transactionable_type', self::CUSTOMER_MORPH_TYPES)
                    ->groupBy('transactionable_id');
            })
            ->get();

        // Total pending amount (amount_balance > 0 means customer owes us)
        // Use getRawOriginal to get the raw database value (not the cast value)
        $pendingAmountRaw = $latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') > 0)
            ->sum(fn ($transaction) => $transaction->getRawOriginal('amount_balance'));
        $pendingAmount = $this->convertFromStorage($pendingAmountRaw, $store);

        $customersToBeReceived = $latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') > 0)
            ->count();

        // Total customers with transactions in this store
        $totalCustomers = Customer::query()
            ->where('store_id', $store->id)
            ->whereHas('transactions')
            ->count();

        // Generate optimized charts for last 7 days using database aggregation
        $start7 = Carbon::now()->subDays(6)->startOfDay();
        $days = collect(range(0, 6))->map(fn ($i) => Carbon::now()->subDays(6 - $i)->toDateString());

        // Get daily pending amounts (optimized)
        $dailyPendingAmounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::CUSTOMER_MORPH_TYPES)
            ->where('amount_balance', '>', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, SUM(amount_balance) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $pendingAmountChart = $days->map(function ($date) use ($dailyPendingAmounts, $store) {
            $totalRaw = (float) ($dailyPendingAmounts[$date] ?? 0);

            return (float) $this->convertFromStorage($totalRaw, $store);
        })->toArray();

        // Get daily customer counts (optimized)
        $dailyCustomerCounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::CUSTOMER_MORPH_TYPES)
            ->where('amount_balance', '>', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT transactionable_id) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $customersToBeReceivedChart = $days->map(function ($date) use ($dailyCustomerCounts) {
            return (int) ($dailyCustomerCounts[$date] ?? 0);
        })->toArray();

        return [
            Stat::make('Pending Amount', $this->formatCompactCurrency($pendingAmount, $store))
                ->description('Total amount to be received')
                ->color('warning')
                ->chart($pendingAmountChart),
            Stat::make('Customers to be Received', number_format($customersToBeReceived))
                ->description('Customers with pending balances')
                ->color('info')
                ->chart($customersToBeReceivedChart),
            Stat::make('Customers with Transactions', number_format($totalCustomers))
                ->description('Customers with any transaction')
                ->color('success'),
        ];
    }

    public static function getPendingAmountStatForPeriod($startDate, $endDate): array
    {
        $store = Filament::getTenant();

        // Get latest transactions for all customers in this store within the period
        $latestTransactions = Transaction::query()
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::CUSTOMER_MORPH_TYPES)
            ->whereIn('id', function ($query) use ($store, $startDate, $endDate) {
                $query->selectRaw('MAX(id)')
                    ->from('transactions')
                    ->where('store_id', $store->id)
                    ->whereIn('transactionable_type', self::CUSTOMER_MORPH_TYPES)
                    ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                    ->groupBy('transactionable_id');
            })
            ->get();

        // Only sum positive balances (customers who owe us)
        $pendingAmountRaw = $latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') > 0)
            ->sum(fn ($transaction) => $transaction->getRawOriginal('amount_balance'));
        $pendingAmount = (new static)->convertFromStorage($pendingAmountRaw, $store);

        return [
            'value' => $pendingAmount,
            'chart' => [],
        ];
    }
}
