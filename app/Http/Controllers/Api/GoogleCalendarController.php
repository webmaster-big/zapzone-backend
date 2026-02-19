<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\GoogleCalendarSetting;
use App\Services\GoogleCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleCalendarController extends Controller
{
    /**
     * Resolve the GoogleCalendarService for a specific location.
     */
    protected function resolveService(?int $locationId): GoogleCalendarService
    {
        return new GoogleCalendarService($locationId);
    }

    /**
     * Check if the app's Google Calendar credentials are configured in config.
     */
    protected function credentialsConfigured(): bool
    {
        return !empty(config('google_calendar.client_id')) && !empty(config('google_calendar.client_secret'));
    }

    /**
     * Get the current Google Calendar connection status for a location.
     */
    public function status(Request $request): JsonResponse
    {
        $locationId = $request->query('location_id') ? (int) $request->query('location_id') : null;
        $settings = GoogleCalendarSetting::getSettings($locationId);
        $credentialsConfigured = $this->credentialsConfigured();

        if (!$settings) {
            return response()->json([
                'success' => true,
                'data' => [
                    'location_id' => $locationId,
                    'credentials_configured' => $credentialsConfigured,
                    'is_connected' => false,
                    'google_account_email' => null,
                    'calendar_id' => null,
                    'last_synced_at' => null,
                    'sync_from_date' => null,
                    'redirect_uri' => rtrim(config('app.url', ''), '/') . '/api/google-calendar/callback',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'location_id' => $settings->location_id,
                'credentials_configured' => $credentialsConfigured,
                'is_connected' => $settings->is_connected,
                'google_account_email' => $settings->google_account_email,
                'calendar_id' => $settings->calendar_id,
                'last_synced_at' => $settings->last_synced_at?->toIso8601String(),
                'sync_from_date' => $settings->sync_from_date?->toDateString(),
                'connected_at' => $settings->metadata['connected_at'] ?? null,
                'redirect_uri' => rtrim(config('app.url', ''), '/') . '/api/google-calendar/callback',
            ],
        ]);
    }

    /**
     * Get the Google OAuth2 authorization URL.
     * Frontend redirects user to this URL to start Google login.
     */
    public function getAuthUrl(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        $locationId = (int) $request->query('location_id');

        if (!$this->credentialsConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not configured. Please contact the developer.',
            ], 400);
        }

        try {
            $service = $this->resolveService($locationId);
            $authUrl = $service->getAuthUrl();

            return response()->json([
                'success' => true,
                'data' => [
                    'auth_url' => $authUrl,
                    'location_id' => $locationId,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate Google Calendar auth URL', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate authorization URL. Check Google Calendar configuration.',
            ], 500);
        }
    }

    /**
     * Handle the OAuth2 callback from Google.
     * Google redirects here after user authorizes access.
     * The location_id is passed via the OAuth state parameter.
     */
    public function handleCallback(Request $request)
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $locationId = null;

        // Decode location_id from state parameter
        if ($state) {
            $stateData = json_decode(base64_decode($state), true);
            $locationId = $stateData['location_id'] ?? null;
        }

        // Frontend redirect URL from config
        $frontendUrl = config('google_calendar.frontend_redirect_url', 'http://localhost:3000/settings/google-calendar');

        if (!$code) {
            $error = $request->get('error', 'unknown');
            Log::error('Google Calendar OAuth callback error', [
                'error' => $error,
                'location_id' => $locationId,
            ]);
            $redirectParams = '?error=' . urlencode($error);
            if ($locationId) {
                $redirectParams .= '&location_id=' . $locationId;
            }
            return redirect($frontendUrl . $redirectParams);
        }

        try {
            $service = $this->resolveService($locationId);
            $settings = $service->handleCallback($code);

            // Log the connection
            ActivityLog::log(
                action: 'Google Calendar Connected',
                category: 'update',
                description: "Google Calendar connected with account: {$settings->google_account_email} for location ID: {$locationId}",
                userId: null,
                entityType: 'google_calendar',
                metadata: [
                    'location_id' => $locationId,
                    'google_account' => $settings->google_account_email,
                    'connected_at' => now()->toIso8601String(),
                ]
            );

            $redirectParams = '?connected=true';
            if ($locationId) {
                $redirectParams .= '&location_id=' . $locationId;
            }
            return redirect($frontendUrl . $redirectParams);

        } catch (\Exception $e) {
            Log::error('Google Calendar OAuth callback failed', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);
            $redirectParams = '?error=' . urlencode('Authentication failed: ' . $e->getMessage());
            if ($locationId) {
                $redirectParams .= '&location_id=' . $locationId;
            }
            return redirect($frontendUrl . $redirectParams);
        }
    }

    /**
     * Disconnect Google Calendar for a location.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        $locationId = (int) $request->input('location_id');

        try {
            $settings = GoogleCalendarSetting::getSettings($locationId);
            $email = $settings?->google_account_email;

            $service = $this->resolveService($locationId);
            $service->disconnect();

            // Log the disconnection
            $currentUser = auth()->user();
            ActivityLog::log(
                action: 'Google Calendar Disconnected',
                category: 'update',
                description: "Google Calendar disconnected (was: {$email}) for location ID: {$locationId}",
                userId: auth()->id() ?? null,
                entityType: 'google_calendar',
                metadata: [
                    'location_id' => $locationId,
                    'disconnected_by' => [
                        'user_id' => auth()->id(),
                        'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                        'email' => $currentUser?->email,
                    ],
                    'google_account' => $email,
                    'disconnected_at' => now()->toIso8601String(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Google Calendar disconnected successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to disconnect Google Calendar', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to disconnect Google Calendar',
            ], 500);
        }
    }

    /**
     * Get list of calendars available on the connected Google account.
     */
    public function getCalendars(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        $locationId = (int) $request->query('location_id');
        $service = $this->resolveService($locationId);

        if (!$service->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected for this location',
            ], 400);
        }

        try {
            $calendars = $service->getCalendarList();

            return response()->json([
                'success' => true,
                'data' => $calendars,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch calendar list', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch calendar list',
            ], 500);
        }
    }

    /**
     * Update which calendar to use for booking events.
     */
    public function updateCalendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
            'calendar_id' => 'required|string',
        ]);

        $locationId = (int) $validated['location_id'];
        $service = $this->resolveService($locationId);

        if (!$service->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected for this location',
            ], 400);
        }

        $service->setCalendarId($validated['calendar_id']);

        return response()->json([
            'success' => true,
            'message' => 'Calendar updated successfully',
            'data' => [
                'location_id' => $locationId,
                'calendar_id' => $validated['calendar_id'],
            ],
        ]);
    }

    /**
     * Sync existing bookings to Google Calendar for a location.
     * Only syncs bookings from the given date onwards that haven't been synced yet.
     */
    public function syncBookings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
            'from_date' => 'required|date|date_format:Y-m-d',
        ]);

        $locationId = (int) $validated['location_id'];
        $service = $this->resolveService($locationId);

        if (!$service->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected for this location. Please connect first.',
            ], 400);
        }

        try {
            $fromDate = new \DateTime($validated['from_date']);
            $results = $service->syncExistingBookings($fromDate, $locationId);

            // Log the sync
            $currentUser = auth()->user();
            ActivityLog::log(
                action: 'Google Calendar Sync',
                category: 'update',
                description: "Synced bookings to Google Calendar from {$validated['from_date']} for location ID: {$locationId}: {$results['created']} created, {$results['skipped']} skipped, {$results['failed']} failed",
                userId: auth()->id() ?? null,
                entityType: 'google_calendar',
                metadata: [
                    'location_id' => $locationId,
                    'synced_by' => [
                        'user_id' => auth()->id(),
                        'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                        'email' => $currentUser?->email,
                    ],
                    'from_date' => $validated['from_date'],
                    'results' => $results,
                    'synced_at' => now()->toIso8601String(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => "Sync completed: {$results['created']} events created, {$results['skipped']} skipped, {$results['failed']} failed",
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('Google Calendar sync failed', [
                'location_id' => $locationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually create a Google Calendar event for a specific booking.
     */
    public function syncSingleBooking(int $bookingId): JsonResponse
    {
        $booking = \App\Models\Booking::with(['package', 'location', 'customer', 'room', 'attractions', 'addOns'])
            ->findOrFail($bookingId);

        // Resolve service for the booking's location
        $service = $this->resolveService($booking->location_id);

        if (!$service->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected for this booking\'s location',
            ], 400);
        }

        try {
            if ($booking->google_calendar_event_id) {
                $eventId = $service->updateEventFromBooking($booking);
                $action = 'updated';
            } else {
                $eventId = $service->createEventFromBooking($booking);
                $action = 'created';
            }

            if ($eventId) {
                return response()->json([
                    'success' => true,
                    'message' => "Calendar event {$action} successfully",
                    'data' => [
                        'event_id' => $eventId,
                        'booking_id' => $booking->id,
                        'location_id' => $booking->location_id,
                        'reference' => $booking->reference_number,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync booking to calendar',
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to sync single booking to Google Calendar', [
                'booking_id' => $bookingId,
                'location_id' => $booking->location_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a booking's event from Google Calendar.
     */
    public function removeBookingEvent(int $bookingId): JsonResponse
    {
        $booking = \App\Models\Booking::findOrFail($bookingId);

        // Resolve service for the booking's location
        $service = $this->resolveService($booking->location_id);

        if (!$service->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected for this booking\'s location',
            ], 400);
        }

        if (!$booking->google_calendar_event_id) {
            return response()->json([
                'success' => false,
                'message' => 'This booking does not have a Google Calendar event',
            ], 400);
        }

        $deleted = $service->deleteEvent($booking);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted
                ? 'Calendar event removed successfully'
                : 'Failed to remove calendar event',
        ]);
    }
}
