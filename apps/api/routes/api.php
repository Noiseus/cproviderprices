<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OrganizationContextController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\PlatformContextController;
use App\Http\Controllers\Api\V1\PlatformSubscriptionController;
use App\Http\Controllers\Api\V1\RestaurantLocationController;
use App\Http\Controllers\Api\V1\SubscriptionStatusController;
use App\Models\Organization;
use Illuminate\Support\Facades\Route;

Route::model('organization', Organization::class);

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [HealthController::class, 'health']);
    Route::get('/readiness', [HealthController::class, 'readiness']);

    Route::middleware('web')->group(function (): void {
        Route::get('/auth/csrf-cookie', [SessionController::class, 'csrf'])->name('auth.csrf');
        Route::post('/auth/login', [SessionController::class, 'store'])->middleware('throttle:login');
        Route::post('/auth/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:passwords');
        Route::post('/auth/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:passwords');
        Route::get('/auth/invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:invitations');
        Route::post('/auth/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:invitations');

        Route::middleware(['auth:web', 'active.user'])->group(function (): void {
            Route::get('/auth/session', [SessionController::class, 'show']);
            Route::delete('/auth/session', [SessionController::class, 'destroy']);
            Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'send'])
                ->middleware('throttle:verification');
            Route::get('/auth/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
                ->middleware(['signed:relative', 'throttle:verification'])
                ->name('verification.verify');

            Route::prefix('organizations/{organization}')->scopeBindings()->group(function (): void {
                Route::get('/status', [OrganizationContextController::class, 'status'])->middleware('tenant:true');
                Route::get('/subscription', [SubscriptionStatusController::class, 'show'])->middleware('tenant:true');
                Route::middleware('tenant')->group(function (): void {
                    Route::get('/context', [OrganizationContextController::class, 'show']);
                    Route::post('/switch', [OrganizationContextController::class, 'switch']);
                    Route::get('/restaurant-locations/{restaurantLocation}', [RestaurantLocationController::class, 'show']);
                    Route::post('/restaurant-locations', [RestaurantLocationController::class, 'store']);
                    Route::patch('/restaurant-locations/{restaurantLocation}', [RestaurantLocationController::class, 'update']);
                    Route::delete('/restaurant-locations/{restaurantLocation}', [RestaurantLocationController::class, 'destroy']);
                    Route::patch('/memberships/{membership}', [OrganizationMembershipController::class, 'update']);
                });
            });

            Route::get('/platform/context', [PlatformContextController::class, 'show'])
                ->middleware('platform.scope')->name('platform.context');
            Route::patch('/platform/organizations/{organization}/subscription', [PlatformSubscriptionController::class, 'update'])
                ->middleware('platform.scope')->name('platform.organizations.subscription.update');
        });
    });
});
