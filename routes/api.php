<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AddOnController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AttractionController;
use App\Http\Controllers\Api\AttractionPurchaseController;
use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Api\AuthorizeNetAccountController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerNotificationController;
use App\Http\Controllers\Api\DayOffController;
use App\Http\Controllers\Api\EmailCampaignController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PackageTimeSlotController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PromoController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ShareableTokenController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\StripeController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes (no authentication required)
Route::post('login', [ApiAuthController::class, 'login']);
Route::post('customer-login', [ApiAuthController::class, 'customerLogin']);

// Public endpoint for Accept.js integration (get API Login ID only)
Route::get('authorize-net/public-key/{locationId}', [AuthorizeNetAccountController::class, 'getPublicKey']);
Route::get('authorize-net/accounts/all', [AuthorizeNetAccountController::class, 'allAccounts']);
Route::post('authorize-net/test-connection', [AuthorizeNetAccountController::class, 'testConnection'])->middleware('auth:sanctum');

// Public package and attraction browsing
Route::get('/packages/grouped-by-name', [PackageController::class, 'packagesGroupedByName']);
Route::get('/packages/{id}', [PackageController::class, 'show']);
Route::get('attractions/grouped', [AttractionController::class, 'attractionsGroupedByName']);
Route::get('attractions/popular', [AttractionController::class, 'getPopular']);
Route::get('attractions/location/{locationId}', [AttractionController::class, 'getByLocation']);
Route::get('attractions/{id}', [AttractionController::class, 'show']);
Route::get('packages/location/{locationId}', [PackageController::class, 'getByLocation']);

// Public customer search
Route::get('customers/search', [CustomerController::class, 'search']);

// Public payment processing
Route::post('payments/charge', [PaymentController::class, 'charge']);
Route::put('payments/{payment}', [PaymentController::class, 'update']);
Route::patch('payments/{payment}', [PaymentController::class, 'update']);

// Public booking creation
Route::post('bookings', [BookingController::class, 'store']);
Route::post('bookings/{booking}/qrcode', [BookingController::class, 'storeQrCode']);

// Public attraction purchase creation
Route::post('attraction-purchases', [AttractionPurchaseController::class, 'store']);
Route::post('attraction-purchases/{attractionPurchase}/qrcode', [AttractionPurchaseController::class, 'storeQrCode']);

// Locations Route
Route::get('locations', [LocationController::class, 'index']);
Route::post('locations', [LocationController::class, 'store']);

// User Registration
Route::post('users', [UserController::class, 'store']);

// Package Time Slot routes
Route::apiResource('package-time-slots', PackageTimeSlotController::class);
Route::get('package-time-slots/available-slots/{packageId}/{date}', [PackageTimeSlotController::class, 'getAvailableSlotsAuto']);

// Server-Sent Events (SSE) streams - no authentication required
Route::get('stream/bookings', [StreamController::class, 'bookingNotifications']);
Route::get('stream/attraction-purchases', [StreamController::class, 'attractionPurchaseNotifications']);
Route::get('stream/notifications', [StreamController::class, 'combinedNotifications']);

