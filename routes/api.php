<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CouponController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::delete('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/coupons/apply', [CouponController::class, 'apply']);
    Route::get('/coupons/status/{requestId}', [CouponController::class, 'status']);
    Route::post('/coupons/consume', [CouponController::class, 'consume']);
    Route::post('/coupons/release', [CouponController::class, 'release']);
});
