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
    protected GoogleCalendarService $calendarService;

    public function __construct(GoogleCalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    /**
     * Get the current Google Calendar connection status.
     */
    public function status(): JsonResponse
    {
        $settings = GoogleCalendarSetting::getSettings();

        if (!$settings) {
            return response()->json([
                'success' => true,
                'data' => [
                    'credentials_configured' => false,
                    'is_connected' => false,
                    'google_account_email' => null,
                    'calendar_id' => null,
                    'last_synced_at' => null,
                    'sync_from_date' => null,
                    'frontend_redirect_url' => null,
                    'redirect_uri' => rtrim(config('app.url', ''), '/') . '/api/google-calendar/callback',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'credentials_configured' => !empty($settings->client_id) && !empty($settings->client_secret),
                'has_client_id' => !empty($settings->client_id),
                'is_connected' => $settings->is_connected,
                'google_account_email' => $settings->google_account_email,
                'calendar_id' => $settings->calendar_id,
                'last_synced_at' => $settings->last_synced_at?->toIso8601String(),
                'sync_from_date' => $settings->sync_from_date?->toDateString(),
                'connected_at' => $settings->metadata['connected_at'] ?? null,
                'frontend_redirect_url' => $settings->frontend_redirect_url,
                'redirect_uri' => rtrim(config('app.url', ''), '/') . '/api/google-calendar/callback',
            ],
        ]);
    }

    /**
     * Save Google OAuth credentials (client_id, client_secret) from admin UI.
     * This means NO env vars are needed on Forge — everything is in the DB.
     */
    public function saveCredentials(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|string|min:10',
            'client_secret' => 'required|string|min:5',
            'frontend_redirect_url' => 'nullable|string|url',
        ]);

        $settings = GoogleCalendarSetting::first() ?? new GoogleCalendarSetting();
        $settings->fill([
            'client_id' => $validated['client_id'],
            'client_secret' => $validated['client_secret'],
            'frontend_redirect_url' => $validated['frontend_redirect_url'] ?? $settings->frontend_redirect_url,
        ]);
        $settings->save();

        Log::info('Google Calendar credentials saved from admin UI', [
            'client_id_length' => strlen($validated['client_id']),
            'has_frontend_url' => !empty($validated['frontend_redirect_url']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Google Calendar credentials saved successfully. You can now connect a Google account.',
            'data' => [
                'credentials_configured' => true,
                'redirect_uri' => rtrim(config('app.url', ''), '/') . '/api/google-calendar/callback',
            ],
        ]);
    }

    /**
     * Update Google OAuth credentials (partial update).
     */
    public function updateCredentials(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'sometimes|string|min:10',
            'client_secret' => 'sometimes|string|min:5',
            'frontend_redirect_url' => 'nullable|string',
        ]);

        $settings = GoogleCalendarSetting::first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'No credentials found. Use POST to save credentials first.',
            ], 404);
        }

        $settings->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Credentials updated successfully',
        ]);
    }

    /**
     * Get the Google OAuth2 authorization URL.
     * Frontend redirects user to this URL.
     */
    public function getAuthUrl(): JsonResponse
    {
        try {
            $authUrl = $this->calendarService->getAuthUrl();

            return response()->json([
                'success' => true,
                'data' => [
                    'auth_url' => $authUrl,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate Google Calendar auth URL', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate authorization URL. Check Google Calendar configuration.',
            ], 500);
        }
    }

    /**
     * Handle the OAuth2 callback from Google.
     * Google redirects here after user authorizes access.
     */
    public function handleCallback(Request $request)
    {
        $code = $request->get('code');

        // Read frontend redirect URL from DB (no env needed)
        $dbSettings = GoogleCalendarSetting::getSettings();
        $frontendUrl = $dbSettings?->frontend_redirect_url ?: 'http://localhost:3000/settings/google-calendar';

        if (!$code) {
            $error = $request->get('error', 'unknown');
            Log::error('Google Calendar OAuth callback error', ['error' => $error]);
            return redirect($frontendUrl . '?error=' . urlencode($error));
        }

        try {
            $settings = $this->calendarService->handleCallback($code);

            // Log the connection (no auth on this route since Google redirects here)
            ActivityLog::log(
                action: 'Google Calendar Connected',
                category: 'update',
                description: "Google Calendar connected with account: {$settings->google_account_email}",
                userId: null,
                entityType: 'google_calendar',
                metadata: [
                    'google_account' => $settings->google_account_email,
                    'connected_at' => now()->toIso8601String(),
                ]
            );

            return redirect($frontendUrl . '?connected=true');

        } catch (\Exception $e) {
            Log::error('Google Calendar OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect($frontendUrl . '?error=' . urlencode('Authentication failed: ' . $e->getMessage()));
        }
    }

    /**
     * Disconnect Google Calendar.
     */
    public function disconnect(): JsonResponse
    {
        try {
            $settings = GoogleCalendarSetting::getSettings();
            $email = $settings?->google_account_email;

            $this->calendarService->disconnect();

            // Log the disconnection
            $currentUser = auth()->user();
            ActivityLog::log(
                action: 'Google Calendar Disconnected',
                category: 'update',
                description: "Google Calendar disconnected (was: {$email})",
                userId: auth()->id() ?? null,
                entityType: 'google_calendar',
                metadata: [
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
            Log::error('Failed to disconnect Google Calendar', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to disconnect Google Calendar',
            ], 500);
        }
    }

    /**
     * Get list of calendars available on the connected Google account.
     */
    public function getCalendars(): JsonResponse
    {
        if (!$this->calendarService->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected',
            ], 400);
        }

        try {
            $calendars = $this->calendarService->getCalendarList();

            return response()->json([
                'success' => true,
                'data' => $calendars,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch calendar list', ['error' => $e->getMessage()]);

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
            'calendar_id' => 'required|string',
        ]);

        if (!$this->calendarService->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected',
            ], 400);
        }

        $this->calendarService->setCalendarId($validated['calendar_id']);

        return response()->json([
            'success' => true,
            'message' => 'Calendar updated successfully',
            'data' => [
                'calendar_id' => $validated['calendar_id'],
            ],
        ]);
    }

    /**
     * Sync existing bookings to Google Calendar.
     * Only syncs bookings from the given date onwards that haven't been synced yet.
     */
    public function syncBookings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'required|date|date_format:Y-m-d',
        ]);

        if (!$this->calendarService->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected. Please connect first.',
            ], 400);
        }

        try {
            $fromDate = new \DateTime($validated['from_date']);
            $results = $this->calendarService->syncExistingBookings($fromDate);

            // Log the sync
            $currentUser = auth()->user();
            ActivityLog::log(
                action: 'Google Calendar Sync',
                category: 'update',
                description: "Synced bookings to Google Calendar from {$validated['from_date']}: {$results['created']} created, {$results['skipped']} skipped, {$results['failed']} failed",
                userId: auth()->id() ?? null,
                entityType: 'google_calendar',
                metadata: [
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
            Log::error('Google Calendar sync failed', ['error' => $e->getMessage()]);

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
        if (!$this->calendarService->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected',
            ], 400);
        }

        $booking = \App\Models\Booking::with(['package', 'location', 'customer', 'room', 'attractions', 'addOns'])
            ->findOrFail($bookingId);

        try {
            if ($booking->google_calendar_event_id) {
                // Update existing event
                $eventId = $this->calendarService->updateEventFromBooking($booking);
                $action = 'updated';
            } else {
                // Create new event
                $eventId = $this->calendarService->createEventFromBooking($booking);
                $action = 'created';
            }

            if ($eventId) {
                return response()->json([
                    'success' => true,
                    'message' => "Calendar event {$action} successfully",
                    'data' => [
                        'event_id' => $eventId,
                        'booking_id' => $booking->id,
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
        if (!$this->calendarService->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'Google Calendar is not connected',
            ], 400);
        }

        $booking = \App\Models\Booking::findOrFail($bookingId);

        if (!$booking->google_calendar_event_id) {
            return response()->json([
                'success' => false,
                'message' => 'This booking does not have a Google Calendar event',
            ], 400);
        }

        $deleted = $this->calendarService->deleteEvent($booking);

        return response()->json([
            'success' => $deleted,
            'message' => $deleted
                ? 'Calendar event removed successfully'
                : 'Failed to remove calendar event',
        ]);
    }
}
