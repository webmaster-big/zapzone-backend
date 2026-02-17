<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GoogleCalendarSetting;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected ?Client $client = null;
    protected ?Calendar $service = null;
    protected ?GoogleCalendarSetting $settings = null;

    /**
     * Initialize the Google Client with stored OAuth tokens.
     */
    public function __construct()
    {
        $this->settings = GoogleCalendarSetting::getSettings();
    }

    /**
     * Get the Google Client configured for OAuth2.
     * Reads client_id/secret from DB first, falls back to env/config.
     */
    public function getClient(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        // Resolve credentials: DB first, then config/env fallback
        $clientId = $this->settings?->client_id ?: config('google_calendar.client_id');
        $clientSecret = $this->settings?->client_secret ?: config('google_calendar.client_secret');

        if (!$clientId || !$clientSecret) {
            throw new \Exception('Google Calendar credentials not configured. Set them in Settings > Google Calendar.');
        }

        // Auto-detect redirect URI from the current app URL
        $redirectUri = rtrim(config('app.url', 'http://localhost:8000'), '/') . '/api/google-calendar/callback';

        $this->client = new Client();
        $this->client->setApplicationName(config('google_calendar.app_name', 'ZapZone Booking Calendar'));
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->addScope(Calendar::CALENDAR);
        $this->client->addScope(Calendar::CALENDAR_EVENTS);

        // Set stored tokens if available
        if ($this->settings && $this->settings->is_connected) {
            $token = [
                'access_token' => $this->settings->access_token,
                'refresh_token' => $this->settings->refresh_token,
                'expires_in' => $this->settings->token_expires_at
                    ? now()->diffInSeconds($this->settings->token_expires_at, false)
                    : 0,
                'created' => $this->settings->updated_at?->timestamp ?? time(),
            ];

            $this->client->setAccessToken($token);

            // Refresh if expired
            if ($this->client->isAccessTokenExpired() && $this->settings->refresh_token) {
                $this->refreshToken();
            }
        }

        return $this->client;
    }

    /**
     * Get the Calendar service instance.
     */
    public function getCalendarService(): Calendar
    {
        if ($this->service) {
            return $this->service;
        }

        $this->service = new Calendar($this->getClient());
        return $this->service;
    }

    /**
     * Generate the OAuth2 authorization URL.
     */
    public function getAuthUrl(): string
    {
        return $this->getClient()->createAuthUrl();
    }

    /**
     * Handle the OAuth2 callback and store tokens.
     */
    public function handleCallback(string $code): GoogleCalendarSetting
    {
        $client = $this->getClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            Log::error('Google Calendar OAuth error', ['error' => $token]);
            throw new \Exception('Failed to authenticate with Google: ' . ($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);

        // Get the authenticated user's email
        $oauth2 = new \Google\Service\Oauth2($client);
        // We'll use the calendar service to get the calendar info instead
        $calendarService = new Calendar($client);
        $calendarInfo = $calendarService->calendars->get('primary');
        $email = $calendarInfo->getSummary() ?: ($token['id_token'] ?? 'connected');

        // Try to get email from token
        $accountEmail = null;
        if (isset($token['id_token'])) {
            $payload = $client->verifyIdToken($token['id_token']);
            if ($payload) {
                $accountEmail = $payload['email'] ?? null;
            }
        }

        // If we still don't have an email, add the email scope temporarily
        if (!$accountEmail) {
            $accountEmail = $calendarInfo->getId() ?: 'connected@google.com';
        }

        // Store or update settings (singleton) — preserve existing client_id/secret
        $settings = GoogleCalendarSetting::first() ?? new GoogleCalendarSetting();
        $settings->fill([
            'google_account_email' => $accountEmail,
            'calendar_id' => $settings->calendar_id ?: 'primary',
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $settings->refresh_token,
            'token_expires_at' => now()->addSeconds($token['expires_in'] ?? 3600),
            'is_connected' => true,
            'metadata' => [
                'token_type' => $token['token_type'] ?? 'Bearer',
                'scope' => $token['scope'] ?? null,
                'connected_at' => now()->toIso8601String(),
            ],
        ]);
        $settings->save();

        $this->settings = $settings;

        Log::info('Google Calendar connected successfully', [
            'email' => $accountEmail,
        ]);

        return $settings;
    }

    /**
     * Refresh the access token using the stored refresh token.
     */
    protected function refreshToken(): void
    {
        if (!$this->settings || !$this->settings->refresh_token) {
            Log::warning('No refresh token available for Google Calendar');
            return;
        }

        try {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->settings->refresh_token);

            if (isset($newToken['error'])) {
                Log::error('Google Calendar token refresh failed', ['error' => $newToken]);
                $this->settings->update(['is_connected' => false]);
                return;
            }

            $this->settings->update([
                'access_token' => $newToken['access_token'],
                'token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
                'refresh_token' => $newToken['refresh_token'] ?? $this->settings->refresh_token,
            ]);

            Log::info('Google Calendar token refreshed successfully');
        } catch (\Exception $e) {
            Log::error('Google Calendar token refresh exception', ['error' => $e->getMessage()]);
            $this->settings->update(['is_connected' => false]);
        }
    }

    /**
     * Check if Google Calendar is connected and ready.
     */
    public function isConnected(): bool
    {
        return $this->settings && $this->settings->is_connected && $this->settings->refresh_token;
    }

    /**
     * Disconnect Google Calendar (revoke tokens).
     */
    public function disconnect(): void
    {
        if ($this->settings) {
            try {
                $client = $this->getClient();
                $client->revokeToken();
            } catch (\Exception $e) {
                Log::warning('Failed to revoke Google Calendar token', ['error' => $e->getMessage()]);
            }

            $this->settings->update([
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'is_connected' => false,
                'metadata' => array_merge($this->settings->metadata ?? [], [
                    'disconnected_at' => now()->toIso8601String(),
                ]),
            ]);
        }
    }

    /**
     * Create a Google Calendar event from a booking.
     */
    public function createEventFromBooking(Booking $booking): ?string
    {
        if (!$this->isConnected()) {
            Log::warning('Google Calendar not connected, skipping event creation', [
                'booking_id' => $booking->id,
            ]);
            return null;
        }

        try {
            $booking->loadMissing(['package', 'location', 'customer', 'room', 'attractions', 'addOns']);

            $event = $this->buildEventFromBooking($booking);
            $calendarId = $this->settings->calendar_id ?? 'primary';

            $createdEvent = $this->getCalendarService()->events->insert($calendarId, $event);
            $eventId = $createdEvent->getId();

            // Store the event ID on the booking
            $booking->update(['google_calendar_event_id' => $eventId]);

            Log::info('Google Calendar event created', [
                'booking_id' => $booking->id,
                'event_id' => $eventId,
                'reference' => $booking->reference_number,
            ]);

            return $eventId;

        } catch (\Exception $e) {
            Log::error('Failed to create Google Calendar event', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update Google Calendar event when a booking is updated.
     */
    public function updateEventFromBooking(Booking $booking): ?string
    {
        if (!$this->isConnected()) {
            return null;
        }

        try {
            $booking->loadMissing(['package', 'location', 'customer', 'room', 'attractions', 'addOns']);

            $calendarId = $this->settings->calendar_id ?? 'primary';

            // If booking already has an event, update it
            if ($booking->google_calendar_event_id) {
                try {
                    $event = $this->buildEventFromBooking($booking);
                    $updatedEvent = $this->getCalendarService()->events->update(
                        $calendarId,
                        $booking->google_calendar_event_id,
                        $event
                    );

                    Log::info('Google Calendar event updated', [
                        'booking_id' => $booking->id,
                        'event_id' => $updatedEvent->getId(),
                    ]);

                    return $updatedEvent->getId();
                } catch (\Google\Service\Exception $e) {
                    // Event may have been deleted externally, create a new one
                    if ($e->getCode() === 404 || $e->getCode() === 410) {
                        Log::info('Google Calendar event not found, creating new one', [
                            'booking_id' => $booking->id,
                            'old_event_id' => $booking->google_calendar_event_id,
                        ]);
                        $booking->google_calendar_event_id = null;
                        return $this->createEventFromBooking($booking);
                    }
                    throw $e;
                }
            }

            // No event exists yet, create one
            return $this->createEventFromBooking($booking);

        } catch (\Exception $e) {
            Log::error('Failed to update Google Calendar event', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Delete a Google Calendar event when a booking is cancelled.
     */
    public function deleteEvent(Booking $booking): bool
    {
        if (!$this->isConnected() || !$booking->google_calendar_event_id) {
            return false;
        }

        try {
            $calendarId = $this->settings->calendar_id ?? 'primary';
            $this->getCalendarService()->events->delete($calendarId, $booking->google_calendar_event_id);

            $booking->update(['google_calendar_event_id' => null]);

            Log::info('Google Calendar event deleted', [
                'booking_id' => $booking->id,
                'event_id' => $booking->google_calendar_event_id,
            ]);

            return true;

        } catch (\Google\Service\Exception $e) {
            // Event may already be deleted
            if ($e->getCode() === 404 || $e->getCode() === 410) {
                $booking->update(['google_calendar_event_id' => null]);
                return true;
            }

            Log::error('Failed to delete Google Calendar event', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Sync existing bookings from a given date to Google Calendar.
     * Returns counts of created, skipped, and failed events.
     */
    public function syncExistingBookings(\DateTime $fromDate): array
    {
        if (!$this->isConnected()) {
            throw new \Exception('Google Calendar is not connected');
        }

        $results = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        // Get bookings from the given date onwards that don't have a Google event yet
        // Exclude cancelled bookings
        $bookings = Booking::with(['package', 'location', 'customer', 'room', 'attractions', 'addOns'])
            ->where('booking_date', '>=', $fromDate->format('Y-m-d'))
            ->whereNull('google_calendar_event_id')
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('booking_date')
            ->orderBy('booking_time')
            ->get();

        Log::info('Starting Google Calendar sync', [
            'from_date' => $fromDate->format('Y-m-d'),
            'bookings_to_sync' => $bookings->count(),
        ]);

        foreach ($bookings as $booking) {
            try {
                $eventId = $this->createEventFromBooking($booking);

                if ($eventId) {
                    $results['created']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'booking_id' => $booking->id,
                    'reference' => $booking->reference_number,
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to sync booking to Google Calendar', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }

        // Update last synced timestamp
        $this->settings->update([
            'last_synced_at' => now(),
            'sync_from_date' => $fromDate->format('Y-m-d'),
        ]);

        Log::info('Google Calendar sync completed', $results);

        return $results;
    }

    /**
     * Build a Google Calendar Event object from a Booking.
     */
    protected function buildEventFromBooking(Booking $booking): Event
    {
        $customerName = $booking->customer_name ?? $booking->guest_name ?? 'Guest';
        $packageName = $booking->package?->name ?? 'Booking';
        $locationName = $booking->location?->name ?? 'Unknown Location';
        $roomName = $booking->room?->name ?? null;

        // Build the event title
        $title = "{$packageName} - {$customerName}";
        if ($booking->reference_number) {
            $title .= " ({$booking->reference_number})";
        }

        // Build the description
        $descriptionParts = [];
        $descriptionParts[] = "📋 Booking Reference: {$booking->reference_number}";
        $descriptionParts[] = "👤 Customer: {$customerName}";

        if ($booking->customer_email ?? $booking->guest_email) {
            $descriptionParts[] = "📧 Email: " . ($booking->customer_email ?? $booking->guest_email);
        }
        if ($booking->customer_phone ?? $booking->guest_phone) {
            $descriptionParts[] = "📞 Phone: " . ($booking->customer_phone ?? $booking->guest_phone);
        }

        $descriptionParts[] = "";
        $descriptionParts[] = "📦 Package: {$packageName}";
        $descriptionParts[] = "📍 Location: {$locationName}";

        if ($roomName) {
            $descriptionParts[] = "🚪 Room: {$roomName}";
        }

        $descriptionParts[] = "👥 Participants: {$booking->participants}";
        $descriptionParts[] = "⏱ Duration: {$booking->duration} {$booking->duration_unit}";
        $descriptionParts[] = "💰 Total: $" . number_format($booking->total_amount, 2);
        $descriptionParts[] = "💳 Paid: $" . number_format($booking->amount_paid, 2);
        $descriptionParts[] = "📊 Status: " . ucfirst($booking->status);
        $descriptionParts[] = "💵 Payment: " . ucfirst($booking->payment_status);

        // Attractions
        if ($booking->attractions && $booking->attractions->count() > 0) {
            $descriptionParts[] = "";
            $descriptionParts[] = "🎯 Attractions:";
            foreach ($booking->attractions as $attraction) {
                $qty = $attraction->pivot->quantity ?? 1;
                $descriptionParts[] = "  - {$attraction->name} (x{$qty})";
            }
        }

        // Add-ons
        if ($booking->addOns && $booking->addOns->count() > 0) {
            $descriptionParts[] = "";
            $descriptionParts[] = "➕ Add-ons:";
            foreach ($booking->addOns as $addOn) {
                $qty = $addOn->pivot->quantity ?? 1;
                $descriptionParts[] = "  - {$addOn->name} (x{$qty})";
            }
        }

        // Guest of honor
        if ($booking->guest_of_honor_name) {
            $descriptionParts[] = "";
            $descriptionParts[] = "🎂 Guest of Honor: {$booking->guest_of_honor_name}";
            if ($booking->guest_of_honor_age) {
                $descriptionParts[] = "   Age: {$booking->guest_of_honor_age}";
            }
        }

        // Notes
        if ($booking->notes) {
            $descriptionParts[] = "";
            $descriptionParts[] = "📝 Notes: {$booking->notes}";
        }
        if ($booking->special_requests) {
            $descriptionParts[] = "🌟 Special Requests: {$booking->special_requests}";
        }

        $description = implode("\n", $descriptionParts);

        // Calculate start and end times
        $startDateTime = $booking->booking_date . 'T' . $booking->booking_time;

        // Calculate end time based on duration
        $durationMinutes = $booking->duration_unit === 'hours'
            ? $booking->duration * 60
            : $booking->duration;

        $startCarbon = \Carbon\Carbon::parse($startDateTime);
        $endCarbon = $startCarbon->copy()->addMinutes($durationMinutes);

        // Build location string
        $locationParts = [$locationName];
        if ($booking->location?->address) {
            $locationParts[] = $booking->location->address;
        }
        if ($booking->location?->city) {
            $cityState = $booking->location->city;
            if ($booking->location?->state) {
                $cityState .= ', ' . $booking->location->state;
            }
            $locationParts[] = $cityState;
        }
        $eventLocation = implode(', ', $locationParts);

        // Build the event
        $event = new Event();
        $event->setSummary($title);
        $event->setDescription($description);
        $event->setLocation($eventLocation);

        $start = new EventDateTime();
        $start->setDateTime($startCarbon->toRfc3339String());
        $start->setTimeZone(config('app.timezone', 'America/Detroit'));
        $event->setStart($start);

        $end = new EventDateTime();
        $end->setDateTime($endCarbon->toRfc3339String());
        $end->setTimeZone(config('app.timezone', 'America/Detroit'));
        $event->setEnd($end);

        // Set color based on status
        $colorMap = [
            'pending' => '5',    // Banana (yellow)
            'confirmed' => '10', // Basil (green)
            'checked-in' => '7', // Peacock (blue)
            'completed' => '2',  // Sage (light green)
            'cancelled' => '11', // Tomato (red)
        ];

        if (isset($colorMap[$booking->status])) {
            $event->setColorId($colorMap[$booking->status]);
        }

        // Add reminder
        $reminders = new \Google\Service\Calendar\EventReminders();
        $reminders->setUseDefault(false);
        $override = new \Google\Service\Calendar\EventReminder();
        $override->setMethod('popup');
        $override->setMinutes(60); // 1 hour before
        $reminders->setOverrides([$override]);
        $event->setReminders($reminders);

        return $event;
    }

    /**
     * Get list of calendars for the connected account.
     */
    public function getCalendarList(): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        try {
            $calendarList = $this->getCalendarService()->calendarList->listCalendarList();
            $calendars = [];

            foreach ($calendarList->getItems() as $calendar) {
                $calendars[] = [
                    'id' => $calendar->getId(),
                    'summary' => $calendar->getSummary(),
                    'description' => $calendar->getDescription(),
                    'primary' => $calendar->getPrimary() ?? false,
                    'background_color' => $calendar->getBackgroundColor(),
                ];
            }

            return $calendars;

        } catch (\Exception $e) {
            Log::error('Failed to fetch Google Calendar list', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Update the calendar ID used for syncing events.
     */
    public function setCalendarId(string $calendarId): void
    {
        if ($this->settings) {
            $this->settings->update(['calendar_id' => $calendarId]);
        }
    }
}
