<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Photo;
use App\Models\PhotoOverlay;
use App\Models\PhotoSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FrameImages;
use Tests\TestCase;

class PhotoOverlayVersionsTest extends TestCase
{
    use RefreshDatabase;
    use FrameImages;

    private const GRAY = [128, 128, 128];
    private const CYAN = [0, 255, 255];
    private const ORANGE = [255, 140, 0];
    private const RED = [230, 20, 20];
    private const BLUE = [20, 20, 230];

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
            'company_name' => 'Frames Co',
            'email' => 'owner@frames.test',
            'phone' => '5551230000',
            'address' => '1 Test Way',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Frameville',
            'address' => '1 Test Way',
            'city' => 'Frameville',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485550000',
            'email' => 'frameville@frames.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'admin@frames.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    public function test_each_photo_gets_the_frame_version_that_matches_its_shape(): void
    {
        $this->uploadFrame([
            'image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN),
            'alt_image' => $this->edgeFrame('tall.png', 1080, 1920, self::ORANGE),
        ])->assertCreated()
            ->assertJsonPath('data.missing_shape', null)
            ->assertJsonPath('data.versions.0.shape', 'landscape')
            ->assertJsonPath('data.versions.1.shape', 'portrait');

        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(1080, 1920))->assertCreated();
        $this->captureFromCamera($session, $this->plain(1920, 1080))->assertCreated();

        [$portrait, $landscape] = $session->photos()->orderBy('position')->get()->all();

        $this->assertSame([1080, 1920], [$portrait->width, $portrait->height]);
        $this->assertWholeFrame($portrait->delivery_path, self::ORANGE, 'portrait photo');
        $this->assertWholeFrame($portrait->slideshow_path, self::ORANGE, 'portrait slideshow copy');

