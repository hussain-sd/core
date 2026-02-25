<?php

namespace SmartTill\Core\Observers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use SmartTill\Core\Services\CoreAccessBootstrapService;
use SmartTill\Core\Services\CoreStoreSettingsService;

class StoreObserver
{
    public function created(Store $store): void
    {
        if (method_exists($store, 'initializeDefaultSettings')) {
            $store->initializeDefaultSettings();
        } else {
            app(CoreStoreSettingsService::class)->initializeDefaultSettings($store);
        }

        $bootstrapService = app(CoreAccessBootstrapService::class);
        $bootstrapService->ensureCoreAccess();

        $authUser = Auth::user();
        if ($authUser instanceof User) {
            $bootstrapService->assignStoreSuperAdmin($authUser, $store);
        }
    }
}
