<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\PhotoSession;
use App\Models\SlideshowQueue;
use App\Models\User;
use App\Services\PhotoDeviceTokenService;
use App\Support\OperatingDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlideshowApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $staff;

    private PhotoDeviceTokenService $devices;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $company->id,
            'name' => 'ZapZone Waterford',
            'address' => '1 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $this->location->id,
        ]);

        $this->devices = app(PhotoDeviceTokenService::class);
    }

    private function setting(array $values = []): LocationPhotoSetting
    {
        $setting = LocationPhotoSetting::forLocation($this->location);

        if ($values !== []) {
            $setting->update($values);
        }

        return $setting->fresh();
    }

    private function kioskSession(): PhotoSession
    {
        $now = now();

        return PhotoSession::create([
            'company_id' => $this->location->company_id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'slideshow_opt_in' => true,
            'capture_date' => OperatingDay::calendarDateFor($this->location, $now),
            'operating_day' => OperatingDay::forLocation($this->location, $now),
        ]);
    }

    private function readyPhoto(PhotoSession $session, string $source = Photo::SOURCE_KIOSK): Photo
    {
        return Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->location->company_id,
            'location_id' => $this->location->id,
            'source' => $source,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/original.jpg',
            'delivery_path' => 'x/delivery.jpg',
            'slideshow_path' => 'x/slideshow.jpg',
            'thumbnail_path' => 'x/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
    }

    private function acceptAtKiosk(PhotoSession $session, bool $optIn = true): \Illuminate\Testing\TestResponse
    {
        $session = $session->fresh();

        return $this->withHeaders([
            'X-Photo-Device' => $this->devices->issue($this->location->id, PhotoDeviceTokenService::MODE_KIOSK)['token'],
            'X-Kiosk-Session' => $this->devices->sessionSecret($session->id, (string) $session->created_at),
        ])->postJson(
            "/api/photos/kiosk/{$this->location->id}/sessions/{$session->id}/accept",
            ['slideshow_opt_in' => $optIn]
        );
    }

    private function feedPhotoIds(): array
    {
        $response = $this->withHeaders([
            'X-Photo-Device' => $this->devices->issue($this->location->id, PhotoDeviceTokenService::MODE_SLIDESHOW)['token'],
        ])->getJson("/api/photos/slideshow/{$this->location->id}/feed");

        $response->assertOk();

        return array_column($response->json('data.photos'), 'id');
    }

    public function test_a_kiosk_photo_waits_for_approval_before_it_reaches_the_screen(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);

        $this->acceptAtKiosk($session)->assertOk();

        $photo->refresh();
        $this->assertTrue($photo->slideshow_eligible);
        $this->assertSame(Photo::APPROVAL_PENDING, $photo->slideshow_approval_status);
        $this->assertFalse($photo->showsInSlideshow());
        $this->assertNotContains($photo->id, $this->feedPhotoIds());

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/approval", ['status' => 'approved'])
            ->assertOk();

        $photo->refresh();
        $this->assertSame(Photo::APPROVAL_APPROVED, $photo->slideshow_approval_status);
        $this->assertSame($this->staff->id, $photo->slideshow_approved_by);
        $this->assertContains($photo->id, $this->feedPhotoIds());
    }

    public function test_a_rejected_photo_never_shows_even_when_approval_is_turned_off(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);
        $this->acceptAtKiosk($session)->assertOk();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/approval", ['status' => 'rejected'])
            ->assertOk();

        $this->assertNotContains($photo->id, $this->feedPhotoIds());

        $this->actingAs($this->staff, 'sanctum')
            ->putJson('/api/photo-settings', [
                'location_id' => $this->location->id,
                'slideshow_requires_approval' => false,
            ])
            ->assertOk();

        $this->assertSame(Photo::APPROVAL_REJECTED, $photo->fresh()->slideshow_approval_status);
        $this->assertNotContains($photo->id, $this->feedPhotoIds());
    }

    public function test_turning_approval_off_pushes_every_photo_straight_through(): void
    {
        $this->setting(['slideshow_requires_approval' => false]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);

        $this->acceptAtKiosk($session)->assertOk();

        $photo->refresh();
        $this->assertSame(Photo::APPROVAL_APPROVED, $photo->slideshow_approval_status);
        $this->assertNull($photo->slideshow_approved_by);
        $this->assertContains($photo->id, $this->feedPhotoIds());
    }

    public function test_turning_approval_off_releases_the_photos_already_waiting(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);
        $this->acceptAtKiosk($session)->assertOk();

        $this->assertNotContains($photo->id, $this->feedPhotoIds());

        $this->actingAs($this->staff, 'sanctum')
            ->putJson('/api/photo-settings', [
                'location_id' => $this->location->id,
                'slideshow_requires_approval' => false,
            ])
            ->assertOk();

        $photo->refresh();
        $this->assertSame(Photo::APPROVAL_APPROVED, $photo->slideshow_approval_status);
        $this->assertSame($this->staff->id, $photo->slideshow_approved_by);
        $this->assertContains($photo->id, $this->feedPhotoIds());
    }

    public function test_a_guest_who_declines_is_kept_off_the_screen_however_the_location_is_set(): void
    {
        $this->setting(['slideshow_requires_approval' => false, 'slideshow_auto_add_kiosk' => true]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);

        $this->acceptAtKiosk($session, false)->assertOk();

        $photo->refresh();
        $this->assertFalse($photo->slideshow_eligible);
        $this->assertNull($photo->slideshow_queue_id);
        $this->assertNotContains($photo->id, $this->feedPhotoIds());
    }

    public function test_bulk_approval_clears_the_waiting_list(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);

        $ids = [];
        foreach (range(1, 3) as $ignored) {
            $session = $this->kioskSession();
            $photo = $this->readyPhoto($session);
            $this->acceptAtKiosk($session)->assertOk();
            $ids[] = $photo->id;
        }

        $queue = SlideshowQueue::activeFor($this->location);
        $this->assertSame(3, $queue->photosAwaitingApproval()->count());
        $this->assertSame(0, $queue->visiblePhotos()->count());

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/slideshow-queues/{$queue->id}/approve-pending")
            ->assertOk()
            ->assertJsonPath('data.awaiting_approval', 0)
            ->assertJsonPath('data.visible_photos', 3);

        $this->assertEqualsCanonicalizing($ids, $this->feedPhotoIds());
    }

    public function test_the_queue_reports_what_is_waiting_and_what_the_location_is_set_to(): void
    {
        $this->setting(['slideshow_requires_approval' => true, 'slideshow_auto_add_staff' => true]);
        $session = $this->kioskSession();
        $this->readyPhoto($session);
        $this->acceptAtKiosk($session)->assertOk();

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/slideshow-queues?location_id=' . $this->location->id)
            ->assertOk()
            ->assertJsonPath('data.active.awaiting_approval', 1)
            ->assertJsonPath('data.active.visible_photos', 0)
            ->assertJsonPath('data.settings.slideshow_requires_approval', true)
            ->assertJsonPath('data.settings.slideshow_auto_add_staff', true);
    }

    public function test_a_staff_photo_joins_the_slideshow_on_its_own_when_the_location_asks_for_it(): void
    {
        $this->setting(['slideshow_requires_approval' => true, 'slideshow_auto_add_staff' => true]);

        $session = PhotoSession::create([
            'company_id' => $this->location->company_id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->staff->id,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $photo = $this->readyPhoto($session, Photo::SOURCE_CAMERA);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/photo-sessions/{$session->id}/deliver", ['method' => 'staff_qr'])
            ->assertOk();

        $photo->refresh();
        $this->assertTrue($photo->slideshow_eligible);
        $this->assertSame(Photo::APPROVAL_PENDING, $photo->slideshow_approval_status);
        $this->assertNotContains($photo->id, $this->feedPhotoIds());
    }

    public function test_a_staff_photo_stays_off_the_slideshow_when_the_location_does_not_ask_for_it(): void
    {
        $this->setting(['slideshow_auto_add_staff' => false]);

        $session = PhotoSession::create([
            'company_id' => $this->location->company_id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->staff->id,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $photo = $this->readyPhoto($session, Photo::SOURCE_CAMERA);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/photo-sessions/{$session->id}/deliver", ['method' => 'staff_qr'])
            ->assertOk();

        $photo->refresh();
        $this->assertFalse($photo->slideshow_eligible);
        $this->assertNotContains($photo->id, $this->feedPhotoIds());
    }

    public function test_a_staff_tick_at_delivery_still_waits_for_approval(): void
    {
        $this->setting(['slideshow_requires_approval' => true, 'slideshow_auto_add_staff' => false]);

        $session = PhotoSession::create([
            'company_id' => $this->location->company_id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->staff->id,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $photo = $this->readyPhoto($session, Photo::SOURCE_CAMERA);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/photo-sessions/{$session->id}/deliver", [
                'method' => 'staff_qr',
                'slideshow_opt_in' => true,
            ])
            ->assertOk();

        $photo->refresh();
        $this->assertTrue($photo->slideshow_eligible);
        $this->assertSame(Photo::APPROVAL_PENDING, $photo->slideshow_approval_status);
        $this->assertNotContains($photo->id, $this->feedPhotoIds());
    }

    public function test_a_staff_tick_at_delivery_shows_at_once_when_approval_is_off(): void
    {
        $this->setting(['slideshow_requires_approval' => false, 'slideshow_auto_add_staff' => false]);

        $session = PhotoSession::create([
            'company_id' => $this->location->company_id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->staff->id,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $photo = $this->readyPhoto($session, Photo::SOURCE_CAMERA);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/photo-sessions/{$session->id}/deliver", [
                'method' => 'staff_qr',
                'slideshow_opt_in' => true,
            ])
            ->assertOk();

        $photo->refresh();
        $this->assertSame(Photo::APPROVAL_APPROVED, $photo->slideshow_approval_status);
        $this->assertContains($photo->id, $this->feedPhotoIds());
    }

    public function test_adding_a_library_photo_to_the_screen_is_itself_an_approval(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);

        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/inclusion", ['include' => true])
            ->assertOk();

        $photo->refresh();
        $this->assertSame(Photo::APPROVAL_APPROVED, $photo->slideshow_approval_status);
        $this->assertSame($this->staff->id, $photo->slideshow_approved_by);
        $this->assertContains($photo->id, $this->feedPhotoIds());
    }

    public function test_a_hidden_photo_stays_off_the_screen_after_it_is_approved(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);
        $this->acceptAtKiosk($session)->assertOk();

        $this->actingAs($this->staff, 'sanctum')
            ->patchJson("/api/slideshow-photos/{$photo->id}", ['slideshow_state' => 'hidden'])
            ->assertOk();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/approval", ['status' => 'approved'])
            ->assertOk();

        $this->assertNotContains($photo->id, $this->feedPhotoIds());
    }

    public function test_every_screen_is_told_how_the_location_is_set(): void
    {
        $this->setting([
            'slideshow_requires_approval' => true,
            'slideshow_auto_add_kiosk' => false,
            'slideshow_auto_add_staff' => true,
        ]);

        $this->withHeaders([
            'X-Photo-Device' => $this->devices->issue($this->location->id, PhotoDeviceTokenService::MODE_KIOSK)['token'],
        ])->getJson("/api/photos/kiosk/{$this->location->id}")
            ->assertOk()
            ->assertJsonPath('data.slideshow_offered', true)
            ->assertJsonPath('data.slideshow_default_on', false)
            ->assertJsonPath('data.slideshow_requires_approval', true);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->assertOk()
            ->assertJsonPath('data.slideshow_enabled', true)
            ->assertJsonPath('data.slideshow_requires_approval', true)
            ->assertJsonPath('data.slideshow_auto_add_staff', true);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/photo-settings?location_id=' . $this->location->id)
            ->assertOk()
            ->assertJsonPath('data.setting.slideshow_requires_approval', true)
            ->assertJsonPath('data.setting.slideshow_auto_add_kiosk', false)
            ->assertJsonPath('data.setting.slideshow_auto_add_staff', true);
    }

    public function test_a_photo_payload_says_whether_it_is_on_the_screen(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);
        $this->acceptAtKiosk($session)->assertOk();

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/photo-library?location_id=' . $this->location->id)
            ->assertOk()
            ->assertJsonPath('data.days.0.photos.0.awaiting_approval', true)
            ->assertJsonPath('data.days.0.photos.0.shows_in_slideshow', false)
            ->assertJsonPath('data.days.0.photos.0.slideshow_approval_status', 'pending');

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/approval", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.shows_in_slideshow', true)
            ->assertJsonPath('data.awaiting_approval', false)
            ->assertJsonPath('data.slideshow_approval_status', 'approved');
    }

    public function test_an_unapproved_photo_is_not_counted_as_showing(): void
    {
        $this->setting(['slideshow_requires_approval' => true]);
        $session = $this->kioskSession();
        $photo = $this->readyPhoto($session);
        $this->acceptAtKiosk($session)->assertOk();

        $queue = SlideshowQueue::activeFor($this->location);

        $this->assertSame(0, $queue->visiblePhotos()->count());
        $this->assertSame(1, $queue->photosAwaitingApproval()->count());
        $this->assertTrue($photo->fresh()->isAwaitingApproval());
    }
}
