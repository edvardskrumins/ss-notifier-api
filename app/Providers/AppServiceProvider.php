<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
            $email = $notifiable->getEmailForPasswordReset();

            return $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($email);
        });
    }
}
