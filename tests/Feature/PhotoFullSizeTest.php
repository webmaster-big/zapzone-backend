<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\PhotoSession;
use App\Models\User;
use App\Services\PhotoDeliveryService;
use App\Services\PhotoProcessingService;
use App\Support\OperatingDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoFullSizeTest extends TestCase
{
    use RefreshDatabase;

    private const GRAY = [128, 128, 128];

    private Company $company;

    private Location $location;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Storage::fake('photos');
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Full Size Co',
            'email' => 'owner@fullsize.test',
            'phone' => '5551230000',
            'address' => '1 Test Way',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Sharpville',
            'address' => '1 Test Way',
            'city' => 'Sharpville',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485550000',
            'email' => 'sharpville@fullsize.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'admin@fullsize.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    public function test_the_guests_copy_keeps_the_full_size_the_camera_or_upload_gave(): void
    {
        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(3840, 2160))->assertCreated();

        ob_start();
        imagejpeg($this->plain(4032, 3024), null, 90);
        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/photo-sessions/{$session->id}/photos", [
                'file' => UploadedFile::fake()->createWithContent('gallery.jpg', (string) ob_get_clean()),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        [$camera, $upload] = $session->photos()->orderBy('position')->get()->all();

        $this->assertSame([3840, 2160], [$camera->width, $camera->height]);
        $this->assertSame([3840, 2160], $this->storedSize($camera->delivery_path));
        $this->assertSame([1920, 1080], $this->storedSize($camera->slideshow_path));
        $this->assertSame([420, 236], $this->storedSize($camera->thumbnail_path));

        $this->assertSame(Photo::PROCESSING_READY, $upload->processing_status);
        $this->assertSame([4032, 3024], [$upload->width, $upload->height]);
        $this->assertSame([4032, 3024], $this->storedSize($upload->delivery_path));
    }

    public function test_the_date_keeps_the_same_share_of_the_photo_at_any_size(): void
    {
        LocationPhotoSetting::forLocation($this->location)->update(['date_font_size' => 60]);
        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(1920, 1080))->assertCreated();
        $this->captureFromCamera($session, $this->plain(3840, 2160))->assertCreated();

        [$standard, $sharp] = $session->photos()->orderBy('position')->get()->all();

        $standardText = $this->dateTextHeight($standard->delivery_path);
        $sharpText = $this->dateTextHeight($sharp->delivery_path);

        $this->assertGreaterThan(10, $standardText);
        $this->assertEqualsWithDelta(2.0, $sharpText / $standardText, 0.15, "1080p date {$standardText}px vs 4K date {$sharpText}px");
    }

    public function test_a_server_short_of_memory_still_delivers_the_largest_copy_it_can(): void
    {
        $this->app->instance(PhotoProcessingService::class, new class extends PhotoProcessingService {
            protected function hasMemoryFor(int $pixels): bool
            {
                return $pixels <= 3000000;
            }
        });
        Log::spy();

        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(3840, 2160))->assertCreated();
        $photo = $session->photos()->first();

        $this->assertSame(Photo::PROCESSING_READY, $photo->processing_status);
        $this->assertGreaterThanOrEqual(PhotoProcessingService::SLIDESHOW_MAX_EDGE, $photo->width);
        $this->assertLessThan(3840, $photo->width);
        $this->assertLessThanOrEqual(3000000, $photo->width * $photo->height);
        $this->assertSame([$photo->width, $photo->height], $this->storedSize($photo->delivery_path));

        Log::shouldHaveReceived('warning')
            ->with('Photo delivered below its full size because the server is short of memory.', \Mockery::on(
                fn (array $context) => $context['photo_id'] === $photo->id && $context['full_edge'] === 3840 && $context['delivered_edge'] === $photo->width
            ))
            ->once();
    }

    public function test_a_server_with_almost_no_memory_never_goes_below_the_slideshow_size(): void
    {
        $this->app->instance(PhotoProcessingService::class, new class extends PhotoProcessingService {
            protected function hasMemoryFor(int $pixels): bool
            {
                return false;
            }
        });

        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(3840, 2160))->assertCreated();
        $this->captureFromCamera($session, $this->plain(1280, 720))->assertCreated();

        [$sharp, $small] = $session->photos()->orderBy('position')->get()->all();

        $this->assertSame([1920, 1080], [$sharp->width, $sharp->height]);
        $this->assertSame([1280, 720], [$small->width, $small->height]);
    }

    public function test_photo_emails_switch_to_the_smaller_copy_once_the_full_copies_would_be_too_big(): void
    {
        $session = $this->openPhotoSession();
        $megabyte = 1048576;
        $this->readyPhoto($session, 1, 7 * $megabyte, $megabyte / 2);
        $this->readyPhoto($session, 2, 7 * $megabyte, $megabyte / 2);
        $this->readyPhoto($session, 3, 7 * $megabyte, $megabyte / 2);

        $sizes = array_map(
            fn (array $file) => strlen(base64_decode($file['data'])),
            app(PhotoDeliveryService::class)->photoAttachments($session, 'Lab Escape')
        );

        $this->assertSame([7 * $megabyte, 7 * $megabyte, $megabyte / 2], $sizes);
        $this->assertLessThanOrEqual(PhotoDeliveryService::ATTACHMENT_BUDGET_BYTES, array_sum($sizes));

        $small = $this->openPhotoSession();
        $this->readyPhoto($small, 1, 3 * $megabyte, $megabyte / 2);
        $this->readyPhoto($small, 2, 3 * $megabyte, $megabyte / 2);

        $this->assertSame([3 * $megabyte, 3 * $megabyte], array_map(
            fn (array $file) => strlen(base64_decode($file['data'])),
            app(PhotoDeliveryService::class)->photoAttachments($small, 'Lab Escape')
        ));
    }

    private function readyPhoto(PhotoSession $session, int $position, int $deliveryBytes, int $slideshowBytes): void
    {
        $base = "x/{$session->id}/{$position}";
        Storage::disk('photos')->put("{$base}/delivery.jpg", str_repeat('d', $deliveryBytes));
        Storage::disk('photos')->put("{$base}/slideshow.jpg", str_repeat('s', $slideshowBytes));

        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $session->company_id,
            'location_id' => $session->location_id,
            'position' => $position,
            'source' => Photo::SOURCE_CAMERA,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => "{$base}/original.jpg",
            'delivery_path' => "{$base}/delivery.jpg",
            'slideshow_path' => "{$base}/slideshow.jpg",
            'thumbnail_path' => "{$base}/thumb.jpg",
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
    }

    private function openPhotoSession(): PhotoSession
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/photo-sessions', [
            'location_id' => $this->location->id,
            'verbal_consent' => true,
        ])->assertCreated();

        return PhotoSession::findOrFail($response->json('data.id'));
    }

    private function captureFromCamera(PhotoSession $session, \GdImage $image)
    {
        ob_start();
        imagejpeg($image, null, 95);

        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-sessions/{$session->id}/photos", [
            'image' => 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean()),
            'source' => Photo::SOURCE_CAMERA,
        ]);
    }

    private function plain(int $w, int $h): \GdImage
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, ...self::GRAY));

        return $image;
    }

    private function storedSize(string $path): array
    {
        $info = getimagesize(Storage::disk('photos')->path($path));

        return [$info[0], $info[1]];
    }

    private function dateTextHeight(string $path): int
    {
        $image = imagecreatefromjpeg(Storage::disk('photos')->path($path));
        $w = imagesx($image);
        $h = imagesy($image);
        $top = null;
        $bottom = null;

        for ($y = (int) ($h * 0.75); $y < $h; $y++) {
            for ($x = (int) ($w * 0.3); $x < $w; $x++) {
                $rgb = imagecolorat($image, $x, $y);

                if ((($rgb >> 16) & 0xFF) > 235 && (($rgb >> 8) & 0xFF) > 235 && ($rgb & 0xFF) > 235) {
                    $top ??= $y;
                    $bottom = $y;
                    break;
                }
            }
        }

        return $top === null ? 0 : $bottom - $top + 1;
    }
}
