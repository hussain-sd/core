<?php

namespace SmartTill\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait HasStoreScopedReference
{
    public static function bootHasStoreScopedReference(): void
    {
        static::creating(function (Model $model): void {
            self::assignStoreScopedReferenceIfMissing($model);
        });

        static::created(function (Model $model): void {
            self::assignStoreScopedReferenceIfMissing($model, true);
        });
    }

    private static function assignStoreScopedReferenceIfMissing(Model $model, bool $persisted = false): void
    {
        if (config('smart_till.reference_on_create', true) !== true) {
            return;
        }

        $reference = trim((string) ($model->getAttribute('reference') ?? ''));
        if ($reference !== '') {
            return;
        }

        $table = $model->getTable();
        if (! Schema::hasColumn($table, 'reference') || ! Schema::hasColumn($table, 'store_id')) {
            return;
        }

        $storeId = (int) ($model->getAttribute('store_id') ?? 0);
        if ($storeId <= 0) {
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
