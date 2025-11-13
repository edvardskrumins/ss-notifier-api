<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Auth\VerifyEmailLinkController;


Route::get('/email/verify/{id}/{hash}', VerifyEmailLinkController::class)
    ->middleware([
        'signed',
        'throttle:'.config('fortify.limiters.verification', '6,1'),
    ])
    ->name('verification.verify');