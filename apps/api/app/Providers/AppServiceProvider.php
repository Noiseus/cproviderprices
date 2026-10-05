<?php

namespace App\Providers;

use App\Events\ImportPriceObservationsCompleted;
use App\Listeners\RefreshImportPriceState;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(EntitlementService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $trustedProxies = config('security.trusted_proxies');
        if (is_array($trustedProxies) && $trustedProxies !== []) {
            TrustProxies::at($trustedProxies);
        }
        $trustedHosts = config('security.trusted_hosts');
        if (is_array($trustedHosts) && $trustedHosts !== []) {
            TrustHosts::at($trustedHosts);
        }

        Event::listen(ImportPriceObservationsCompleted::class, RefreshImportPriceState::class);
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            $this->emailAndIp($request)
        ));
        RateLimiter::for('passwords', fn (Request $request): Limit => Limit::perMinute(5)->by($this->emailAndIp($request)));
        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(10)->by(
            hash('sha256', (string) ($request->route('token') ?? $request->input('token') ?? '')).'|'.$request->ip()
        ));
        RateLimiter::for('verification', fn (Request $request): Limit => Limit::perMinute(6)->by(
            (string) $request->user()->getAuthIdentifier()
        ));
        RateLimiter::for('team-invitations', fn (Request $request): Limit => Limit::perMinute(10)->by(
            $this->userAndScope($request)
        ));
        RateLimiter::for('platform-mutations', fn (Request $request): Limit => Limit::perMinute(20)->by(
            $this->userAndScope($request)
        ));
        RateLimiter::for('import-writes', fn (Request $request): Limit => Limit::perMinute(10)->by(
            $this->userAndScope($request)
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

    private function emailAndIp(Request $request): string
    {
        return hash('sha256', Str::lower(trim((string) $request->input('email')))).'|'.$request->ip();
    }

    private function userAndScope(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier() ?? 'guest';
        $organization = $request->route('organization');
        $scope = is_object($organization) && method_exists($organization, 'getKey')
            ? (string) $organization->getKey()
            : (string) ($organization ?? 'global');

        return $userId.'|'.$scope.'|'.$request->ip();
    }
}
