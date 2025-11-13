<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;

// Route::prefix('categories')->group(function () {
//     Route::get('/', [CategoryController::class, 'index']);
//     Route::get('{category}/subcategories', [CategoryController::class, 'subcategories']);
//     Route::get('{category}/ads', [CategoryController::class, 'ads']);
// });

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::prefix('categories')->group(function () {
        Route::get('{category}/subcategories', [CategoryController::class, 'subcategories']);
        Route::get('{category}/ads', [CategoryController::class, 'ads']);
    });
});

Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
});