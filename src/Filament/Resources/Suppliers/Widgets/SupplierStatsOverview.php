<?php

namespace SmartTill\Core\Filament\Resources\Suppliers\Widgets;

use Carbon\Carbon;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Enums\PurchaseOrderStatus;
use SmartTill\Core\Filament\Concerns\FormatsCurrency;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Supplier;

class SupplierStatsOverview extends StatsOverviewWidget
{
    use FormatsCurrency;

    private const SUPPLIER_MORPH_TYPES = [
        Supplier::class,
        'App\\Models\\Supplier',
        'supplier',
    ];

    public ?Supplier $record = null;

    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Supplier Stats Overview Widget');
    }

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $store = $this->record->store;

        // Total Purchase Orders Amount (from closed/completed purchase orders)
        $totalPurchaseOrdersAmountRaw = $this->record->purchaseOrders()
            ->where('status', PurchaseOrderStatus::Closed)
            ->sum('total_requested_supplier_price');
        $totalPurchaseOrdersAmount = $this->convertFromStorage($totalPurchaseOrdersAmountRaw, $store);

        // Total Number of Purchase Orders
        $totalPurchaseOrders = $this->record->purchaseOrders()
            ->where('status', PurchaseOrderStatus::Closed)
            ->count();

        // Pending Balance from latest transaction (negative means we owe the supplier)
        $latestTransaction = $this->record->transactions()
            ->whereIn('transactionable_type', self::SUPPLIER_MORPH_TYPES)
            ->latest()
            ->first();

        // Only show pending amount when we owe the supplier (negative balance)
        // Use getRawOriginal to get the raw database value (not the cast value)
        $pendingBalanceRaw = ($latestTransaction && $latestTransaction->getRawOriginal('amount_balance') < 0)
            ? abs($latestTransaction->getRawOriginal('amount_balance'))
            : 0;
        $pendingBalance = $this->convertFromStorage($pendingBalanceRaw, $store);

        // Purchase orders chart (last 7 days) - optimized with single database query
        $start7 = Carbon::now()->subDays(6)->startOfDay();
        $days = collect(range(0, 6))->map(fn ($i) => Carbon::now()->subDays(6 - $i)->toDateString());

        // Get daily purchase order totals using database aggregation (optimized)
        $dailyPurchaseOrders = DB::table('purchase_orders')
            ->where('supplier_id', $this->record->id)
            ->where('status', PurchaseOrderStatus::Closed->value)
            ->whereDate('created_at', '>=', $start7)
            ->selectRaw('DATE(created_at) as date, SUM(total_requested_supplier_price) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $purchaseOrdersChart = $days->map(function ($date) use ($dailyPurchaseOrders, $store) {
            $totalRaw = (float) ($dailyPurchaseOrders[$date] ?? 0);

            return (float) $this->convertFromStorage($totalRaw, $store);
        })->toArray();

        return [
            Stat::make('Total Purchases', $this->formatCompactCurrency($totalPurchaseOrdersAmount, $store))
                ->description('Closed purchase orders')
                ->descriptionIcon(Heroicon::OutlinedCurrencyDollar)
                ->color('success')
                ->chart($purchaseOrdersChart),

            Stat::make('Total Orders', number_format($totalPurchaseOrders))
                ->description('Closed purchase orders')
                ->descriptionIcon(Heroicon::OutlinedShoppingBag)
                ->color('primary'),

            Stat::make('Pending Balance', $this->formatCompactCurrency($pendingBalance, $store))
                ->description('Amount to be paid')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($pendingBalance > 0 ? 'warning' : 'success'),
        ];
    }
}
