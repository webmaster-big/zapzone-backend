<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Photo;
use App\Models\PhotoOverlay;
use App\Models\PhotoSession;
use App\Models\User;
use App\Services\PhotoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoOrientationTest extends TestCase
{
    use RefreshDatabase;

    private const GRAY = [128, 128, 128];
    private const RED = [230, 20, 20];
    private const GREEN = [20, 200, 20];
    private const BLUE = [20, 20, 230];
    private const YELLOW = [240, 230, 20];
    private const CYAN = [0, 255, 255];
    private const MAGENTA = [255, 0, 255];

    private Company $company;

    private Location $location;

    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Storage::fake('photos');
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Orientation Co',
            'email' => 'owner@orientation.test',
            'phone' => '5551230000',
            'address' => '1 Test Way',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Testville',
            'address' => '1 Test Way',
            'city' => 'Testville',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485550000',
            'email' => 'testville@orientation.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->attendant = User::create([
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'email' => 'staff@orientation.test',
            'password' => bcrypt('secret-password'),
            'role' => 'attendant',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    public function test_a_landscape_camera_photo_is_saved_landscape_and_a_portrait_one_stays_portrait(): void
    {
        $session = $this->openPhotoSession();

        $this->captureFromCamera($session, $this->scene(1920, 1080))->assertCreated();
        $this->captureFromCamera($session, $this->scene(1080, 1920))->assertCreated();

        [$landscape, $portrait] = $session->photos()->orderBy('position')->get()->all();

        $this->assertSame([1600, 900], [$landscape->width, $landscape->height]);
        $this->assertSame([1600, 900], $this->storedSize($landscape->delivery_path));
        $this->assertSame([1920, 1080], $this->storedSize($landscape->slideshow_path));
        $this->assertSame([420, 236], $this->storedSize($landscape->thumbnail_path));
        $this->assertCorners($landscape->delivery_path);

        $this->assertSame([900, 1600], [$portrait->width, $portrait->height]);
        $this->assertSame([900, 1600], $this->storedSize($portrait->delivery_path));
        $this->assertCorners($portrait->delivery_path);
    }

    public function test_a_landscape_frame_lines_up_with_the_edges_of_a_landscape_group_photo(): void
    {
        $overlay = $this->makeOverlay(1920, 1080);
        $session = $this->openPhotoSession();

        $this->captureFromCamera($session, $this->scene(1920, 1080))->assertCreated();
        $this->captureFromCamera($session, $this->scene(1600, 924))->assertCreated();

        foreach ($session->photos()->orderBy('position')->get() as $photo) {
            $this->assertSame($overlay->id, $photo->photo_overlay_id);
            $image = $this->load($photo->delivery_path);
            $w = imagesx($image);
            $h = imagesy($image);

            $this->assertColor(self::CYAN, $this->rgb($image, 2, intdiv($h, 2)), 'left edge of the frame');
            $this->assertColor(self::CYAN, $this->rgb($image, $w - 3, intdiv($h, 2)), 'right edge of the frame');
            $this->assertColor(self::MAGENTA, $this->rgb($image, intdiv($w, 2), 2), 'top edge of the frame');
            $this->assertColor(self::MAGENTA, $this->rgb($image, intdiv($w, 2), $h - 3), 'bottom edge of the frame');
            $this->assertColor(self::GRAY, $this->rgb($image, intdiv($w, 4), intdiv($h, 3)), 'the photo inside the frame');
        }
    }

    public function test_frames_of_a_clearly_different_shape_still_cover_the_photo_as_before(): void
    {
        $processor = app(PhotoProcessingService::class);

        $this->assertSame([0, 0, 1600, 900], $processor->overlayPlacement(1600, 900, 1920, 1080));
        $this->assertSame([0, 0, 1600, 924], $processor->overlayPlacement(1600, 924, 1920, 1080));
        $this->assertSame([0, 0, 1600, 1200], $processor->overlayPlacement(1600, 1200, 1600, 1200));
        $this->assertSame([0, -150, 1600, 1200], $processor->overlayPlacement(1600, 900, 1600, 1200));
        $this->assertSame([-972, 0, 2844, 1600], $processor->overlayPlacement(900, 1600, 1920, 1080));

        $overlay = $this->makeOverlay(1920, 1080);
        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->scene(1080, 1920))->assertCreated();

        $photo = $session->photos()->first();
        $this->assertSame(Photo::PROCESSING_READY, $photo->processing_status);
        $this->assertSame($overlay->id, $photo->photo_overlay_id);
        $this->assertSame([900, 1600], [$photo->width, $photo->height]);
    }

    public function test_uploaded_photos_are_turned_upright_for_every_camera_orientation_tag(): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('The exif extension is not installed, so camera orientation tags cannot be read.');
        }

        $session = $this->openPhotoSession();

        foreach (range(1, 8) as $tag) {
            $upright = $this->scene(300, 200);
            $stored = $this->storedFor($upright, $tag);

            $this->actingAs($this->attendant, 'sanctum')->post(
                "/api/photo-sessions/{$session->id}/photos",
                ['file' => UploadedFile::fake()->createWithContent("tag-{$tag}.jpg", $this->withOrientation($this->jpeg($stored), $tag))],
                ['Accept' => 'application/json']
            )->assertCreated();

            $photo = $session->photos()->orderByDesc('id')->first();
            $this->assertSame(Photo::PROCESSING_READY, $photo->processing_status, "orientation {$tag}");
            $this->assertSame([300, 200], [$photo->width, $photo->height], "orientation {$tag}");
            $this->assertCorners($photo->delivery_path, "orientation {$tag}");

            $session->photos()->whereKey($photo->id)->delete();
        }
    }

    private function openPhotoSession(): PhotoSession
    {
        $response = $this->actingAs($this->attendant, 'sanctum')->postJson('/api/photo-sessions', [
            'location_id' => $this->location->id,
            'verbal_consent' => true,
        ])->assertCreated();

        return PhotoSession::findOrFail($response->json('data.id'));
    }

    private function captureFromCamera(PhotoSession $session, \GdImage $image)
    {
        return $this->actingAs($this->attendant, 'sanctum')->postJson("/api/photo-sessions/{$session->id}/photos", [
            'image' => 'data:image/jpeg;base64,' . base64_encode($this->jpeg($image)),
            'source' => Photo::SOURCE_CAMERA,
        ]);
    }

    private function scene(int $w, int $h): \GdImage
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, ...self::GRAY));
        $block = (int) round(min($w, $h) * 0.25);
        imagefilledrectangle($image, 0, 0, $block, $block, imagecolorallocate($image, ...self::RED));
        imagefilledrectangle($image, $w - $block - 1, 0, $w - 1, $block, imagecolorallocate($image, ...self::GREEN));
        imagefilledrectangle($image, 0, $h - $block - 1, $block, $h - 1, imagecolorallocate($image, ...self::BLUE));
        imagefilledrectangle($image, $w - $block - 1, $h - $block - 1, $w - 1, $h - 1, imagecolorallocate($image, ...self::YELLOW));

        return $image;
    }

    private function storedFor(\GdImage $upright, int $tag): \GdImage
    {
        $image = match ($tag) {
            3 => imagerotate($upright, 180, 0),
            6 => imagerotate($upright, 90, 0),
            8 => imagerotate($upright, -90, 0),
            5 => imagerotate($upright, -90, 0),
            7 => imagerotate($upright, 90, 0),
            default => $upright,
        };

        if (in_array($tag, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($tag === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    private function jpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 95);

        return ob_get_clean();
    }

    private function withOrientation(string $jpeg, int $tag): string
    {
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 1)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $tag) . pack('v', 0)
            . pack('V', 0);
        $payload = "Exif\0\0" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private function makeOverlay(int $w, int $h): PhotoOverlay
    {
        $image = imagecreatetruecolor($w, $h);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $edge = 12;
        imagefilledrectangle($image, 0, 0, $edge - 1, $h - 1, imagecolorallocatealpha($image, ...[...self::CYAN, 0]));
        imagefilledrectangle($image, $w - $edge, 0, $w - 1, $h - 1, imagecolorallocatealpha($image, ...[...self::CYAN, 0]));
        imagefilledrectangle($image, $edge, 0, $w - $edge - 1, $edge - 1, imagecolorallocatealpha($image, ...[...self::MAGENTA, 0]));
        imagefilledrectangle($image, $edge, $h - $edge, $w - $edge - 1, $h - 1, imagecolorallocatealpha($image, ...[...self::MAGENTA, 0]));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        $path = "photo-overlays/{$this->location->id}/frame-{$w}x{$h}.png";
        Storage::disk('public')->put($path, $png);

        return PhotoOverlay::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => "Frame {$w}x{$h}",
            'image_path' => $path,
            'priority' => 10,
        ]);
    }

    private function load(string $path): \GdImage
    {
        return imagecreatefromstring(Storage::disk('photos')->get($path));
    }

    private function storedSize(string $path): array
    {
        $image = $this->load($path);

        return [imagesx($image), imagesy($image)];
    }

    private function rgb(\GdImage $image, int $x, int $y): array
    {
        $c = imagecolorat($image, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    private function assertColor(array $expected, array $actual, string $where): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->assertLessThanOrEqual(40, abs($expected[$i] - $actual[$i]), sprintf(
                '%s: expected rgb(%s), got rgb(%s)',
                $where,
                implode(',', $expected),
                implode(',', $actual)
            ));
        }
    }

    private function assertCorners(string $path, string $label = ''): void
    {
        $image = $this->load($path);
        $w = imagesx($image);
        $h = imagesy($image);
        $inset = (int) round(min($w, $h) * 0.08);

        $this->assertColor(self::RED, $this->rgb($image, $inset, $inset), trim("{$label} top-left"));
        $this->assertColor(self::GREEN, $this->rgb($image, $w - $inset, $inset), trim("{$label} top-right"));
        $this->assertColor(self::BLUE, $this->rgb($image, $inset, $h - $inset), trim("{$label} bottom-left"));
        $this->assertColor(self::YELLOW, $this->rgb($image, $w - 4, $h - 4), trim("{$label} bottom-right"));
    }
}
