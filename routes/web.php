<?php

use Illuminate\Support\Facades\Route;
use SmartTill\Core\Http\Controllers\PublicPaymentReceiptController;
use SmartTill\Core\Http\Controllers\PublicPurchaseOrderReceiptController;
use SmartTill\Core\Http\Controllers\PublicReceiptController;

Route::get('/receipts/{store}/{reference}', PublicReceiptController::class)
    ->middleware('web')
    ->name('public.receipt');

Route::get('/purchase-orders/{purchaseOrder}/receipt', PublicPurchaseOrderReceiptController::class)
    ->middleware('web')
    ->name('print.purchase-order');

Route::get('/payments/{payment}/receipt', PublicPaymentReceiptController::class)
    ->middleware('web')
    ->name('print.payment');
