<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip()
        ));
        RateLimiter::for('passwords', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('verification', fn (Request $request): Limit => Limit::perMinute(6)->by(
            (string) $request->user()->getAuthIdentifier()
        ));

        ResetPassword::createUrlUsing(function (User $notifiable, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.rawurlencode($token)
                .'&email='.rawurlencode((string) $notifiable->getEmailForPasswordReset());
        });

        VerifyEmail::createUrlUsing(function (User $notifiable): string {
            $path = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
                false,
            );

            return rtrim((string) config('app.frontend_url'), '/').'/verify-email?'.http_build_query([
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]).'&'.parse_url($path, PHP_URL_QUERY);
        });
    }
}
