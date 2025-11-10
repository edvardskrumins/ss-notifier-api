<?php

use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('{category}/subcategories', [CategoryController::class, 'subcategories']);
    Route::get('{category}/ads', [CategoryController::class, 'ads']);
});
