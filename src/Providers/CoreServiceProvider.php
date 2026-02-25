<?php

namespace SmartTill\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SmartTill\Core\Console\Commands\CoreInstallCommand;
use SmartTill\Core\Console\Commands\NativeCoreInstallCommand;
use SmartTill\Core\Models\Attribute;
use SmartTill\Core\Models\Payment;
use SmartTill\Core\Models\Product;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\Transaction;
use SmartTill\Core\Models\Unit;
use SmartTill\Core\Models\Variation;
use SmartTill\Core\Observers\AttributeObserver;
use SmartTill\Core\Observers\PaymentObserver;
use SmartTill\Core\Observers\ProductObserver;
use SmartTill\Core\Observers\SaleObserver;
use SmartTill\Core\Observers\StockObserver;
use SmartTill\Core\Observers\TransactionObserver;
use SmartTill\Core\Observers\UnitObserver;
use SmartTill\Core\Observers\VariationObserver;
use SmartTill\Core\Services\CoreAccessBootstrapService;
use SmartTill\Core\Services\CoreGeoBootstrapService;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CoreAccessBootstrapService::class, CoreAccessBootstrapService::class);
        $this->app->singleton(CoreGeoBootstrapService::class, CoreGeoBootstrapService::class);

        $this->commands([
            CoreInstallCommand::class,
            NativeCoreInstallCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'smart-core');
        Livewire::component('product-search', \SmartTill\Core\Livewire\ProductSearch::class);

        Attribute::observe(AttributeObserver::class);
        Unit::observe(UnitObserver::class);
        Product::observe(ProductObserver::class);
        Variation::observe(VariationObserver::class);
        Stock::observe(StockObserver::class);
        Sale::observe(SaleObserver::class);
        Payment::observe(PaymentObserver::class);
        Transaction::observe(TransactionObserver::class);

        if (class_exists(\App\Models\Store::class) && ! class_exists(\App\Observers\StoreObserver::class)) {
            \App\Models\Store::observe(\SmartTill\Core\Observers\StoreObserver::class);
        }
    }
}
