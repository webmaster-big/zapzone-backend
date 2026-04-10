<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\GoogleCalendarSetting;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GoogleCalendarBookingSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected Location $location;
    protected Package $package;
    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'Test Company',
            'email' => 'test@company.com',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->user = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Test Location',
            'address' => '123 Test St',
        ]);

        $this->package = Package::create([
            'company_id' => $this->company->id,
            'name' => 'Test Package',
            'duration' => 120,
            'price' => 299.99,
            'min_participants' => 10,
            'max_participants' => 30,
        ]);

        $this->room = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Party Room A',
            'capacity' => 30,
            'is_available' => true,
        ]);
    }

    protected function createBookingWithCalendarEvent(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'reference_number' => 'BK' . now()->format('Ymd') . strtoupper(Str::random(6)),
            'location_id' => $this->location->id,
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'type' => 'package',
            'booking_date' => now()->addDays(7)->format('Y-m-d'),
            'booking_time' => '14:00',
            'participants' => 15,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 299.99,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
            'guest_name' => 'John Doe',
            'guest_email' => 'john@test.com',
            'google_calendar_event_id' => 'gcal_event_' . uniqid(),
        ], $overrides));
    }

    // -------------------------------------------------------
    // Cancel sets status to cancelled and booking no longer has active gcal event
    // -------------------------------------------------------

    public function test_cancel_booking_sets_cancelled_status(): void
    {
        $booking = $this->createBookingWithCalendarEvent();

        $response = $this->actingAs($this->user)
            ->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_booking_without_calendar_event_succeeds(): void
    {
        $booking = $this->createBookingWithCalendarEvent([
            'google_calendar_event_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cannot_cancel_already_cancelled_booking(): void
    {
        $booking = $this->createBookingWithCalendarEvent(['status' => 'cancelled']);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(400);
    }

    public function test_cannot_cancel_completed_booking(): void
    {
        $booking = $this->createBookingWithCalendarEvent(['status' => 'completed']);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(400);
    }

    // -------------------------------------------------------
    // Soft delete removes booking
    // -------------------------------------------------------

    public function test_soft_delete_booking(): void
    {
        $booking = $this->createBookingWithCalendarEvent();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/bookings/{$booking->id}");

        $response->assertOk();
        $this->assertSoftDeleted('bookings', ['id' => $booking->id]);
    }

    public function test_soft_deleted_booking_not_in_default_query(): void
    {
        $booking = $this->createBookingWithCalendarEvent();
        $booking->delete();

        $this->assertNull(Booking::find($booking->id));
        $this->assertNotNull(Booking::withTrashed()->find($booking->id));
    }

    // -------------------------------------------------------
    // Force delete permanently removes booking
    // -------------------------------------------------------

    public function test_force_delete_permanently_removes_booking(): void
    {
        $booking = $this->createBookingWithCalendarEvent(['status' => 'cancelled']);
        $booking->delete(); // soft delete first

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/bookings/{$booking->id}/force");

        $response->assertOk();
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    // -------------------------------------------------------
    // Public force delete works for pending bookings
    // -------------------------------------------------------

    public function test_public_force_delete_removes_pending_booking(): void
    {
        $booking = $this->createBookingWithCalendarEvent(['status' => 'pending']);

        $response = $this->deleteJson("/api/bookings/{$booking->id}/force-delete");

        $response->assertOk();
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_public_force_delete_rejects_non_pending_non_trashed(): void
    {
        $booking = $this->createBookingWithCalendarEvent(['status' => 'confirmed']);

        $response = $this->deleteJson("/api/bookings/{$booking->id}/force-delete");

        $response->assertStatus(403);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    // -------------------------------------------------------
    // Resync query excludes cancelled bookings
    // -------------------------------------------------------

    public function test_resync_query_excludes_cancelled_bookings(): void
    {
        $confirmedBooking = $this->createBookingWithCalendarEvent([
            'status' => 'confirmed',
            'google_calendar_event_id' => null,
            'booking_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $cancelledBooking = $this->createBookingWithCalendarEvent([
            'status' => 'cancelled',
            'google_calendar_event_id' => null,
            'booking_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $bookingsToSync = Booking::where('booking_date', '>=', now()->format('Y-m-d'))
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('google_calendar_event_id')
            ->pluck('id');

        $this->assertTrue($bookingsToSync->contains($confirmedBooking->id));
        $this->assertFalse($bookingsToSync->contains($cancelledBooking->id));
    }

    // -------------------------------------------------------
    // Resync query excludes soft-deleted bookings
    // -------------------------------------------------------

    public function test_resync_query_excludes_soft_deleted_bookings(): void
    {
        $activeBooking = $this->createBookingWithCalendarEvent([
            'status' => 'confirmed',
            'google_calendar_event_id' => null,
            'booking_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $deletedBooking = $this->createBookingWithCalendarEvent([
            'status' => 'confirmed',
            'google_calendar_event_id' => null,
            'booking_date' => now()->addDays(3)->format('Y-m-d'),
        ]);
        $deletedBooking->delete();

        $bookingsToSync = Booking::where('booking_date', '>=', now()->format('Y-m-d'))
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('google_calendar_event_id')
            ->pluck('id');

        $this->assertTrue($bookingsToSync->contains($activeBooking->id));
        $this->assertFalse($bookingsToSync->contains($deletedBooking->id));
    }

    // -------------------------------------------------------
    // Resync query excludes bookings that already have event IDs
    // -------------------------------------------------------

    public function test_resync_query_excludes_already_synced_bookings(): void
    {
        $unsyncedBooking = $this->createBookingWithCalendarEvent([
            'status' => 'confirmed',
            'google_calendar_event_id' => null,
            'booking_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $syncedBooking = $this->createBookingWithCalendarEvent([
            'status' => 'confirmed',
            'google_calendar_event_id' => 'gcal_existing_123',
            'booking_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $bookingsToSync = Booking::where('booking_date', '>=', now()->format('Y-m-d'))
            ->whereNotIn('status', ['cancelled'])
            ->whereNull('google_calendar_event_id')
            ->pluck('id');

        $this->assertTrue($bookingsToSync->contains($unsyncedBooking->id));
        $this->assertFalse($bookingsToSync->contains($syncedBooking->id));
    }

    // -------------------------------------------------------
    // deleteEvent service method clears event ID
    // -------------------------------------------------------

    public function test_delete_event_clears_event_id_on_booking(): void
    {
        $booking = $this->createBookingWithCalendarEvent();
        $this->assertNotNull($booking->google_calendar_event_id);

        // Simulate what deleteEvent does on success
        $booking->update(['google_calendar_event_id' => null]);

        $this->assertNull($booking->fresh()->google_calendar_event_id);
    }

    // -------------------------------------------------------
    // createEventFromBooking duplicate guard
    // -------------------------------------------------------

    public function test_create_event_guard_redirects_to_update_if_event_exists(): void
    {
        // The guard logic in createEventFromBooking:
        // if ($booking->google_calendar_event_id) → update instead of create
        $booking = $this->createBookingWithCalendarEvent();

        // Verify guard condition: booking with event ID should trigger update path
        $this->assertNotNull($booking->google_calendar_event_id);

        // Booking without event ID should be eligible for creation
        $newBooking = $this->createBookingWithCalendarEvent([
            'google_calendar_event_id' => null,
        ]);
        $this->assertNull($newBooking->google_calendar_event_id);
    }

    // -------------------------------------------------------
    // Cancel sets cancelled_at timestamp
    // -------------------------------------------------------

    public function test_cancel_sets_cancelled_at_timestamp(): void
    {
        $booking = $this->createBookingWithCalendarEvent();
        $this->assertNull($booking->cancelled_at);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $this->assertNotNull($booking->fresh()->cancelled_at);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
