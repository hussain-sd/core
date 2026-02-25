<?php

namespace SmartTill\Core\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserStoreCashService
{
    public function getCashInHandForStore(User $user, Store|int $store): float
    {
        $storeInstance = $this->resolveStore($store);
        if (! $storeInstance) {
            return 0.0;
        }

        $rawAmount = DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('store_id', $storeInstance->id)
            ->value('cash_in_hand');

        if (! is_numeric($rawAmount)) {
            return 0.0;
        }

        return (float) $rawAmount / $this->getCurrencyMultiplier($storeInstance);
    }

    public function updateCashInHandForStore(User $user, Store|int $store, float $amount): void
    {
        $storeInstance = $this->resolveStore($store);
        if (! $storeInstance) {
            return;
        }

        DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('store_id', $storeInstance->id)
            ->update([
                'cash_in_hand' => (int) round($amount * $this->getCurrencyMultiplier($storeInstance)),
            ]);
    }

    public function incrementCashInHandForStore(User $user, Store|int $store, float $amount): void
    {
        $storeInstance = $this->resolveStore($store);
        if (! $storeInstance) {
            return;
        }

        DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('store_id', $storeInstance->id)
            ->increment('cash_in_hand', (int) round($amount * $this->getCurrencyMultiplier($storeInstance)));
    }

    public function decrementCashInHandForStore(User $user, Store|int $store, float $amount): void
    {
        $storeInstance = $this->resolveStore($store);
        if (! $storeInstance) {
            return;
        }

        DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('store_id', $storeInstance->id)
            ->decrement('cash_in_hand', (int) round($amount * $this->getCurrencyMultiplier($storeInstance)));
    }

    private function resolveStore(Store|int $store): ?Store
    {
        if ($store instanceof Store) {
            return $store;
        }

        return Store::query()->find($store);
    }

    private function getCurrencyMultiplier(Store $store): int
    {
        $decimalPlaces = $store->currency?->decimal_places ?? 2;

        return (int) pow(10, $decimalPlaces);
    }
}

