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
use App\Http\Controllers\ProfitController;
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

    // Profit reporting — reads the materialized rollups; nothing recomputes on request.
    // Cost/margin data is gated by role (spec 01 §9): a viewer-level teammate can work orders
    // without seeing what the business makes. Static segments stay above any /{id} route so they
    // can't be captured as an id.
    Route::middleware('cost.access')->group(function () {
        Route::get('/analytics/profit/timeline', [ProfitController::class, 'timeline']);
        Route::get('/analytics/profit/by-sku', [ProfitController::class, 'bySku']);
        Route::get('/analytics/profit/by-channel', [ProfitController::class, 'byChannel']);
        Route::get('/analytics/profit/coverage', [ProfitController::class, 'coverage']);
        Route::get('/analytics/profit', [ProfitController::class, 'summary']);
        Route::get('/orders/{id}/profit', [ProfitController::class, 'order']);

        // Operating-cost inputs that feed the P&L: business expenses + advertising spend.
        Route::get('/expenses', [\App\Http\Controllers\ExpenseController::class, 'index']);
        Route::post('/expenses', [\App\Http\Controllers\ExpenseController::class, 'store']);
        Route::put('/expenses/{id}', [\App\Http\Controllers\ExpenseController::class, 'update']);
        Route::delete('/expenses/{id}', [\App\Http\Controllers\ExpenseController::class, 'destroy']);

        Route::get('/ad-spend', [\App\Http\Controllers\AdSpendController::class, 'index']);
        Route::post('/ad-spend', [\App\Http\Controllers\AdSpendController::class, 'store']);
        Route::post('/ad-spend/import', [\App\Http\Controllers\AdSpendController::class, 'import']);
        Route::delete('/ad-spend/{id}', [\App\Http\Controllers\AdSpendController::class, 'destroy']);
    });

    // Automation rules engine (spec 02) — org-scoped but ungated in every plan.
    Route::get('/automation/schema', [\App\Http\Controllers\AutomationController::class, 'schema']);
    Route::get('/automation/templates', [\App\Http\Controllers\AutomationController::class, 'templates']);
    Route::get('/automation/rules', [\App\Http\Controllers\AutomationController::class, 'index']);
    Route::post('/automation/rules', [\App\Http\Controllers\AutomationController::class, 'store']);
    Route::post('/automation/rules/simulate', [\App\Http\Controllers\AutomationController::class, 'simulate']);
    Route::get('/automation/runs', [\App\Http\Controllers\AutomationController::class, 'runs']);
    Route::get('/automation/rules/{id}', [\App\Http\Controllers\AutomationController::class, 'show']);
    Route::put('/automation/rules/{id}', [\App\Http\Controllers\AutomationController::class, 'update']);
    Route::post('/automation/rules/{id}/toggle', [\App\Http\Controllers\AutomationController::class, 'toggle']);
    Route::delete('/automation/rules/{id}', [\App\Http\Controllers\AutomationController::class, 'destroy']);

    // Returns / RMA (spec 03)
    Route::get('/return-reasons', [\App\Http\Controllers\ReturnReasonController::class, 'index']);
    Route::get('/returns', [\App\Http\Controllers\ReturnController::class, 'index']);
    Route::post('/returns', [\App\Http\Controllers\ReturnController::class, 'store']);
    // Static segment before /{id} so it isn't captured as an id.
    Route::get('/returns/analytics', [\App\Http\Controllers\ReturnController::class, 'analytics']);
    Route::get('/returns/{id}', [\App\Http\Controllers\ReturnController::class, 'show']);
    Route::post('/returns/{id}/refund', [\App\Http\Controllers\ReturnController::class, 'refund']);
    Route::post('/returns/{id}/approve', [\App\Http\Controllers\ReturnController::class, 'approve']);
    Route::post('/returns/{id}/reject', [\App\Http\Controllers\ReturnController::class, 'reject']);
    Route::post('/returns/{id}/ship', [\App\Http\Controllers\ReturnController::class, 'ship']);
    Route::post('/returns/{id}/receive', [\App\Http\Controllers\ReturnController::class, 'receive']);
    Route::post('/returns/{id}/inspect', [\App\Http\Controllers\ReturnController::class, 'inspect']);

    // Shipping (spec 04) — carrier accounts + shipment lifecycle over the shipment engine.
    Route::get('/shipping/carriers', [\App\Http\Controllers\CarrierController::class, 'catalog']);
    Route::get('/shipping/accounts', [\App\Http\Controllers\CarrierAccountController::class, 'index']);
    Route::post('/shipping/accounts', [\App\Http\Controllers\CarrierAccountController::class, 'store']);
    Route::put('/shipping/accounts/{id}', [\App\Http\Controllers\CarrierAccountController::class, 'update']);
    Route::delete('/shipping/accounts/{id}', [\App\Http\Controllers\CarrierAccountController::class, 'destroy']);
    Route::post('/shipping/accounts/{id}/validate', [\App\Http\Controllers\CarrierAccountController::class, 'validateCredentials']);

    Route::get('/shipments', [\App\Http\Controllers\ShipmentController::class, 'index']);
    Route::post('/shipments', [\App\Http\Controllers\ShipmentController::class, 'store']);
    Route::get('/shipments/{id}', [\App\Http\Controllers\ShipmentController::class, 'show']);
    Route::delete('/shipments/{id}', [\App\Http\Controllers\ShipmentController::class, 'destroy']);
    Route::post('/shipments/{id}/rates', [\App\Http\Controllers\ShipmentController::class, 'rates']);
    Route::post('/shipments/{id}/label', [\App\Http\Controllers\ShipmentController::class, 'purchaseLabel']);
    Route::get('/shipments/{id}/label', [\App\Http\Controllers\ShipmentController::class, 'downloadLabel']);
    Route::get('/shipments/{id}/packing-slip', [\App\Http\Controllers\ShipmentController::class, 'packingSlip']);
    Route::post('/shipments/{id}/cancel', [\App\Http\Controllers\ShipmentController::class, 'cancel']);
    Route::get('/shipments/{id}/tracking', [\App\Http\Controllers\ShipmentController::class, 'tracking']);
    Route::post('/shipments/{id}/tracking-events', [\App\Http\Controllers\ShipmentController::class, 'addManualEvent']);
    Route::post('/orders/{id}/shipments', [\App\Http\Controllers\ShipmentController::class, 'storeForOrder']);
    Route::post('/addresses/validate', [\App\Http\Controllers\AddressController::class, 'validateAddress']);

    // Manifests + pickups (spec 04 §4.10)
    Route::get('/manifests', [\App\Http\Controllers\ManifestController::class, 'index']);
    Route::post('/manifests', [\App\Http\Controllers\ManifestController::class, 'store']);
    Route::get('/manifests/{id}', [\App\Http\Controllers\ManifestController::class, 'show']);
    Route::get('/manifests/{id}/document', [\App\Http\Controllers\ManifestController::class, 'document']);
    Route::post('/shipments/packing-slips/batch', [\App\Http\Controllers\ShipmentBatchController::class, 'packingSlips']);
    Route::get('/pickups', [\App\Http\Controllers\PickupController::class, 'index']);
    Route::post('/pickups', [\App\Http\Controllers\PickupController::class, 'store']);
    Route::delete('/pickups/{id}', [\App\Http\Controllers\PickupController::class, 'destroy']);

    // COD reconciliation (spec 06)
    Route::get('/cod/summary', [\App\Http\Controllers\CodController::class, 'summary']);
    Route::get('/cod/transactions', [\App\Http\Controllers\CodController::class, 'index']);
    Route::post('/cod/transactions/{id}/collected', [\App\Http\Controllers\CodController::class, 'markCollected']);
    Route::post('/cod/transactions/{id}/remitted', [\App\Http\Controllers\CodController::class, 'markRemitted']);

    // Warehouse scanning (spec 08 slice 1: setup + barcode resolution + lookup scan).
    Route::get('/warehouses', [\App\Http\Controllers\WarehouseController::class, 'index']);
    Route::post('/warehouses', [\App\Http\Controllers\WarehouseController::class, 'storeWarehouse']);
    Route::get('/warehouses/{id}/locations', [\App\Http\Controllers\WarehouseController::class, 'locations']);
    Route::post('/warehouses/{id}/locations', [\App\Http\Controllers\WarehouseController::class, 'storeLocation']);
    Route::get('/barcodes', [\App\Http\Controllers\WarehouseController::class, 'barcodes']);
    Route::post('/barcodes', [\App\Http\Controllers\WarehouseController::class, 'storeBarcode']);
    Route::delete('/barcodes/{id}', [\App\Http\Controllers\WarehouseController::class, 'destroyBarcode']);
    Route::post('/scan', [\App\Http\Controllers\WarehouseController::class, 'scan']);
    // Receiving (spec 08 §4.3) — stock moves on complete, never per scan.
    Route::get('/receipts', [\App\Http\Controllers\ReceiptController::class, 'index']);
    Route::post('/receipts', [\App\Http\Controllers\ReceiptController::class, 'store']);
    Route::get('/receipts/{id}', [\App\Http\Controllers\ReceiptController::class, 'show']);
    Route::post('/receipts/{id}/scan', [\App\Http\Controllers\ReceiptController::class, 'scan']);
    Route::post('/receipts/{id}/complete', [\App\Http\Controllers\ReceiptController::class, 'complete']);
    Route::post('/receipts/{id}/cancel', [\App\Http\Controllers\ReceiptController::class, 'cancel']);

    // Pick + pack (spec 08 §4.1/§4.2). Picking never moves stock; packing verifies by barcode.
    Route::get('/pick-lists', [\App\Http\Controllers\PickPackController::class, 'index']);
    Route::post('/pick-lists', [\App\Http\Controllers\PickPackController::class, 'store']);
    Route::get('/pick-lists/{id}', [\App\Http\Controllers\PickPackController::class, 'show']);
    Route::post('/pick-lists/{id}/start', [\App\Http\Controllers\PickPackController::class, 'start']);
    Route::post('/pick-lists/{id}/pick', [\App\Http\Controllers\PickPackController::class, 'pick']);
    Route::post('/pick-lists/{id}/items/{itemId}/short', [\App\Http\Controllers\PickPackController::class, 'short']);
    Route::post('/pick-lists/{id}/complete', [\App\Http\Controllers\PickPackController::class, 'complete']);
    Route::post('/pick-lists/{id}/cancel', [\App\Http\Controllers\PickPackController::class, 'cancel']);

    Route::post('/pack-sessions', [\App\Http\Controllers\PickPackController::class, 'openPack']);
    Route::get('/pack-sessions/{id}', [\App\Http\Controllers\PickPackController::class, 'showPack']);
    Route::post('/pack-sessions/{id}/scan', [\App\Http\Controllers\PickPackController::class, 'packScan']);
    Route::post('/pack-sessions/{id}/complete', [\App\Http\Controllers\PickPackController::class, 'completePack']);
    Route::post('/pack-sessions/{id}/void', [\App\Http\Controllers\PickPackController::class, 'voidPack']);
    Route::get('/scan-events', [\App\Http\Controllers\WarehouseController::class, 'scanEvents']);

    // Invoicing / VAT (spec 05 Milestone 0 — proper VAT documents, not yet ZATCA-cleared).
    // Note there is no delete route for an issued invoice: cancellation is a credit note only.
    Route::get('/tax-registration', [\App\Http\Controllers\InvoiceController::class, 'taxRegistration']);
    Route::put('/tax-registration', [\App\Http\Controllers\InvoiceController::class, 'saveTaxRegistration']);
    Route::get('/invoices', [\App\Http\Controllers\InvoiceController::class, 'index']);
    Route::post('/invoices', [\App\Http\Controllers\InvoiceController::class, 'store']);
    Route::get('/invoices/{id}', [\App\Http\Controllers\InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/issue', [\App\Http\Controllers\InvoiceController::class, 'issue']);
    Route::post('/invoices/{id}/credit-note', [\App\Http\Controllers\InvoiceController::class, 'creditNote']);
    Route::delete('/invoices/{id}', [\App\Http\Controllers\InvoiceController::class, 'destroy']);

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
