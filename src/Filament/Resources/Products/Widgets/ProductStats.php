<?php

namespace SmartTill\Core\Filament\Resources\Products\Widgets;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Models\Product;
use SmartTill\Core\Models\Variation;

class ProductStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return ResourceCanAccessHelper::check('View Product Stats Widget');
    }

    protected function getStats(): array
    {
        $store = Filament::getTenant();

        // Total Products
        $totalProducts = Product::query()
            ->where('store_id', $store->id)
            ->count();

        // Total Variations
        $totalVariations = Variation::query()
            ->where('store_id', $store->id)
            ->count();

        // Low Stock Variations (stock <= 10)
        $lowStockVariations = Variation::query()
            ->where('store_id', $store->id)
            ->whereRaw('(SELECT SUM(stocks.stock) FROM stocks WHERE stocks.variation_id = variations.id) <= ?', [10])
            ->count();

        // Products chart (last 7 days - cumulative count optimized with single query)
        $today = Carbon::today();
        $start7 = $today->copy()->subDays(6);

        // Get all products created up to each date in one query
        $productsByDate = Product::query()
            ->where('store_id', $store->id)
            ->whereDate('created_at', '<=', $today->toDateString())
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $period7 = CarbonPeriod::create($start7, $today);
        $cumulativeProducts = 0;
        $productsChart = collect($period7)->map(function ($date) use ($productsByDate, &$cumulativeProducts) {
            $dateStr = $date->toDateString();
            $cumulativeProducts += $productsByDate[$dateStr] ?? 0;

            return $cumulativeProducts;
        })->toArray();

        // Variations chart (last 7 days - cumulative count optimized with single query)
        $variationsByDate = Variation::query()
            ->where('store_id', $store->id)
            ->whereDate('created_at', '<=', $today->toDateString())
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $cumulativeVariations = 0;
        $variationsChart = collect($period7)->map(function ($date) use ($variationsByDate, &$cumulativeVariations) {
            $dateStr = $date->toDateString();
            $cumulativeVariations += $variationsByDate[$dateStr] ?? 0;

            return $cumulativeVariations;
        })->toArray();

        return [
            Stat::make('Total Products', number_format($totalProducts))
                ->description('All products in store')
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color('primary')
                ->chart($productsChart),

            Stat::make('Total Variations', number_format($totalVariations))
                ->description('All product variations')
                ->descriptionIcon(Heroicon::OutlinedCube)
                ->color('info')
                ->chart($variationsChart),

            Stat::make('Low Stock Alert', number_format($lowStockVariations))
                ->description('Variations with stock ≤ 10')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($lowStockVariations > 0 ? 'warning' : 'success'),
        ];
    }
}
