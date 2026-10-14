<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\User;
use App\Models\WaiverTemplate;
use App\Services\EscapeRoomSessionService;
use App\Services\WaiverService;
use App\Support\PreviewImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EscapeRoomRoomImageTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-03';

    private Company $company;

    private Location $location;

    private User $admin;

    private array $madeFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'app.frontend_url' => 'https://zapzone.test']);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse(self::TODAY . ' 13:30:00', 'America/Detroit'));

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Farmington | Escape Room',
            'address' => '1 Test Way',
            'city' => 'Farmington',
            'state' => 'MI',
            'zip_code' => '48335',
            'phone' => '2485551234',
            'email' => 'farmington@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'first_name' => 'Company',
            'last_name' => 'Admin',
            'email' => 'admin.user@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'Escape Room Waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}} for {{activity_name}}.</p>',
            'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            'assigned_package_ids' => [],
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);
    }

    protected function tearDown(): void
    {
        foreach ($this->madeFiles as $relative) {
            $preview = PreviewImage::pathFor($relative);
            if ($preview !== null) {
                Storage::disk(PreviewImage::DISK)->delete($preview);
            }
            $absolute = storage_path('app/public/' . $relative);
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        parent::tearDown();
    }

    private function photo(string $prefix, int $width = 1600, int $height = 900, ?string $bytes = null): string
    {
        $relative = 'images/packages/' . $prefix . '-' . uniqid() . '.jpg';
        $absolute = storage_path('app/public/' . $relative);

        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        if ($bytes === null) {
            $canvas = imagecreatetruecolor($width, $height);
            imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, imagecolorallocate($canvas, 40, 90, 160));
            imagejpeg($canvas, $absolute, 90);
        } else {
            file_put_contents($absolute, $bytes);
        }

        $this->madeFiles[] = $relative;

        return $relative;
    }

    private function room(string $name, mixed $image, bool $escapeRoom = true): Package
    {
        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'description' => 'Test room',
            'category' => 'Escape Room',
            'price' => 30,
            'pricing_type' => 'per_person',
            'min_participants' => 1,
            'max_participants' => 10,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
            'is_escape_room' => $escapeRoom,
            'image' => $image,
        ]);

        PackageAvailabilitySchedule::create([
            'package_id' => $package->id,
            'availability_type' => 'daily',
            'day_configuration' => [],
            'time_slot_start' => '11:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 60,
            'priority' => 0,
            'is_active' => true,
        ]);

        return $package;
    }

    private function kioskRooms(array $query = []): array
    {
        $url = "/api/waivers/escape-room/{$this->location->id}" . ($query === [] ? '' : '?' . http_build_query($query));

        return collect($this->getJson($url)->assertOk()->json('data.rooms'))->keyBy('id')->all();
    }

    private function previewExists(string $source): bool
    {
        $preview = PreviewImage::pathFor($source);

        return $preview !== null && Storage::disk(PreviewImage::DISK)->exists($preview);
    }

    public function test_room_cards_carry_the_package_photo_and_a_small_preview_follows(): void
    {
        $photo = $this->photo('morgue');
        $room = $this->room('The Morgue', [$photo]);

        $first = $this->kioskRooms()[$room->id];
        $this->assertSame($photo, $first['image']);
        $this->assertNull($first['image_preview']);
        $this->assertTrue($first['image_pending'], 'the kiosk is told to wait instead of loading the full-size photo');
        $this->assertNotEmpty($first['times']);

        $preview = PreviewImage::pathFor($photo);
        $this->assertNotNull($preview);
        $this->assertTrue(Storage::disk(PreviewImage::DISK)->exists($preview), 'the preview is made after the first response');

        $size = getimagesize(Storage::disk(PreviewImage::DISK)->path($preview));
        $this->assertSame([800, 450], [$size[0], $size[1]]);
        $this->assertSame('image/jpeg', $size['mime']);

        $second = $this->kioskRooms()[$room->id];
        $this->assertSame($photo, $second['image']);
        $this->assertSame($preview, $second['image_preview']);
        $this->assertFalse($second['image_pending']);
    }

    public function test_the_preview_follows_a_replaced_package_photo(): void
    {
        $old = $this->photo('old');
        $room = $this->room('Airlock', [$old]);
        $this->kioskRooms();
        $oldPreview = PreviewImage::pathFor($old);
        $this->assertTrue(Storage::disk(PreviewImage::DISK)->exists($oldPreview));

        $new = $this->photo('new', 1200, 900);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/packages/{$room->id}", ['image' => [$new]])
            ->assertOk();

        $this->assertFileDoesNotExist(storage_path('app/public/' . $old));
        $this->assertFalse(Storage::disk(PreviewImage::DISK)->exists($oldPreview), 'the replaced photo takes its preview with it');
        $this->assertTrue($this->previewExists($new), 'saving the photo prepares its preview before any guest loads the kiosk');

        $afterReplace = $this->kioskRooms()[$room->id];
        $this->assertSame($new, $afterReplace['image']);
        $this->assertSame(PreviewImage::pathFor($new), $afterReplace['image_preview']);
        $this->assertFalse($afterReplace['image_pending']);
        $this->assertNotSame($oldPreview, $afterReplace['image_preview']);
        $size = getimagesize(Storage::disk(PreviewImage::DISK)->path($afterReplace['image_preview']));
        $this->assertSame([800, 600], [$size[0], $size[1]]);
    }

    public function test_only_escape_rooms_get_previews_and_switching_a_room_on_prepares_one(): void
    {
        $photo = $this->photo('party');
        $party = $this->room('Birthday Party', [$photo], false);

        $this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertOk();
        $this->assertFalse($this->previewExists($photo), 'a package that is not on the check-in screen gets no preview');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/packages/{$party->id}", ['is_escape_room' => true])
            ->assertOk();
        $this->assertTrue($this->previewExists($photo), 'switching the escape-room flag on prepares the picture');
    }

    public function test_a_photo_shared_by_a_duplicated_package_survives_replacing_one_copy(): void
    {
        $shared = $this->photo('shared');
        $original = $this->room('Bank Heist', [$shared]);
        $copy = $this->room('Bank Heist (copy)', [$shared]);
        $this->kioskRooms();
        $this->assertTrue($this->previewExists($shared));

        $replacement = $this->photo('replacement');
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/packages/{$original->id}", ['image' => [$replacement]])
            ->assertOk();

        $this->assertFileExists(storage_path('app/public/' . $shared), 'a photo another package still shows must not be deleted');
        $this->assertTrue($this->previewExists($shared));
        $rooms = $this->kioskRooms();
        $this->assertSame($shared, $rooms[$copy->id]['image']);
        $this->assertSame(PreviewImage::pathFor($shared), $rooms[$copy->id]['image_preview']);

    }

    public function test_a_photo_kept_by_a_deleted_package_survives_until_that_package_is_purged(): void
    {
        $shared = $this->photo('trashed');
        $live = $this->room('Lost in Space', [$shared]);
        $trashed = $this->room('Lost in Space (old)', [$shared]);
        $trashed->delete();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/packages/{$live->id}", ['image' => [$this->photo('fresh')]])
            ->assertOk();
        $this->assertFileExists(storage_path('app/public/' . $shared), 'a deleted package can be restored, so its photo stays');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/packages/{$trashed->id}/force")
            ->assertOk();
        $this->assertFileDoesNotExist(storage_path('app/public/' . $shared));
    }

    public function test_force_deleting_a_package_keeps_a_photo_another_package_uses(): void
    {
        $shared = $this->photo('kept');
        $doomed = $this->room('Temple Run', [$shared]);
        $this->room('Temple Run (copy)', [$shared]);

        $doomed->delete();
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/packages/{$doomed->id}/force")
            ->assertOk();

        $this->assertFileExists(storage_path('app/public/' . $shared));
    }

    public function test_inline_remote_and_unsafe_images_never_reach_the_kiosk(): void
    {
        $inline = $this->room('Inline', null);
        $remote = $this->room('Remote', null);
        $unsafe = $this->room('Unsafe', null);
        $none = $this->room('No Photo', null);

        DB::table('packages')->where('id', $inline->id)->update(['image' => json_encode(['data:image/png;base64,iVBORw0KGgo='])]);
        DB::table('packages')->where('id', $remote->id)->update(['image' => json_encode(['https://cdn.example.test/room.jpg'])]);
        DB::table('packages')->where('id', $unsafe->id)->update(['image' => json_encode(['images/../../.env'])]);

        $previewsBefore = Storage::disk(PreviewImage::DISK)->files(PreviewImage::DIRECTORY);
        $rooms = $this->kioskRooms();

        $this->assertNull($rooms[$inline->id]['image']);
        $this->assertNull($rooms[$inline->id]['image_preview']);
        $this->assertSame('https://cdn.example.test/room.jpg', $rooms[$remote->id]['image']);
        $this->assertNull($rooms[$remote->id]['image_preview']);
        $this->assertNull($rooms[$unsafe->id]['image']);
        $this->assertNull($rooms[$unsafe->id]['image_preview']);
        $this->assertNull($rooms[$none->id]['image']);
        $this->assertStringNotContainsString('base64', $this->getJson("/api/waivers/escape-room/{$this->location->id}")->getContent());
        $this->assertSame($previewsBefore, Storage::disk(PreviewImage::DISK)->files(PreviewImage::DIRECTORY));
    }

    public function test_a_broken_picture_is_marked_once_and_not_retried_on_every_load(): void
    {
        $broken = $this->photo('broken', bytes: 'this is not an image');
        $room = $this->room('Zombie Bomb', [$broken]);

        $first = $this->kioskRooms()[$room->id];
        $this->assertSame($broken, $first['image']);
        $this->assertNull($first['image_preview']);
        $this->assertTrue($first['image_pending']);

        $preview = PreviewImage::pathFor($broken);
        $this->assertFalse(Storage::disk(PreviewImage::DISK)->exists($preview));
        $this->assertTrue((bool) Cache::get('preview-image-failed:' . sha1($preview)));
        $this->assertFalse(PreviewImage::forDisplay([$broken])['pending']);

        $second = $this->kioskRooms()[$room->id];
        $this->assertSame($broken, $second['image']);
        $this->assertNull($second['image_preview']);
        $this->assertFalse($second['image_pending'], 'a picture that cannot be shrunk is shown as it is instead of waiting forever');
    }

    public function test_a_signed_game_link_room_also_carries_its_photo(): void
    {
        $photo = $this->photo('linked');
        $room = $this->room("Dragon's Heart", [$photo]);
        $this->room('Another Room', [$this->photo('other')]);
        $date = Carbon::parse(self::TODAY)->addDays(2)->toDateString();

        Booking::create([
            'reference_number' => 'ERIMG' . $room->id,
            'booking_date' => $date,
            'booking_time' => '15:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'package_id' => $room->id,
            'participants' => 4,
            'total_amount' => 120,
            'status' => 'confirmed',
            'guest_name' => 'Jordan Rivera',
            'guest_email' => 'jordan@example.test',
            'guest_phone' => '2485550100',
        ]);

        $sig = app(EscapeRoomSessionService::class)->gameLinkSignature($this->location->id, $room->id, $date, '15:00');
        $rooms = $this->kioskRooms(['date' => $date, 'room' => $room->id, 'time' => '15:00', 'sig' => $sig]);

        $this->assertSame([$room->id], array_keys($rooms));
        $this->assertSame($photo, $rooms[$room->id]['image']);
        $this->assertTrue($this->previewExists($photo));

        $again = $this->kioskRooms(['date' => $date, 'room' => $room->id, 'time' => '15:00', 'sig' => $sig]);
        $this->assertSame(PreviewImage::pathFor($photo), $again[$room->id]['image_preview']);
    }

    public function test_at_most_six_previews_are_made_per_load(): void
    {
        $photos = [];
        foreach (range(1, 7) as $index) {
            $photos[$index] = $this->photo('batch' . $index, 640, 360);
            $room = $this->room('Room ' . $index, null);
            DB::table('packages')->where('id', $room->id)->update(['image' => json_encode([$photos[$index]])]);
        }

        $this->kioskRooms();
        $made = collect($photos)->filter(fn ($photo) => $this->previewExists($photo))->count();
        $this->assertSame(PreviewImage::MAX_PER_REQUEST, $made);

        $this->kioskRooms();
        $this->assertSame(7, collect($photos)->filter(fn ($photo) => $this->previewExists($photo))->count());
    }

    public function test_the_per_request_cap_covers_every_save_in_that_request(): void
    {
        $photos = [];
        foreach (range(1, 8) as $index) {
            $photos[$index] = $this->photo('saved' . $index, 640, 360);
            $this->room('Saved Room ' . $index, [$photos[$index]]);
        }

        $this->kioskRooms();
        $this->assertSame(PreviewImage::MAX_PER_REQUEST, collect($photos)->filter(fn ($photo) => $this->previewExists($photo))->count());

        $this->kioskRooms();
        $this->assertSame(8, collect($photos)->filter(fn ($photo) => $this->previewExists($photo))->count());
    }

    public function test_replacing_a_photo_never_deletes_files_outside_the_package_photo_folder(): void
    {
        $foreign = 'images/attractions/not-a-package-' . uniqid() . '.jpg';
        $absolute = storage_path('app/public/' . $foreign);
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, 'attraction-photo-bytes');
        $this->madeFiles[] = $foreign;

        $room = $this->room('Odd Path Room', [$foreign]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/packages/{$room->id}", ['image' => [$this->photo('replacement')]])
            ->assertOk();

        $this->assertFileExists($absolute, 'only files in images/packages may be deleted by a package edit');
    }

    public function test_a_previews_folder_that_cannot_be_written_is_marked_instead_of_retried(): void
    {
        $root = sys_get_temp_dir() . '/preview-disk-' . uniqid();
        mkdir($root . '/images/packages', 0755, true);
        $canvas = imagecreatetruecolor(640, 360);
        imagejpeg($canvas, $root . '/images/packages/room.jpg', 90);
        file_put_contents($root . '/images/previews', 'not a directory');

        config(['filesystems.disks.public.root' => $root]);
        Storage::forgetDisk('public');

        try {
            $this->assertTrue(PreviewImage::forDisplay(['images/packages/room.jpg'])['pending']);
            $this->assertNull(PreviewImage::generate('images/packages/room.jpg'));
            $this->assertFalse(PreviewImage::forDisplay(['images/packages/room.jpg'])['pending'], 'a folder that cannot be written is not retried on every load');
        } finally {
            @unlink($root . '/images/previews');
            @unlink($root . '/images/packages/room.jpg');
            @rmdir($root . '/images/packages');
            @rmdir($root . '/images');
            @rmdir($root);
            Storage::forgetDisk('public');
        }
    }

    public function test_a_large_original_is_never_sent_to_the_kiosk(): void
    {
        config(['media.preview_fallback_max_bytes' => 1000]);
        $photo = $this->photo('large');
        $broken = $this->photo('large-broken', bytes: str_repeat('not an image ', 200));
        $room = $this->room('Large Photo Room', [$photo]);
        $brokenRoom = $this->room('Large Broken Room', [$broken]);

        $first = $this->kioskRooms();
        $this->assertNull($first[$room->id]['image'], 'a photo over the limit is not offered as a fallback');
        $this->assertNull($first[$room->id]['image_preview']);
        $this->assertTrue($first[$room->id]['image_pending']);

        $second = $this->kioskRooms();
        $this->assertNull($second[$room->id]['image']);
        $this->assertSame(PreviewImage::pathFor($photo), $second[$room->id]['image_preview']);
        $this->assertNull($second[$brokenRoom->id]['image'], 'a large file that cannot be shrunk shows the placeholder, not the original');
        $this->assertNull($second[$brokenRoom->id]['image_preview']);
        $this->assertFalse($second[$brokenRoom->id]['image_pending']);
    }

    public function test_a_memory_refusal_is_retried_on_a_later_request(): void
    {
        $photo = $this->photo('tight-memory', 640, 360);
        $limit = ini_get('memory_limit');

        ini_set('memory_limit', (string) (memory_get_usage(true) + 6 * 1048576));
        try {
            $this->assertNull(PreviewImage::generate($photo));
        } finally {
            ini_set('memory_limit', $limit);
        }

        $this->assertTrue(PreviewImage::forDisplay([$photo])['pending'], 'running short of memory once does not block the photo for hours');
        $this->assertSame(PreviewImage::pathFor($photo), PreviewImage::generate($photo));
    }

    public function test_only_one_worker_makes_previews_at_a_time(): void
    {
        $photo = $this->photo('runner');
        $room = $this->room('Runner Room', null);
        DB::table('packages')->where('id', $room->id)->update(['image' => json_encode([$photo])]);

        $busy = Cache::lock('preview-image:runner', 300);
        $this->assertTrue($busy->get());
        try {
            $this->kioskRooms();
            $this->assertFalse($this->previewExists($photo), 'another worker is already making previews');
        } finally {
            $busy->release();
        }

        $this->kioskRooms();
        $this->assertTrue($this->previewExists($photo));
    }

    public function test_photos_that_already_have_previews_do_not_use_up_the_cap(): void
    {
        $done = [];
        foreach (range(1, 6) as $index) {
            $done[$index] = $this->photo('done' . $index, 640, 360);
            $this->assertNotNull(PreviewImage::generate($done[$index]));
        }

        $fresh = $this->photo('fresh', 640, 360);
        $room = $this->room('Fresh Room', null);
        DB::table('packages')->where('id', $room->id)->update(['image' => json_encode([$fresh])]);

        PreviewImage::generateLater(array_values($done));
        $this->kioskRooms();

        $this->assertTrue($this->previewExists($fresh), 'finished photos are skipped before the per-request cap is applied');
    }

    public function test_preview_paths_never_escape_the_public_disk(): void
    {
        $this->assertNull(PreviewImage::pathFor('../../.env'));
        $this->assertNull(PreviewImage::pathFor('/etc/passwd'));
        $this->assertNull(PreviewImage::pathFor('images\\packages\\x.jpg'));
        $this->assertNull(PreviewImage::pathFor('https://cdn.example.test/room.jpg'));
        $this->assertNull(PreviewImage::generate('images/../../.env'));
        $this->assertNull(PreviewImage::primary(['images/../../.env']));
        $this->assertSame('images/packages/a b.jpg', PreviewImage::primary(['images/packages/a b.jpg']));
    }
}
