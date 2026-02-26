<?php

namespace SmartTill\Core\Http\Controllers;

use App\Models\Store;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Models\Sale;

class PublicReceiptController
{
    public function __invoke(Request $request, string $store, string $reference): View
    {
        $storeModel = $this->resolveStore($store);

        $sale = Sale::query()
            ->where('store_id', $storeModel->id)
            ->where('reference', $reference)
            ->firstOrFail();

        $sale->loadMissing([
            'customer',
            'preparableItems.variation',
            'store.currency',
            'store.timezone',
            'variations.unit',
        ]);

        $autoPrint = filter_var($request->query('print'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($autoPrint === null) {
            $autoPrint = $request->hasSession()
                ? (bool) $request->session()->pull('print.mode', false)
                : false;
        }

        $next = $request->query('next');
        if (! $next) {
            $next = $request->hasSession()
                ? $request->session()->pull('print.next', '/')
                : '/';
        }

        $viewName = view()->exists('print.sale')
            ? 'print.sale'
            : 'smart-core::print.sale';

        return view($viewName, [
            'sale' => $sale,
            'next' => $next,
            'autoPrint' => $autoPrint,
            'groupedVariations' => $sale->buildReceiptLines(),
        ]);
    }

    private function resolveStore(string $store): Store
    {
        $table = (new Store())->getTable();
        $query = Store::query();
        $primaryKey = (new Store())->getKeyName();

        if (Schema::hasColumn($table, 'slug')) {
            $query->where('slug', $store)->orWhere($primaryKey, $store);
        } else {
            $query->whereKey($store);
        }

        return $query->firstOrFail();
    }
}
