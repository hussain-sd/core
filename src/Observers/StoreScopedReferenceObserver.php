<?php

namespace SmartTill\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreScopedReferenceObserver
{
    public function creating(Model $model): void
    {
        $this->assignStoreScopedReferenceIfMissing($model);
    }

    public function created(Model $model): void
    {
        $this->assignStoreScopedReferenceIfMissing($model, true);
    }

    private function resolveStoreId(Model $model): int
    {
        $table = $model->getTable();

        if (Schema::hasColumn($table, 'store_id')) {
            return (int) ($model->getAttribute('store_id') ?? 0);
        }

        return match ($table) {
            'product_attributes' => (int) DB::table('products')
                ->where('id', (int) $model->getAttribute('product_id'))
                ->value('store_id'),
            'purchase_order_products' => (int) DB::table('purchase_orders')
                ->where('id', (int) $model->getAttribute('purchase_order_id'))
                ->value('store_id'),
            'sale_variation', 'sale_preparable_items' => (int) DB::table('sales')
                ->where('id', (int) $model->getAttribute('sale_id'))
                ->value('store_id'),
            'stocks' => (int) DB::table('variations')
                ->where('id', (int) $model->getAttribute('variation_id'))
                ->value('store_id'),
            'unit_dimensions' => (int) ($model->getAttribute('store_id') ?? 0),
            default => 0,
        };
    }

    private function assignStoreScopedReferenceIfMissing(Model $model, bool $persisted = false): void
    {
        if (config('smart_till.reference_on_create', true) !== true) {
            return;
        }

        $table = $model->getTable();
        if (! Schema::hasColumn($table, 'reference')) {
            return;
        }

        $storeId = $this->resolveStoreId($model);
        $reference = trim((string) ($model->getAttribute('reference') ?? ''));

        if ($storeId <= 0 || $reference !== '') {
            return;
        }

        $nextReference = (int) DB::table($table)
            ->where('store_id', $storeId)
            ->whereNotNull('reference')
            ->pluck('reference')
            ->map(static fn ($value): int => (int) $value)
            ->max() + 1;

        if ($persisted) {
            $keyName = $model->getKeyName();
            $key = $model->getKey();

            if ($key !== null) {
                DB::table($table)
                    ->where($keyName, $key)
                    ->whereNull('reference')
                    ->update(['reference' => (string) $nextReference]);
            }
        }

        $model->setAttribute('reference', (string) $nextReference);
    }
}
