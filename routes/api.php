<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\UserController;

// Route::prefix('categories')->group(function () {
//     Route::get('/', [CategoryController::class, 'index']);
//     Route::get('{category}/subcategories', [CategoryController::class, 'subcategories']);
//     Route::get('{category}/ads', [CategoryController::class, 'ads']);
// });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [UserController::class, 'show']);
    Route::post('/user/password', [UserController::class, 'updatePassword']);
    Route::post('/user/email/verification-notification', [UserController::class, 'sendVerificationNotification'])
        ->middleware('throttle:' . config('fortify.limiters.verification', '6,1'));
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::prefix('categories')->group(function () {
        Route::get('{category}/subcategories', [CategoryController::class, 'subcategories']);
        Route::get('{category}/ads', [CategoryController::class, 'ads']);
        Route::post('{category}/start-notifications', [CategoryController::class, 'startNotifications']);
    });
});

Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
});