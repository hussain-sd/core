<?php

namespace SmartTill\Core\Filament\Resources\Customers\Widgets;

use Carbon\Carbon;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Enums\SaleStatus;
use SmartTill\Core\Filament\Concerns\FormatsCurrency;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Transaction;

class CustomerStatsOverview extends StatsOverviewWidget
{
    use FormatsCurrency;

    private const CUSTOMER_MORPH_TYPES = [
        Customer::class,
        'App\\Models\\Customer',
        'customer',
    ];

    public ?Customer $record = null;

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Customer Stats Overview Widget');
    }

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $store = $this->record->store;

        // Total Sales Amount
        $totalSalesRaw = $this->record->sales()
            ->where('status', SaleStatus::Completed)
            ->sum('total');
        $totalSalesAmount = $this->convertFromStorage($totalSalesRaw, $store);

        // Total Number of Purchases
        $totalPurchases = $this->record->sales()
            ->where('status', SaleStatus::Completed)
            ->count();

        // Pending Balance from latest transaction (only show positive balance - customer owes us)
        $latestTransaction = Transaction::query()
            ->where('store_id', $this->record->store_id)
            ->where('transactionable_id', $this->record->id)
            ->whereIn('transactionable_type', self::CUSTOMER_MORPH_TYPES)
            ->latest()
            ->first();

        // Use getRawOriginal to get the raw database value (not the cast value)
        $pendingBalanceRaw = ($latestTransaction && $latestTransaction->getRawOriginal('amount_balance') > 0)
            ? $latestTransaction->getRawOriginal('amount_balance')
            : 0;
        $pendingBalance = $this->convertFromStorage($pendingBalanceRaw, $store);

        // Sales chart (last 7 days) - optimized with single database query
        $start7 = Carbon::now()->subDays(6)->startOfDay();
        $days = collect(range(0, 6))->map(fn ($i) => Carbon::now()->subDays(6 - $i)->toDateString());

        // Get daily sales totals using database aggregation (optimized)
        $dailySales = DB::table('sales')
            ->where('customer_id', $this->record->id)
            ->where('status', SaleStatus::Completed->value)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, SUM(total) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $salesChart = $days->map(function ($date) use ($dailySales, $store) {
            $totalRaw = (float) ($dailySales[$date] ?? 0);

            return (float) $this->convertFromStorage($totalRaw, $store);
        })->toArray();

        return [
            Stat::make('Total Sales', $this->formatCompactCurrency($totalSalesAmount, $store))
                ->description('Completed sales')
                ->descriptionIcon(Heroicon::OutlinedCurrencyDollar)
                ->color('success')
                ->chart($salesChart),

            Stat::make('Total Purchases', number_format($totalPurchases))
                ->description('Completed orders')
                ->descriptionIcon(Heroicon::OutlinedShoppingBag)
                ->color('primary'),

            Stat::make('Pending Balance', $this->formatCompactCurrency($pendingBalance, $store))
                ->description('Amount to be received')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($pendingBalance > 0 ? 'warning' : 'success'),
        ];
    }
}
