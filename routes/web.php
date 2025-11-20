<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ResetPasswordLinkController;
use App\Http\Controllers\Auth\VerifyEmailLinkController;


Route::get('/email/verify/{id}/{hash}', VerifyEmailLinkController::class)
    ->middleware([
        'signed',
        'throttle:'.config('fortify.limiters.verification', '6,1'),
    ])
    ->name('verification.verify');

Route::get('/reset-password/{token}', ResetPasswordLinkController::class)
    ->middleware('guest')
    ->name('password.reset');

Route::get('/', function () {
    return 'API is running';
});