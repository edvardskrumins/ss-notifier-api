<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmailTest;

Route::get('/', function () {
    return view('welcome');
});


// Route::get('/send', action:function() {
//     Mail::to(users:'edvardskrumins@gmail.com')->send(new \App\Mail\EmailTest());
// });


Route::get('/send', function () {
    Mail::to('edvardskrumins@gmail.com')->send(new EmailTest());
    return 'Email sent';
});