<?php

namespace SmartTill\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use SmartTill\Core\Models\PurchaseOrder;

class PublicPurchaseOrderReceiptController
{
    public function __invoke(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $next = $request->query('next');
        if (! $next) {
            $next = $request->hasSession()
                ? $request->session()->pull('print.next')
                : null;
        }
        if (! $next) {
            $next = url()->previous();
        }
        if (! $next) {
            $next = '/';
        }

        $purchaseOrder->loadMissing([
            'variations.product',
            'supplier',
            'store.currency',
            'store.timezone',
        ]);

        $viewName = view()->exists('print.purchase-order')
            ? 'print.purchase-order'
            : 'smart-core::print.purchase-order';

        return view($viewName, [
            'purchaseOrder' => $purchaseOrder,
            'next' => $next,
        ]);
    }
}
