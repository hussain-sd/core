<?php

use Illuminate\Support\Facades\Route;
use SmartTill\Core\Http\Controllers\PublicReceiptController;

Route::get('/receipts/{store}/{reference}', PublicReceiptController::class)
    ->middleware('web')
    ->name('public.receipt');
