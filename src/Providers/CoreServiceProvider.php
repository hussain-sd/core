<?php

namespace SmartTill\Core\Providers;

use App\Models\Store as AppStore;
use App\Models\User as AppUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SmartTill\Core\Console\Commands\CoreInstallCommand;
use SmartTill\Core\Console\Commands\NativeCoreInstallCommand;
use SmartTill\Core\Models\Attribute;
use SmartTill\Core\Models\Brand;
use SmartTill\Core\Models\Category;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Payment;
use SmartTill\Core\Models\Product;
use SmartTill\Core\Models\PurchaseOrder;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\StoreSetting;
use SmartTill\Core\Models\Supplier;
use SmartTill\Core\Models\Timezone;
use SmartTill\Core\Models\Transaction;
use SmartTill\Core\Models\Unit;
use SmartTill\Core\Models\Variation;
use SmartTill\Core\Observers\AttributeObserver;
use SmartTill\Core\Observers\PaymentObserver;
use SmartTill\Core\Observers\ProductObserver;
use SmartTill\Core\Observers\SaleObserver;
use SmartTill\Core\Observers\StockObserver;
use SmartTill\Core\Observers\StoreScopedReferenceObserver;
use SmartTill\Core\Observers\TransactionObserver;
use SmartTill\Core\Observers\UnitObserver;
use SmartTill\Core\Observers\VariationObserver;
use SmartTill\Core\Services\CoreAccessBootstrapService;
use SmartTill\Core\Services\CoreGeoBootstrapService;
use SmartTill\Core\Services\CoreStoreSettingsService;
use SmartTill\Core\Services\CoreUnitBootstrapService;
use SmartTill\Core\Models\Role;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CoreAccessBootstrapService::class, CoreAccessBootstrapService::class);
        $this->app->singleton(CoreGeoBootstrapService::class, CoreGeoBootstrapService::class);
        $this->app->singleton(CoreStoreSettingsService::class, CoreStoreSettingsService::class);
        $this->app->singleton(CoreUnitBootstrapService::class, CoreUnitBootstrapService::class);

        $this->commands([
            CoreInstallCommand::class,
            NativeCoreInstallCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->registerMorphMapCompatibility();
        $this->registerHostModelFallbackRelations();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->registerPackageRoutes();
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
        Attribute::observe(StoreScopedReferenceObserver::class);
        Brand::observe(StoreScopedReferenceObserver::class);
        Category::observe(StoreScopedReferenceObserver::class);
        Customer::observe(StoreScopedReferenceObserver::class);
        Product::observe(StoreScopedReferenceObserver::class);
        PurchaseOrder::observe(StoreScopedReferenceObserver::class);
        Supplier::observe(StoreScopedReferenceObserver::class);
        Unit::observe(StoreScopedReferenceObserver::class);
        Variation::observe(StoreScopedReferenceObserver::class);

        if (class_exists(\App\Models\Store::class) && ! class_exists(\App\Observers\StoreObserver::class)) {
            \App\Models\Store::observe(\SmartTill\Core\Observers\StoreObserver::class);
        }
    }

    private function registerMorphMapCompatibility(): void
    {
        Relation::morphMap([
            'App\Models\Customer' => Customer::class,
            'App\Models\Supplier' => Supplier::class,
            'App\Models\Sale' => Sale::class,
            'App\Models\PurchaseOrder' => PurchaseOrder::class,
            'App\Models\Payment' => Payment::class,
            'App\Models\Transaction' => Transaction::class,
            Customer::class => Customer::class,
            Supplier::class => Supplier::class,
            Sale::class => Sale::class,
            PurchaseOrder::class => PurchaseOrder::class,
            Payment::class => Payment::class,
            Transaction::class => Transaction::class,
        ]);
    }

    private function registerHostModelFallbackRelations(): void
    {
        $pivotColumns = $this->getStoreUserPivotColumns();

        if (class_exists(AppStore::class)) {
            if (! method_exists(AppStore::class, 'currency')) {
                AppStore::resolveRelationUsing('currency', fn (AppStore $store) => $store->belongsTo(Currency::class, 'currency_id'));
            }

            if (! method_exists(AppStore::class, 'country')) {
                AppStore::resolveRelationUsing('country', fn (AppStore $store) => $store->belongsTo(Country::class, 'country_id'));
            }

            if (! method_exists(AppStore::class, 'timezone')) {
                AppStore::resolveRelationUsing('timezone', fn (AppStore $store) => $store->belongsTo(Timezone::class, 'timezone_id'));
            }

            if (! method_exists(AppStore::class, 'settings')) {
                AppStore::resolveRelationUsing('settings', fn (AppStore $store) => $store->hasMany(StoreSetting::class, 'store_id'));
            }

            if (! method_exists(AppStore::class, 'users')) {
                AppStore::resolveRelationUsing(
                    'users',
                    fn (AppStore $store) => $store->belongsToMany(AppUser::class, 'store_user')->withPivot($pivotColumns)
                );
            }
        }

        if (class_exists(AppUser::class)) {
            if (! method_exists(AppUser::class, 'stores')) {
                AppUser::resolveRelationUsing(
                    'stores',
                    fn (AppUser $user) => $user->belongsToMany(AppStore::class, 'store_user')->withPivot($pivotColumns)
                );
            }

            if (! method_exists(AppUser::class, 'roles')) {
                AppUser::resolveRelationUsing(
                    'roles',
                    fn (AppUser $user) => $user->belongsToMany(Role::class, 'user_role')->withPivot('store_id')->withTimestamps()
                );
            }
        }
    }

    private function registerPackageRoutes(): void
    {
        if (! Route::has('public.receipt')) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        }
    }

    /**
     * @return array<int, string>
     */
    private function getStoreUserPivotColumns(): array
    {
        $pivotColumns = [];

        if (Schema::hasTable('store_user')) {
            if (Schema::hasColumn('store_user', 'cash_in_hand')) {
                $pivotColumns[] = 'cash_in_hand';
            }

            if (Schema::hasColumn('store_user', 'role_id')) {
                $pivotColumns[] = 'role_id';
            }
        }

        return $pivotColumns;
    }
}
