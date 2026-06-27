<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

// Pricing is public so the landing/pricing page can list plans without auth.
Route::get('/billing/plans', [BillingController::class, 'plans']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/email/resend', [AuthController::class, 'resendVerificationEmail']);

    // Account settings (no org membership required)
    Route::put('/profile', [\App\Http\Controllers\SettingsController::class, 'updateProfile']);
    Route::put('/password', [\App\Http\Controllers\SettingsController::class, 'changePassword']);
    Route::get('/notification-preferences', [\App\Http\Controllers\SettingsController::class, 'getNotificationPreferences']);
    Route::put('/notification-preferences', [\App\Http\Controllers\SettingsController::class, 'updateNotificationPreferences']);

    Route::middleware('org.member')->group(function () {
        // Organization settings
        Route::put('/organization', [\App\Http\Controllers\SettingsController::class, 'updateOrganization']);

        // Organization members & roles
        Route::get('/organization/members', [\App\Http\Controllers\OrganizationController::class, 'members']);
        Route::put('/organization/members/{userId}', [\App\Http\Controllers\OrganizationController::class, 'updateMemberRole']);

        // OAuth
        Route::get('/oauth/{platform}/redirect', [OAuthController::class, 'redirect']);

    // Stores
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/stores/connect-options', [StoreController::class, 'connectOptions']);
    Route::post('/stores/connect', [StoreController::class, 'connect']);
    Route::post('/stores/sync-all', [StoreController::class, 'syncAll']);
    Route::post('/stores/{id}/set-master', [StoreController::class, 'setMaster']);
    Route::post('/stores/{id}/sync', [StoreController::class, 'sync']);
    Route::delete('/stores/{id}', [StoreController::class, 'destroy']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/export', [OrderController::class, 'export']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}', [OrderController::class, 'update']);

    // Customers
    Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'index']);
    Route::get('/customers/{email}', [\App\Http\Controllers\CustomerController::class, 'show']);

    // Products
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::post('/products/sync', [ProductController::class, 'sync']);
    Route::post('/products/upload', [ProductController::class, 'uploadImage']);
    Route::post('/platform-products/{id}/toggle-sync', [ProductController::class, 'togglePlatformSync']);

    // Inventory
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
    Route::get('/inventory/logs', [InventoryController::class, 'logs']);

    // Analytics
    Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
    Route::get('/analytics/orders-timeline', [AnalyticsController::class, 'ordersTimeline']);
    Route::get('/analytics/top-customers', [AnalyticsController::class, 'topCustomers']);
    Route::get('/analytics/by-platform', [AnalyticsController::class, 'byPlatform']);
    Route::get('/analytics/top-products', [AnalyticsController::class, 'topProducts']);

    // Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        // Billing (plans are public — see above)
        Route::get('/billing/status', [BillingController::class, 'status']);
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    });
});

Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])->name('verification.verify');
Route::get('/oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');

// edfapay posts payment results here (server-to-server, signature-verified).
Route::post('/billing/callback', [BillingController::class, 'callback']);

Route::post('/webhooks/{platform}', [WebhookController::class, 'handle'])
    ->middleware(\App\Http\Middleware\VerifyWebhookSignature::class);
