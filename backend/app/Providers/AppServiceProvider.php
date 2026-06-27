<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

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
        // Password-reset emails should link to the SPA, not an API route.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim(env('FRONTEND_URL', config('app.frontend_url', 'http://localhost:3000')), '/');

            return $frontend . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
