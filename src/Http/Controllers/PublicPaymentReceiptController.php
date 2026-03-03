<?php

namespace SmartTill\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use SmartTill\Core\Models\Payment;

class PublicPaymentReceiptController
{
    public function __invoke(Request $request, Payment $payment): View
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

        $payment->loadMissing([
            'payable',
            'store.currency',
            'store.timezone',
        ]);

        $viewName = view()->exists('print.payment')
            ? 'print.payment'
            : 'smart-core::print.payment';

        return view($viewName, [
            'payment' => $payment,
            'next' => $next,
        ]);
    }
}