        $this->assertSame([1920, 1080], [$landscape->width, $landscape->height]);
        $this->assertWholeFrame($landscape->delivery_path, self::CYAN, 'landscape photo');
    }

    public function test_a_frame_with_one_version_says_which_shape_is_missing_until_it_is_added(): void
    {
        $response = $this->uploadFrame(['image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN)])
            ->assertCreated()
            ->assertJsonPath('data.missing_shape', 'portrait')
            ->assertJsonPath('data.alt_image_url', null);
        $overlayId = $response->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/photo-sessions/context?location_id={$this->location->id}")
            ->assertOk()
            ->assertJsonPath('data.active_overlay.missing_shape', 'portrait');

        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(1080, 1920))->assertCreated();
        $photo = $session->photos()->first();
        $this->assertSame(Photo::PROCESSING_READY, $photo->processing_status);
        $this->assertSame($overlayId, $photo->photo_overlay_id);
        $this->assertSame([1080, 1920], [$photo->width, $photo->height]);

        $this->updateFrame($overlayId, ['alt_image' => $this->edgeFrame('tall.png', 1080, 1920, self::ORANGE)])
            ->assertOk()
            ->assertJsonPath('data.missing_shape', null);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/photo-sessions/context?location_id={$this->location->id}")
            ->assertJsonPath('data.active_overlay.missing_shape', null);

        $portraitOnly = $this->uploadFrame(['image' => $this->edgeFrame('tall.png', 1080, 1920, self::ORANGE)])
            ->assertCreated()
            ->assertJsonPath('data.missing_shape', 'landscape');
        $this->uploadFrame(['image' => $this->edgeFrame('square.png', 1080, 1080, self::CYAN)])
            ->assertCreated()
            ->assertJsonPath('data.missing_shape', null);
        $this->assertNotSame($overlayId, $portraitOnly->json('data.id'));
    }

    public function test_the_second_version_must_be_the_other_shape(): void
    {
        $this->uploadFrame([
            'image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN),
            'alt_image' => $this->edgeFrame('wide-too.png', 1600, 900, self::ORANGE),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['alt_image'])
            ->assertJsonPath('message', 'Both versions of this frame would be landscape. Give it one wide (landscape) version and one tall (portrait) version.');

        $this->uploadFrame([
            'image' => $this->edgeFrame('square.png', 1000, 1000, self::CYAN),
            'alt_image' => $this->edgeFrame('square-too.png', 800, 800, self::ORANGE),
        ])->assertStatus(422)->assertJsonValidationErrors(['alt_image']);

        $this->assertSame(0, PhotoOverlay::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $overlayId = $this->uploadFrame([
            'image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN),
            'alt_image' => $this->edgeFrame('tall.png', 1080, 1920, self::ORANGE),
        ])->assertCreated()->json('data.id');

        $this->updateFrame($overlayId, ['alt_image' => $this->edgeFrame('wide-again.png', 1920, 1080, self::ORANGE)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alt_image']);
        $this->updateFrame($overlayId, ['image' => $this->edgeFrame('tall-main.png', 1080, 1920, self::CYAN)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        $this->assertCount(2, Storage::disk('public')->allFiles());

        $this->updateFrame($overlayId, [
            'image' => $this->edgeFrame('tall-main.png', 1080, 1920, self::CYAN),
            'alt_image' => $this->edgeFrame('wide-alt.png', 1920, 1080, self::ORANGE),
        ])->assertOk()->assertJsonPath('data.versions.0.shape', 'portrait')->assertJsonPath('data.versions.1.shape', 'landscape');
    }

    public function test_one_of_the_two_versions_must_be_tall(): void
    {
        $this->uploadFrame([
            'image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN),
            'alt_image' => $this->edgeFrame('square.png', 1080, 1080, self::ORANGE),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['alt_image'])
            ->assertJsonPath('message', 'One version of this frame must be tall. Give it one wide (landscape) version and one tall (portrait) version.');

        $this->uploadFrame([
            'image' => $this->edgeFrame('square.png', 1080, 1080, self::CYAN),
            'alt_image' => $this->edgeFrame('tall.png', 1080, 1920, self::ORANGE),
        ])->assertCreated()->assertJsonPath('data.missing_shape', null);
    }

    public function test_plain_edits_are_never_blocked_by_the_stored_versions(): void
    {
        $overlayId = $this->uploadFrame([
            'image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN),
            'alt_image' => $this->edgeFrame('tall.png', 1080, 1920, self::ORANGE),
        ])->assertCreated()->json('data.id');

        $overlay = PhotoOverlay::findOrFail($overlayId);
        Storage::disk('public')->put($overlay->alt_image_path, $this->framePng(1600, 900));

        $this->updateFrame($overlayId, ['is_enabled' => '0'])->assertOk()->assertJsonPath('data.is_enabled', false);
        $this->updateFrame($overlayId, ['name' => 'Renamed frame'])->assertOk()->assertJsonPath('data.name', 'Renamed frame');
    }

    public function test_an_unreadable_frame_still_lets_the_photo_through_with_the_date(): void
    {
        $path = "photo-overlays/{$this->location->id}/broken.png";
        Storage::disk('public')->put($path, "\x89PNG\r\n\x1a\nnot really an image");
        PhotoOverlay::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Broken frame',
            'image_path' => $path,
            'priority' => 10,
        ]);

        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(1920, 1080))->assertCreated();
        $photo = $session->photos()->first();

        $this->assertSame(Photo::PROCESSING_READY, $photo->processing_status);
        $this->assertSame([1920, 1080], [$photo->width, $photo->height]);
        $this->assertGreaterThan(10, $this->dateTextHeight($photo->delivery_path));
    }

    public function test_a_frame_with_no_see_through_area_is_refused(): void
    {
        $message = 'This frame has no see-through area, so it would hide the whole photo. Save it as a PNG with a transparent middle.';

        $this->uploadFrame(['image' => $this->frameUpload('solid.png', 1920, 1080, false)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image'])
            ->assertJsonPath('message', $message);

        $this->uploadFrame(['image' => UploadedFile::fake()->image('photo-as-frame.jpg', 1920, 1080)])
            ->assertStatus(422)
            ->assertJsonPath('message', $message);

        $this->uploadFrame([
            'image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN),
            'alt_image' => $this->frameUpload('solid-tall.png', 1080, 1920, false),
        ])->assertStatus(422)->assertJsonValidationErrors(['alt_image']);

        $this->assertSame(0, PhotoOverlay::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->uploadFrame(['image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN)])->assertCreated();
        $this->uploadFrame(['image' => $this->frameUpload('corner-logo.png', 400, 300)])->assertCreated();
    }

    public function test_a_huge_frame_is_refused_with_the_size_to_use_instead(): void
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 6000, 4500, 8, 6, 0, 0, 0)) . $chunk('IEND', '');

        $this->uploadFrame(['image' => UploadedFile::fake()->createWithContent('huge.png', $png)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image'])
            ->assertJsonPath('message', 'This frame is very large (6000 × 4500 pixels). Save it at about 3840 × 2160 for the landscape version or 2160 × 3840 for the portrait version.');

        $this->uploadFrame(['image' => $this->frameUpload('big-but-fine.png', 3840, 2160)])->assertCreated();
        $this->assertSame(1, PhotoOverlay::count());
    }

    public function test_the_second_version_can_be_added_replaced_and_removed_and_its_files_are_cleaned_up(): void
    {
        $overlayId = $this->uploadFrame(['image' => $this->edgeFrame('wide.png', 1920, 1080, self::CYAN)])
            ->assertCreated()
            ->json('data.id');
        $main = PhotoOverlay::findOrFail($overlayId)->image_path;

        $this->updateFrame($overlayId, ['alt_image' => $this->edgeFrame('tall.png', 1080, 1920, self::ORANGE)])->assertOk();
        $first = PhotoOverlay::findOrFail($overlayId)->alt_image_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $this->updateFrame($overlayId, ['alt_image' => $this->edgeFrame('tall-2.png', 1080, 1920, self::ORANGE)])->assertOk();
        $second = PhotoOverlay::findOrFail($overlayId)->alt_image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertExists($main);

        $this->updateFrame($overlayId, ['name' => 'Renamed'])->assertOk();
        $this->assertSame($second, PhotoOverlay::findOrFail($overlayId)->alt_image_path);

        $this->updateFrame($overlayId, ['remove_alt_image' => '1'])
            ->assertOk()
            ->assertJsonPath('data.alt_image_url', null)
            ->assertJsonPath('data.missing_shape', 'portrait');
        $this->assertNull(PhotoOverlay::findOrFail($overlayId)->alt_image_path);
        Storage::disk('public')->assertMissing($second);
        Storage::disk('public')->assertExists($main);

        $this->updateFrame($overlayId, ['alt_image' => $this->edgeFrame('tall-3.png', 1080, 1920, self::ORANGE)])->assertOk();
        $third = PhotoOverlay::findOrFail($overlayId)->alt_image_path;

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/photo-overlays/{$overlayId}")->assertOk();
        Storage::disk('public')->assertMissing($main);
        Storage::disk('public')->assertMissing($third);
    }

    public function test_the_date_is_drawn_the_same_size_on_portrait_and_landscape_photos(): void
    {
        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(1080, 1920))->assertCreated();
        $this->captureFromCamera($session, $this->plain(1920, 1080))->assertCreated();

        [$portrait, $landscape] = $session->photos()->orderBy('position')->get()->all();

        $portraitText = $this->dateTextHeight($portrait->delivery_path);
        $landscapeText = $this->dateTextHeight($landscape->delivery_path);

        $this->assertGreaterThan(10, $landscapeText);
        $this->assertLessThanOrEqual(2, abs($portraitText - $landscapeText), "portrait date {$portraitText}px vs landscape {$landscapeText}px");
    }

    public function test_a_jpeg_frame_with_a_rotation_tag_is_applied_upright(): void
    {
        $upright = imagecreatetruecolor(1920, 1080);
        imagefilledrectangle($upright, 0, 0, 1919, 539, imagecolorallocate($upright, ...self::RED));
        imagefilledrectangle($upright, 0, 540, 1919, 1079, imagecolorallocate($upright, ...self::BLUE));
        $stored = imagerotate($upright, 90, 0);

        ob_start();
        imagejpeg($stored, null, 95);
        $jpeg = ob_get_clean();

        $path = "photo-overlays/{$this->location->id}/rotated.jpg";
        Storage::disk('public')->put($path, $this->withOrientation($jpeg, 6));
        PhotoOverlay::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Old JPEG frame',
            'image_path' => $path,
            'priority' => 10,
        ]);

        $session = $this->openPhotoSession();
        $this->captureFromCamera($session, $this->plain(1920, 1080))->assertCreated();
        $image = $this->load($session->photos()->first()->delivery_path);

        $this->assertColor(self::RED, $this->rgb($image, 1200, 60), 'top right of the frame');
        $this->assertColor(self::BLUE, $this->rgb($image, 400, 700), 'bottom left of the frame');
    }

    private function uploadFrame(array $fields)
    {
        return $this->actingAs($this->admin, 'sanctum')->post('/api/photo-overlays', array_merge([
            'location_id' => $this->location->id,
            'name' => 'Venue frame',
        ], $fields), ['Accept' => 'application/json']);
    }

    private function updateFrame(int $overlayId, array $fields)
    {
        return $this->actingAs($this->admin, 'sanctum')->post("/api/photo-overlays/{$overlayId}", $fields, ['Accept' => 'application/json']);
    }

    private function edgeFrame(string $name, int $w, int $h, array $rgb): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $edge = 16;
        $color = imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], 0);
        imagefilledrectangle($image, 0, 0, $w - 1, $edge - 1, $color);
        imagefilledrectangle($image, 0, $h - $edge, $w - 1, $h - 1, $color);
        imagefilledrectangle($image, 0, 0, $edge - 1, $h - 1, $color);
        imagefilledrectangle($image, $w - $edge, 0, $w - 1, $h - 1, $color);

        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    private function assertWholeFrame(string $path, array $rgb, string $label): void
    {
        $image = $this->load($path);
        $w = imagesx($image);
        $h = imagesy($image);

        foreach ([
            'left edge' => [2, intdiv($h, 2)],
            'right edge' => [$w - 3, intdiv($h, 2)],
            'top edge' => [intdiv($w, 2), 2],
            'bottom edge' => [intdiv($w, 3), $h - 3],
            'top left corner' => [2, 2],
            'top right corner' => [$w - 3, 2],
            'bottom left corner' => [2, $h - 3],
        ] as $where => [$x, $y]) {
            $this->assertColor($rgb, $this->rgb($image, $x, $y), "{$label}: {$where}");
        }

        $this->assertColor(self::GRAY, $this->rgb($image, intdiv($w, 2), intdiv($h, 3)), "{$label}: the photo inside the frame");
    }

    private function dateTextHeight(string $path): int
    {
        $image = $this->load($path);
        $w = imagesx($image);
        $h = imagesy($image);
        $top = null;
        $bottom = null;

        for ($y = (int) ($h * 0.75); $y < $h; $y++) {
            for ($x = (int) ($w * 0.3); $x < $w; $x++) {
                [$r, $g, $b] = $this->rgb($image, $x, $y);

                if ($r > 235 && $g > 235 && $b > 235) {
                    $top ??= $y;
                    $bottom = $y;
                    break;
                }
            }
        }

        return $top === null ? 0 : $bottom - $top + 1;
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

    private function withOrientation(string $jpeg, int $tag): string
    {
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 1)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $tag) . pack('v', 0)
            . pack('V', 0);
        $payload = "Exif\0\0" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private function load(string $path): \GdImage
    {
        return imagecreatefromstring(Storage::disk('photos')->get($path));
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
}