// Shareable Token public routes
Route::post('shareable-tokens/check', [ShareableTokenController::class, 'check']);
Route::post('shareable-tokens', [ShareableTokenController::class, 'store']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // User info
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Logout
    Route::post('logout', [ApiAuthController::class, 'logout']);

    // Metrics and Analytics - protected, role-based
    Route::get('metrics/dashboard/{user}', [MetricsController::class, 'dashboard']);
    Route::get('metrics/attendant', [MetricsController::class, 'attendant']);

    // Company Analytics (Admin/Owner)
    Route::get('analytics/company', [AnalyticsController::class, 'getCompanyAnalytics']);
    Route::post('analytics/company/export', [AnalyticsController::class, 'exportAnalytics']);

    // Location Manager Analytics
    Route::get('analytics/location', [AnalyticsController::class, 'getLocationAnalytics']);
    Route::post('analytics/location/export', [AnalyticsController::class, 'exportAnalytics']);

    // Company routes
    Route::apiResource('companies', CompanyController::class);
    Route::get('companies/{company}/statistics', [CompanyController::class, 'statistics']);
    Route::patch('companies/{company}/logo', [CompanyController::class, 'updateLogo']);

    // Location routes
    Route::apiResource('locations', LocationController::class)->only(['show', 'update', 'destroy']);
    Route::get('locations/company/{companyId}', [LocationController::class, 'getByCompany']);
    Route::patch('locations/{location}/toggle-status', [LocationController::class, 'toggleStatus']);
    Route::get('locations/{location}/statistics', [LocationController::class, 'statistics']);

    // User routes
    Route::apiResource('users', UserController::class)->except(['store']);
    Route::get('users/company/{companyId}', [UserController::class, 'getByCompany']);
    Route::get('users/location/{locationId}', [UserController::class, 'getByLocation']);
    Route::get('users/role/{role}', [UserController::class, 'getByRole']);
    Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::patch('users/{user}/update-last-login', [UserController::class, 'updateLastLogin']);
    Route::patch('users/{user}/update-email', [UserController::class, 'updateEmail']);
    Route::patch('users/{user}/update-password', [UserController::class, 'updatePassword']);
    Route::patch('users/{user}/update-profile-path', [UserController::class, 'updateProfilePath']);
    Route::post('users/bulk-delete', [UserController::class, 'bulkDelete']);

    // Customer routes
    Route::get('customers/bookings', [BookingController::class, 'customerBookings']);
    Route::get('customers/list/{user}', [CustomerController::class, 'fetchCustomerList']);
    Route::get('customers/analytics', [CustomerController::class, 'analytics']);
    Route::post('customers/analytics/export', [CustomerController::class, 'exportAnalytics']);
    Route::apiResource('customers', CustomerController::class);
    Route::patch('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus']);
    Route::get('customers/{customer}/statistics', [CustomerController::class, 'statistics']);
    Route::patch('customers/{customer}/update-last-visit', [CustomerController::class, 'updateLastVisit']);

    // Category routes
    Route::apiResource('categories', CategoryController::class);

    // Package routes
    Route::post('packages/room/create', [PackageController::class, 'storePackageRoom']);
    Route::apiResource('packages', PackageController::class);
    Route::post('packages/bulk-import', [PackageController::class, 'bulkImport']);
    Route::get('packages/category/{category}', [PackageController::class, 'getByCategory']);
    Route::patch('packages/{package}/toggle-status', [PackageController::class, 'toggleIsActiveStatus']);
    Route::post('packages/{package}/attractions/attach', [PackageController::class, 'attachAttractions']);
    Route::post('packages/{package}/attractions/detach', [PackageController::class, 'detachAttractions']);
    Route::post('packages/{package}/addons/attach', [PackageController::class, 'attachAddOns']);
    Route::post('packages/{package}/addons/detach', [PackageController::class, 'detachAddOns']);

    // Package Availability Schedules
    Route::get('packages/{package}/availability-schedules', [PackageController::class, 'getAvailabilitySchedules']);
    Route::post('packages/{package}/availability-schedules', [PackageController::class, 'storeAvailabilitySchedule']);
    Route::put('packages/{package}/availability-schedules', [PackageController::class, 'updateAvailabilitySchedules']);
    Route::delete('packages/{package}/availability-schedules/{scheduleId}', [PackageController::class, 'deleteAvailabilitySchedule']);

    // Attraction routes
    Route::post('attractions/bulk-import', [AttractionController::class, 'bulkImport']);
    Route::get('attractions/category/{category}', [AttractionController::class, 'getByCategory']);
    Route::apiResource('attractions', AttractionController::class)->except(['show']);
    Route::patch('attractions/{attraction}/toggle-status', [AttractionController::class, 'toggleStatus']);
    Route::patch('attractions/{attraction}/activate', [AttractionController::class, 'activate']);
    Route::patch('attractions/{attraction}/deactivate', [AttractionController::class, 'deactivate']);
    Route::get('attractions/{attraction}/statistics', [AttractionController::class, 'statistics']);
    Route::post('attractions/bulk-delete', [AttractionController::class, 'bulkDelete']);

    // Attraction Purchase routes
    Route::apiResource('attraction-purchases', AttractionPurchaseController::class)->except(['store']);
    // update status
    Route::patch('attraction-purchases/{attractionPurchase}/update-status', [AttractionPurchaseController::class, 'updateStatus']);
    Route::get('attraction-purchases/statistics', [AttractionPurchaseController::class, 'statistics']);
    Route::get('attraction-purchases/customer/{customerId}', [AttractionPurchaseController::class, 'getByCustomer']);
    Route::get('attraction-purchases/attraction/{attractionId}', [AttractionPurchaseController::class, 'getByAttraction']);
    Route::patch('attraction-purchases/{attractionPurchase}/complete', [AttractionPurchaseController::class, 'markAsCompleted']);
    Route::patch('attraction-purchases/{attractionPurchase}/cancel', [AttractionPurchaseController::class, 'cancel']);
    Route::post('attraction-purchases/{attractionPurchase}/send-receipt', [AttractionPurchaseController::class, 'sendReceipt']);
    Route::get('attraction-purchases/{id}/verify', [AttractionPurchaseController::class, 'verify']);
    Route::patch('attraction-purchases/{id}/check-in', [AttractionPurchaseController::class, 'checkIn']);
    Route::post('attraction-purchases/bulk-delete', [AttractionPurchaseController::class, 'bulkDelete']);

    // Room routes
    Route::apiResource('rooms', RoomController::class);
    Route::get('rooms/location/{locationId}', [RoomController::class, 'getByLocation']);
    Route::patch('rooms/{room}/toggle-availability', [RoomController::class, 'toggleAvailability']);
    Route::get('rooms/available', [RoomController::class, 'getAvailableRooms']);
    Route::patch('rooms/area-group/{areaGroup}/update-booking-interval', [RoomController::class, 'updateBookingIntervalByAreaGroup']);
    Route::post('rooms/bulk-delete', [RoomController::class, 'bulkDelete']);

    // Day Off routes
    Route::apiResource('day-offs', DayOffController::class);
    Route::get('day-offs/location/{locationId}', [DayOffController::class, 'getByLocation']);
    Route::post('day-offs/check-date', [DayOffController::class, 'checkDate']);
    Route::post('day-offs/bulk-delete', [DayOffController::class, 'bulkDelete']);

    // Add-on routes
    Route::apiResource('addons', AddOnController::class);
    Route::get('addons/location/{locationId}', [AddOnController::class, 'getByLocation']);
    Route::patch('addons/{addOn}/toggle-status', [AddOnController::class, 'toggleStatus']);
    Route::get('addons/popular', [AddOnController::class, 'getPopular']);
    Route::post('addons/bulk-delete', [AddOnController::class, 'bulkDelete']);

    // Gift Card routes
    Route::apiResource('gift-cards', GiftCardController::class);
    Route::post('gift-cards/validate-code', [GiftCardController::class, 'validateByCode']);
    Route::post('gift-cards/{giftCard}/redeem', [GiftCardController::class, 'redeem']);
    Route::patch('gift-cards/{giftCard}/deactivate', [GiftCardController::class, 'deactivate']);
    Route::patch('gift-cards/{giftCard}/reactivate', [GiftCardController::class, 'reactivate']);

    // Promo routes
    Route::apiResource('promos', PromoController::class);
    Route::post('promos/validate-code', [PromoController::class, 'validateByCode']);
    Route::post('promos/{promo}/apply', [PromoController::class, 'apply']);
    Route::get('promos/valid', [PromoController::class, 'getValid']);
    Route::patch('promos/{promo}/toggle-status', [PromoController::class, 'toggleStatus']);

    // Booking routes
    Route::get('bookings/export', [BookingController::class, 'exportIndex']);
    Route::apiResource('bookings', BookingController::class)->except(['store']);
    Route::patch('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::post('bookings/check-in', [BookingController::class, 'checkIn']);
    Route::patch('bookings/{booking}/complete', [BookingController::class, 'complete']);
    Route::get('bookings/location-date', [BookingController::class, 'getByLocationAndDate']);
    Route::get('bookings/search', [BookingController::class, 'search']);
    Route::patch('bookings/{booking}/status', [BookingController::class, 'updateStatus']);
    Route::patch('bookings/{booking}/payment-status', [BookingController::class, 'updatePaymentStatus']);
    Route::patch('bookings/{booking}/internal-notes', [BookingController::class, 'updateInternalNotes']);
    Route::post('bookings/bulk-delete', [BookingController::class, 'bulkDelete']);

    // Booking Summary PDF routes
    Route::get('bookings/summaries/export', [BookingController::class, 'summariesExport']);
    Route::get('bookings/summaries/day/{date}', [BookingController::class, 'summariesDay']);
    Route::get('bookings/summaries/week/{week?}', [BookingController::class, 'summariesWeek']);
    Route::get('bookings/{booking}/summary', [BookingController::class, 'summary']);
    Route::get('bookings/{booking}/summary/view', [BookingController::class, 'summaryView']);

    // Payment Invoice routes (must be before apiResource to avoid route conflicts)
    Route::get('payments/invoices/report', [PaymentController::class, 'invoicesReport']);
    Route::get('payments/invoices/export', [PaymentController::class, 'invoicesExport']);
    Route::get('payments/invoices/day/{date}', [PaymentController::class, 'invoicesDay']);
    Route::get('payments/invoices/week/{week?}', [PaymentController::class, 'invoicesWeek']);
    Route::post('payments/invoices/bulk', [PaymentController::class, 'invoicesBulk']);

    // Payment routes
    Route::apiResource('payments', PaymentController::class)->except(['update']);
    Route::patch('payments/{payment}/refund', [PaymentController::class, 'refund']);
    Route::get('payments/{payment}/invoice', [PaymentController::class, 'invoice']);
    Route::get('payments/{payment}/invoice/view', [PaymentController::class, 'invoiceView']);

    // Activity Log routes
    Route::apiResource('activity-logs', ActivityLogController::class)->only(['index', 'store', 'show']);

    // Notification routes
    Route::apiResource('notifications', NotificationController::class);
    Route::patch('notifications/{notification}/mark-as-read', [NotificationController::class, 'markAsRead']);
    Route::patch('notifications/mark-all-as-read', [NotificationController::class, 'markAllAsRead']);

    // Customer Notification routes
    Route::apiResource('customer-notifications', CustomerNotificationController::class);
    Route::patch('customer-notifications/{customerNotification}/mark-as-read', [CustomerNotificationController::class, 'markAsRead']);
    Route::patch('customer-notifications/mark-all-as-read/{customerId}', [CustomerNotificationController::class, 'markAllAsRead']);
    Route::get('customer-notifications/unread-count/{customerId}', [CustomerNotificationController::class, 'getUnreadCount']);

    // Authorize.Net Account Management (Protected routes for location managers)
    Route::prefix('authorize-net')->group(function () {
        Route::get('account', [AuthorizeNetAccountController::class, 'show']);
        Route::post('account', [AuthorizeNetAccountController::class, 'store']);
        Route::put('account', [AuthorizeNetAccountController::class, 'update']);
        Route::delete('account', [AuthorizeNetAccountController::class, 'destroy']);
    });

    // Email Template routes
    Route::prefix('email-templates')->group(function () {
        Route::get('/', [EmailTemplateController::class, 'index']);
        Route::post('/', [EmailTemplateController::class, 'store']);
        Route::get('/variables', [EmailTemplateController::class, 'getAvailableVariables']);
        Route::post('/preview', [EmailTemplateController::class, 'previewCustom']);
        Route::get('/{emailTemplate}', [EmailTemplateController::class, 'show']);
        Route::put('/{emailTemplate}', [EmailTemplateController::class, 'update']);
        Route::delete('/{emailTemplate}', [EmailTemplateController::class, 'destroy']);
        Route::post('/{emailTemplate}/duplicate', [EmailTemplateController::class, 'duplicate']);
        Route::get('/{emailTemplate}/preview', [EmailTemplateController::class, 'preview']);
        Route::patch('/{emailTemplate}/status', [EmailTemplateController::class, 'updateStatus']);
    });

    // Email Campaign routes
    Route::prefix('email-campaigns')->group(function () {
        Route::get('/', [EmailCampaignController::class, 'index']);
        Route::post('/', [EmailCampaignController::class, 'store']);
        Route::get('/statistics', [EmailCampaignController::class, 'statistics']);
        Route::post('/preview-recipients', [EmailCampaignController::class, 'previewRecipients']);
        Route::post('/send-test', [EmailCampaignController::class, 'sendTest']);
        Route::get('/{emailCampaign}', [EmailCampaignController::class, 'show']);
        Route::delete('/{emailCampaign}', [EmailCampaignController::class, 'destroy']);
        Route::post('/{emailCampaign}/cancel', [EmailCampaignController::class, 'cancel']);
        Route::post('/{emailCampaign}/resend', [EmailCampaignController::class, 'resend']);
    });
});

