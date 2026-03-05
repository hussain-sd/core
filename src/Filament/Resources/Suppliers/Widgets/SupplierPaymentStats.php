<?php

namespace SmartTill\Core\Filament\Resources\Suppliers\Widgets;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Filament\Concerns\FormatsCurrency;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Supplier;
use SmartTill\Core\Models\Transaction;

class SupplierPaymentStats extends StatsOverviewWidget
{
    use FormatsCurrency;

    private const SUPPLIER_MORPH_TYPES = [
        Supplier::class,
        'App\\Models\\Supplier',
        'supplier',
    ];

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Supplier Payment Stats Widget');
    }

    protected function getStats(): array
    {
        $store = Filament::getTenant();

        // Get latest transactions for all suppliers in this store
        $latestTransactions = Transaction::query()
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::SUPPLIER_MORPH_TYPES)
            ->whereIn('id', function ($query) use ($store) {
                $query->selectRaw('MAX(id)')
                    ->from('transactions')
                    ->where('store_id', $store->id)
                    ->whereIn('transactionable_type', self::SUPPLIER_MORPH_TYPES)
                    ->groupBy('transactionable_id');
            })
            ->get();

        // Total pending amount (amount_balance < 0 means we owe supplier, convert to positive)
        // Use getRawOriginal to get the raw database value (not the cast value)
        $pendingAmountRaw = abs($latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') < 0)
            ->sum(fn ($transaction) => $transaction->getRawOriginal('amount_balance')));
        $pendingAmount = $this->convertFromStorage($pendingAmountRaw, $store);

        $suppliersToBePaid = $latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') < 0)
            ->count();

        // Total suppliers with transactions in this store
        $totalSuppliers = Supplier::query()
            ->where('store_id', $store->id)
            ->whereHas('transactions')
            ->count();

        // Generate optimized charts for last 7 days using database aggregation
        $start7 = Carbon::now()->subDays(6)->startOfDay();
        $days = collect(range(0, 6))->map(fn ($i) => Carbon::now()->subDays(6 - $i)->toDateString());

        // Get daily pending amounts (optimized)
        $dailyPendingAmounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::SUPPLIER_MORPH_TYPES)
            ->where('amount_balance', '<', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, ABS(SUM(amount_balance)) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $pendingAmountChart = $days->map(function ($date) use ($dailyPendingAmounts, $store) {
            $totalRaw = (float) ($dailyPendingAmounts[$date] ?? 0);

            return (float) $this->convertFromStorage($totalRaw, $store);
        })->toArray();

        // Get daily supplier counts (optimized)
        $dailySupplierCounts = DB::table('transactions')
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::SUPPLIER_MORPH_TYPES)
            ->where('amount_balance', '<', 0)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT transactionable_id) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $suppliersToBePaidChart = $days->map(function ($date) use ($dailySupplierCounts) {
            return (int) ($dailySupplierCounts[$date] ?? 0);
        })->toArray();

        return [
            Stat::make('Pending Amount', $this->formatCompactCurrency($pendingAmount, $store))
                ->description('Total amount to be paid')
                ->color('warning')
                ->chart($pendingAmountChart),
            Stat::make('Suppliers to be Paid', number_format($suppliersToBePaid))
                ->description('Suppliers with pending balances')
                ->color('info')
                ->chart($suppliersToBePaidChart),
            Stat::make('Suppliers with Transactions', number_format($totalSuppliers))
                ->description('Suppliers with any transaction')
                ->color('success'),
        ];
    }

    public static function getPendingAmountStatForPeriod(Carbon $startDate, Carbon $endDate): array
    {
        $store = Filament::getTenant();

        $latestTransactions = Transaction::query()
            ->where('store_id', $store->id)
            ->whereIn('transactionable_type', self::SUPPLIER_MORPH_TYPES)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->whereIn('id', function ($query) use ($store, $startDate, $endDate) {
                $query->selectRaw('MAX(id)')
                    ->from('transactions')
                    ->where('store_id', $store->id)
                    ->whereIn('transactionable_type', self::SUPPLIER_MORPH_TYPES)
                    ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                    ->groupBy('transactionable_id');
            })
            ->get();

        $pendingAmountRaw = abs($latestTransactions
            ->filter(fn ($transaction) => $transaction->getRawOriginal('amount_balance') < 0)
            ->sum(fn ($transaction) => $transaction->getRawOriginal('amount_balance')));
        $pendingAmount = (new static)->convertFromStorage($pendingAmountRaw, $store);

        return [
            'value' => $pendingAmount,
            'chart' => [],
        ];
    }
}
