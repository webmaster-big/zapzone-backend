<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AddOnController;
use App\Http\Controllers\Api\AttractionController;
use App\Http\Controllers\Api\AttractionPurchaseController;
use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PackageTimeSlotController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PromoController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ShareableTokenController;
use App\Http\Controllers\Api\StripeController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// login and logout routes
Route::post('login', [ApiAuthController::class, 'login']);
Route::post('logout', [ApiAuthController::class, 'logout'])->middleware('auth:sanctum');

// Company routes
Route::apiResource('companies', CompanyController::class);
Route::get('companies/{company}/statistics', [CompanyController::class, 'statistics']);

// Location routes
Route::apiResource('locations', LocationController::class);
Route::get('locations/company/{companyId}', [LocationController::class, 'getByCompany']);
Route::patch('locations/{location}/toggle-status', [LocationController::class, 'toggleStatus']);
Route::get('locations/{location}/statistics', [LocationController::class, 'statistics']);

// User routes
Route::apiResource('users', UserController::class);
Route::get('users/company/{companyId}', [UserController::class, 'getByCompany']);
Route::get('users/location/{locationId}', [UserController::class, 'getByLocation']);
Route::get('users/role/{role}', [UserController::class, 'getByRole']);
Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
Route::patch('users/{user}/update-last-login', [UserController::class, 'updateLastLogin']);

// Customer routes
Route::apiResource('customers', CustomerController::class);
Route::patch('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus']);
Route::get('customers/{customer}/statistics', [CustomerController::class, 'statistics']);
Route::patch('customers/{customer}/update-last-visit', [CustomerController::class, 'updateLastVisit']);
Route::get('customers/search', [CustomerController::class, 'search']);

// Package routes
Route::post('packages/bulk-import', [PackageController::class, 'bulkImport']);
Route::apiResource('packages', PackageController::class);
Route::get('packages/location/{locationId}', [PackageController::class, 'getByLocation']);
Route::get('packages/category/{category}', [PackageController::class, 'getByCategory']);
Route::patch('packages/{package}/toggle-status', [PackageController::class, 'toggleStatus']);
Route::post('packages/{package}/attractions/attach', [PackageController::class, 'attachAttractions']);
Route::post('packages/{package}/attractions/detach', [PackageController::class, 'detachAttractions']);
Route::post('packages/{package}/addons/attach', [PackageController::class, 'attachAddOns']);
Route::post('packages/{package}/addons/detach', [PackageController::class, 'detachAddOns']);

// Package Time Slot routes
Route::get('package-time-slots/available-slots/{packageId}/{roomId}/{date}', [PackageTimeSlotController::class, 'getAvailableSlots']);
Route::apiResource('package-time-slots', PackageTimeSlotController::class);

// Attraction routes
Route::post('attractions/bulk-import', [AttractionController::class, 'bulkImport']);
Route::apiResource('attractions', AttractionController::class);
Route::get('attractions/location/{locationId}', [AttractionController::class, 'getByLocation']);
Route::get('attractions/category/{category}', [AttractionController::class, 'getByCategory']);
Route::patch('attractions/{attraction}/toggle-status', [AttractionController::class, 'toggleStatus']);
Route::patch('attractions/{attraction}/activate', [AttractionController::class, 'activate']);
Route::patch('attractions/{attraction}/deactivate', [AttractionController::class, 'deactivate']);
Route::get('attractions/{attraction}/statistics', [AttractionController::class, 'statistics']);
Route::get('attractions/popular', [AttractionController::class, 'getPopular']);

// Attraction Purchase routes
Route::apiResource('attraction-purchases', AttractionPurchaseController::class);
Route::get('attraction-purchases/statistics', [AttractionPurchaseController::class, 'statistics']);
Route::get('attraction-purchases/customer/{customerId}', [AttractionPurchaseController::class, 'getByCustomer']);
Route::get('attraction-purchases/attraction/{attractionId}', [AttractionPurchaseController::class, 'getByAttraction']);
Route::patch('attraction-purchases/{attractionPurchase}/complete', [AttractionPurchaseController::class, 'markAsCompleted']);
Route::patch('attraction-purchases/{attractionPurchase}/cancel', [AttractionPurchaseController::class, 'cancel']);
Route::post('attraction-purchases/{attractionPurchase}/send-receipt', [AttractionPurchaseController::class, 'sendReceipt']);
Route::get('attraction-purchases/{id}/verify', [AttractionPurchaseController::class, 'verify']);
Route::patch('attraction-purchases/{id}/check-in', [AttractionPurchaseController::class, 'checkIn']);

// Room routes
Route::apiResource('rooms', RoomController::class);
Route::get('rooms/location/{locationId}', [RoomController::class, 'getByLocation']);
Route::patch('rooms/{room}/toggle-availability', [RoomController::class, 'toggleAvailability']);
Route::get('rooms/available', [RoomController::class, 'getAvailableRooms']);

// Add-on routes
Route::apiResource('addons', AddOnController::class);
Route::get('addons/location/{locationId}', [AddOnController::class, 'getByLocation']);
Route::patch('addons/{addOn}/toggle-status', [AddOnController::class, 'toggleStatus']);
Route::get('addons/popular', [AddOnController::class, 'getPopular']);

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
Route::apiResource('bookings', BookingController::class);
Route::post('bookings/{booking}/qrcode', [BookingController::class, 'storeQrCode']);
Route::patch('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
Route::post('bookings/check-in', [BookingController::class, 'checkIn']);
Route::patch('bookings/{booking}/complete', [BookingController::class, 'complete']);
Route::get('bookings/location-date', [BookingController::class, 'getByLocationAndDate']);
Route::get('bookings/search', [BookingController::class, 'search']);

// Payment routes
Route::apiResource('payments', PaymentController::class);
Route::patch('payments/{payment}/refund', [PaymentController::class, 'refund']);

// Stripe Payment routes
Route::post('stripe/payment-intent', [StripeController::class, 'createPaymentIntent']);
Route::post('stripe/confirm-payment', [StripeController::class, 'confirmPayment']);
Route::post('stripe/refund', [StripeController::class, 'refundPayment']);
Route::get('stripe/payment-details', [StripeController::class, 'getPaymentDetails']);
Route::post('stripe/setup-intent', [StripeController::class, 'createSetupIntent']);
Route::post('stripe/webhook', [StripeController::class, 'handleWebhook'])->withoutMiddleware(['auth:sanctum']);

// Reservation routes
Route::apiResource('reservations', ReservationController::class);

// Activity Log routes
Route::apiResource('activity-logs', ActivityLogController::class)->only(['index', 'store', 'show']);

// Notification routes
Route::apiResource('notifications', NotificationController::class);
Route::patch('notifications/{notification}/mark-as-read', [NotificationController::class, 'markAsRead']);
Route::patch('notifications/mark-all-as-read', [NotificationController::class, 'markAllAsRead']);

// Shareable Token Routes
Route::post('shareable-tokens/check', [ShareableTokenController::class, 'check']);
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('shareable-tokens', [ShareableTokenController::class, 'store']);
});
