<?php

namespace SmartTill\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StoreScopedReferenceObserver
{
    public function creating(Model $model): void
    {
        $table = $model->getTable();
        $storeId = (int) ($model->getAttribute('store_id') ?? 0);
        $reference = trim((string) ($model->getAttribute('reference') ?? ''));

        if ($storeId <= 0 || $reference !== '') {
            return;
        }

        $nextReference = DB::table($table)
            ->where('store_id', $storeId)
            ->whereNotNull('reference')
            ->count() + 1;

        $model->setAttribute('reference', (string) $nextReference);
    }
}

