<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\OrganizationContextController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\PlatformContextController;
use App\Http\Controllers\Api\V1\PlatformInvitationController;
use App\Http\Controllers\Api\V1\PlatformMatchingController;
use App\Http\Controllers\Api\V1\PlatformOrganizationController;
use App\Http\Controllers\Api\V1\PlatformOverviewController;
use App\Http\Controllers\Api\V1\PlatformSubscriptionController;
use App\Http\Controllers\Api\V1\PriceHistoryController;
use App\Http\Controllers\Api\V1\ProductComparisonController;
use App\Http\Controllers\Api\V1\ProductSearchController;
use App\Http\Controllers\Api\V1\RestaurantLocationController;
use App\Http\Controllers\Api\V1\SavedListController;
use App\Http\Controllers\Api\V1\SubscriptionStatusController;
use App\Http\Controllers\Api\V1\SupplierAccountController;
use App\Http\Controllers\Api\V1\TeamController;
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
            Route::patch('/auth/preferences', [SessionController::class, 'updatePreferences']);
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
                    Route::get('/imports', [ImportController::class, 'index']);
                    Route::post('/imports', [ImportController::class, 'store']);
                    Route::get('/import-mapping-templates', [ImportController::class, 'templates']);
                    Route::get('/imports/{import}', [ImportController::class, 'show']);
                    Route::put('/imports/{import}/mapping', [ImportController::class, 'mapping']);
                    Route::post('/imports/{import}/validate', [ImportController::class, 'validateImport']);
                    Route::post('/imports/{import}/start', [ImportController::class, 'start']);
                    Route::post('/imports/{import}/cancel', [ImportController::class, 'cancel']);
                    Route::post('/imports/{import}/downloads', [ImportController::class, 'downloads']);
                    Route::get('/imports/{import}/files/{file}/download', [ImportController::class, 'downloadFile'])->middleware('signed:relative')->name('imports.file');
                    Route::get('/imports/{import}/errors/download', [ImportController::class, 'downloadErrors'])->middleware('signed:relative')->name('imports.errors');
                    Route::get('/context', [OrganizationContextController::class, 'show']);
                    Route::post('/switch', [OrganizationContextController::class, 'switch']);
                    Route::get('/team', [TeamController::class, 'index']);
                    Route::post('/invitations', [TeamController::class, 'invite']);
                    Route::post('/invitations/{invitation}/resend', [TeamController::class, 'resend']);
                    Route::delete('/invitations/{invitation}', [TeamController::class, 'revoke']);
                    Route::get('/restaurant-locations', [RestaurantLocationController::class, 'index']);
                    Route::get('/restaurant-locations/{restaurantLocation}', [RestaurantLocationController::class, 'show']);
                    Route::post('/restaurant-locations', [RestaurantLocationController::class, 'store']);
                    Route::patch('/restaurant-locations/{restaurantLocation}', [RestaurantLocationController::class, 'update']);
                    Route::delete('/restaurant-locations/{restaurantLocation}', [RestaurantLocationController::class, 'destroy']);
                    Route::post('/restaurant-locations/{restaurantLocation}/select', [RestaurantLocationController::class, 'select']);
                    Route::patch('/memberships/{membership}', [OrganizationMembershipController::class, 'update']);
                    Route::delete('/memberships/{membership}', [OrganizationMembershipController::class, 'destroy']);
                    Route::get('/supplier-accounts', [SupplierAccountController::class, 'index']);
                    Route::post('/supplier-accounts', [SupplierAccountController::class, 'store']);
                    Route::patch('/supplier-accounts/{supplierAccount}', [SupplierAccountController::class, 'update']);
                    Route::get('/product-search', [ProductSearchController::class, 'index']);
                    Route::get('/comparisons/{canonicalProduct}', [ProductComparisonController::class, 'show']);
                    Route::get('/price-history/{canonicalProduct}', [PriceHistoryController::class, 'show']);
                    Route::get('/saved-lists', [SavedListController::class, 'index']);
                    Route::post('/saved-lists', [SavedListController::class, 'store']);
                    Route::get('/saved-lists/{savedList}', [SavedListController::class, 'show']);
                    Route::patch('/saved-lists/{savedList}', [SavedListController::class, 'update']);
                    Route::post('/saved-lists/{savedList}/duplicate', [SavedListController::class, 'duplicate']);
                    Route::post('/saved-lists/{savedList}/archive', [SavedListController::class, 'archive']);
                    Route::post('/saved-lists/{savedList}/restore', [SavedListController::class, 'restore']);
                    Route::delete('/saved-lists/{savedList}', [SavedListController::class, 'destroy']);
                    Route::post('/saved-lists/{savedList}/items', [SavedListController::class, 'addItem']);
                    Route::patch('/saved-lists/{savedList}/items/{savedListItem}', [SavedListController::class, 'updateItem']);
                    Route::delete('/saved-lists/{savedList}/items/{savedListItem}', [SavedListController::class, 'removeItem']);
                    Route::put('/saved-lists/{savedList}/items/{savedListItem}/selected-offer', [SavedListController::class, 'selectOffer']);
                    Route::put('/saved-lists/{savedList}/reorder', [SavedListController::class, 'reorder']);
                });
            });

            Route::get('/platform/context', [PlatformContextController::class, 'show'])
                ->middleware('platform.scope')->name('platform.context');
            Route::prefix('platform')->middleware('platform.scope')->name('platform.')->group(function (): void {
                Route::get('/overview', [PlatformOverviewController::class, 'show'])->name('overview');
                Route::get('/matching/canonical-products', [PlatformMatchingController::class, 'canonicalProducts'])->name('matching.canonical-products');
                Route::get('/matching/candidates', [PlatformMatchingController::class, 'index'])->name('matching.index');
                Route::get('/matching/candidates/{matchCandidate}', [PlatformMatchingController::class, 'show'])->name('matching.show');
                Route::post('/matching/candidates/{matchCandidate}/review', [PlatformMatchingController::class, 'review'])->name('matching.review');
                Route::post('/matching/mappings/{mapping}/reverse', [PlatformMatchingController::class, 'reverse'])->name('matching.reverse');
                Route::get('/organizations', [PlatformOrganizationController::class, 'index'])->name('organizations.index');
                Route::post('/organizations', [PlatformOrganizationController::class, 'store'])->name('organizations.store');
                Route::get('/organizations/{organization}', [PlatformOrganizationController::class, 'show'])->name('organizations.show');
                Route::patch('/organizations/{organization}/status', [PlatformOrganizationController::class, 'updateStatus'])->name('organizations.status.update');
                Route::patch('/organizations/{organization}/subscription', [PlatformSubscriptionController::class, 'update'])->name('organizations.subscription.update');
                Route::post('/organizations/{organization}/initial-owner-invitations', [PlatformInvitationController::class, 'store'])->name('organizations.invitations.store');
                Route::post('/organizations/{organization}/initial-owner-invitations/{invitation}/resend', [PlatformInvitationController::class, 'resend'])->name('organizations.invitations.resend');
            });
        });
    });
});
