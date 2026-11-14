<?php

use App\Http\Controllers\Api\AdminMenuController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminStaffController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\StaffMenuController;
use App\Http\Controllers\Api\StaffOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Authentication Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    // Menu Catalog Routes
    Route::prefix('menu')->group(function () {
        Route::get('/categories', [MenuController::class, 'categories']);
        Route::get('/items', [MenuController::class, 'items']);
    });

    // Orders & Checkout Routes
    Route::prefix('orders')->group(function () {
        Route::post('/checkout', [OrderController::class, 'checkout']);
        Route::get('/{id}', [OrderController::class, 'show']);
    });

    // Staff Queue & Kitchen Fulfillment (Phase 4 - T022, T023)
    Route::prefix('staff')->middleware(['auth:sanctum', 'role:staff,admin'])->group(function () {
        Route::get('/orders', [StaffOrderController::class, 'index']);
        Route::patch('/orders/{id}/status', [StaffOrderController::class, 'updateStatus']);
        Route::post('/orders/{id}/verify-payment', [StaffOrderController::class, 'verifyPayment']);
        Route::post('/orders/{id}/cancel', [StaffOrderController::class, 'cancel']);
        Route::patch('/menu/{id}/toggle-sold-out', [StaffMenuController::class, 'toggleSoldOut']);
    });

    // Admin Management & Reports (Phase 5 - T027, T028, T029)
    Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/menu', [AdminMenuController::class, 'index']);
        Route::post('/menu', [AdminMenuController::class, 'store']);
        Route::put('/menu/{id}', [AdminMenuController::class, 'update']);
        Route::patch('/menu/{id}', [AdminMenuController::class, 'update']);
        Route::delete('/menu/{id}', [AdminMenuController::class, 'destroy']);

        Route::get('/staff', [AdminStaffController::class, 'index']);
        Route::post('/staff', [AdminStaffController::class, 'store']);
        Route::patch('/staff/{id}/toggle-active', [AdminStaffController::class, 'toggleActive']);

        Route::get('/reports/sales', [AdminReportController::class, 'sales']);
        Route::get('/audit-logs', [AdminReportController::class, 'auditLogs']);
    });
});
