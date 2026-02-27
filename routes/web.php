<?php

use Illuminate\Support\Facades\Route;
use SmartTill\Core\Http\Controllers\PublicPurchaseOrderReceiptController;
use SmartTill\Core\Http\Controllers\PublicReceiptController;

Route::get('/receipts/{store}/{reference}', PublicReceiptController::class)
    ->middleware('web')
    ->name('public.receipt');

Route::get('/purchase-orders/{purchaseOrder}/receipt', PublicPurchaseOrderReceiptController::class)
    ->middleware('web')
    ->name('print.purchase-order');
