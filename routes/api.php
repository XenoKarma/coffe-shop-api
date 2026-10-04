<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::apiResource('categories', CategoryController::class)
    ->only(['index', 'show']);

Route::apiResource('categories', CategoryController::class)
    ->only(['store', 'update', 'destroy'])
    ->middleware(['auth:sanctum', 'role:admin']);

Route::apiResource('products', ProductController::class)
    ->only(['index', 'show']);

Route::apiResource('products', ProductController::class)
    ->only(['store', 'update', 'destroy'])
    ->middleware(['auth:sanctum', 'role:admin']);

Route::middleware(['auth:sanctum', 'role:admin,cashier'])->group(function () {
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
