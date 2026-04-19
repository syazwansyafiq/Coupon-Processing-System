<?php

use App\Http\Controllers\CouponController;
use App\Http\Controllers\GetTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/token', GetTokenController::class);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/coupons/apply', [CouponController::class, 'apply']);
    Route::get('/coupons/status/{requestId}', [CouponController::class, 'status']);
    Route::post('/coupons/consume', [CouponController::class, 'consume']);
    Route::post('/coupons/release', [CouponController::class, 'release']);
});
