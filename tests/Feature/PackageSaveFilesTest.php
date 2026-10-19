<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class PackageSaveFilesTest extends TestCase
{
    use RefreshDatabase;

    private Package $party;

    private User $manager;

    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $company = Company::create([
            'company_name' => 'Save Files Co',
            'email' => 'owner@savefiles.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Waterford | Zap Zone',
            'address' => '1 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@savefiles.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'first_name' => 'Desk',
            'last_name' => 'Manager',
            'email' => 'manager@savefiles.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $this->party = Package::create([
            'location_id' => $location->id,
            'name' => 'Waterford Party',
            'description' => 'Two hours of fun',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $relative) {
            @unlink(storage_path('app/public/' . $relative));
        }

        parent::tearDown();
    }

    private function storedFile(string $folder, string $extension, string $contents): string
    {
        $relative = $folder . '/save-files-' . uniqid() . '.' . $extension;
        $absolute = storage_path('app/public/' . $relative);
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, $contents);
        $this->made[] = $relative;

        return $relative;
    }

    private function photoData(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));

        return 'data:image/png;base64,' . base64_encode(ob_get_clean());
    }

    private function filesIn(string $folder): array
    {
        $absolute = storage_path('app/public/' . $folder);

        return is_dir($absolute) ? array_values(array_diff(scandir($absolute), ['.', '..'])) : [];
    }

    private function asManager(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->manager->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    public function test_a_save_that_fails_keeps_the_old_photo_and_leaves_no_new_one(): void
    {
        $old = $this->storedFile('images/packages', 'png', 'old photo');
        $this->party->update(['image' => [$old]]);
        $before = $this->filesIn('images/packages');

        try {
            $this->asManager()
                ->putJson("/api/packages/{$this->party->id}", ['image' => $this->photoData(), 'price' => 100000000])
                ->assertStatus(500);

            $this->assertFileExists(storage_path('app/public/' . $old), 'the photo the package still shows is kept');
            $this->assertSame([$old], $this->party->fresh()->image);
            $this->assertSame($before, $this->filesIn('images/packages'), 'the new photo from the failed save is removed');
        } finally {
            foreach (array_diff($this->filesIn('images/packages'), $before) as $leftover) {
                @unlink(storage_path('app/public/images/packages/' . $leftover));
            }
        }
    }

    public function test_a_save_that_fails_after_the_row_is_written_keeps_the_photo_the_row_shows(): void
    {
        $old = $this->storedFile('images/packages', 'png', 'old photo');
        $this->party->update(['image' => [$old]]);
        Package::updated(function () {
            throw new \RuntimeException('a follow-up step failed');
        });

        $this->asManager()
            ->putJson("/api/packages/{$this->party->id}", ['image' => $this->photoData()])
            ->assertStatus(500);

        $shown = Package::query()->whereKey($this->party->id)->value('image');
        $shown = is_string($shown) ? json_decode($shown, true) : $shown;
        $this->made[] = $shown[0];
        $this->assertNotSame($old, $shown[0], 'the row already points at the new photo');
        $this->assertFileExists(storage_path('app/public/' . $shown[0]), 'so the new photo is kept');
    }

    public function test_a_normal_photo_change_still_replaces_the_old_photo(): void
    {
        $old = $this->storedFile('images/packages', 'png', 'old photo');
        $this->party->update(['image' => [$old]]);

        $this->asManager()
            ->putJson("/api/packages/{$this->party->id}", ['image' => $this->photoData()])
            ->assertOk();

        $new = $this->party->fresh()->image[0];
        $this->made[] = $new;
        $this->assertNotSame($old, $new);
        $this->assertFileExists(storage_path('app/public/' . $new));
        $this->assertFileDoesNotExist(storage_path('app/public/' . $old));
    }

    public function test_saving_a_package_keeps_its_invitation_file(): void
    {
        $invitation = $this->storedFile('invitations', 'pdf', '%PDF-1.4 party invitation');
        $this->party->update(['invitation_file' => $invitation]);

        $this->asManager()
            ->putJson("/api/packages/{$this->party->id}", ['name' => 'Waterford Party Deluxe', 'invitation_file' => $invitation])
            ->assertOk();

        $this->assertFileExists(storage_path('app/public/' . $invitation), 'an unchanged invitation is not deleted on save');
        $this->assertSame($invitation, $this->party->fresh()->invitation_file);
    }

    public function test_an_invitation_can_only_be_an_uploaded_file(): void
    {
        $other = $this->storedFile('invitations', 'pdf', '%PDF-1.4 another package');

        foreach (['../../../.env', 'invitations/../../../.env', $other, 'https://169.254.169.254/latest/meta-data'] as $planted) {
            $this->asManager()
                ->putJson("/api/packages/{$this->party->id}", ['invitation_file' => $planted])
                ->assertOk();

            $this->assertNull($this->party->fresh()->invitation_file, "{$planted} is not kept as the invitation");
        }
    }

    public function test_replacing_an_invitation_never_deletes_a_shared_or_outside_file(): void
    {
        $shared = $this->storedFile('invitations', 'pdf', '%PDF-1.4 shared invitation');
        $this->party->update(['invitation_file' => $shared]);
        $sibling = $this->party->replicate(['invitation_file']);
        $sibling->name = 'Waterford Party Twin';
        $sibling->invitation_file = $shared;
        $sibling->save();

        $this->asManager()
            ->putJson("/api/packages/{$this->party->id}", ['invitation_file' => 'data:application/pdf;base64,' . base64_encode('%PDF-1.4 new invitation')])
            ->assertOk();
        $this->made[] = $this->party->fresh()->invitation_file;
        $this->assertFileExists(storage_path('app/public/' . $shared), 'another package still sends this invitation');

        $outside = 'save-files-outside-' . uniqid() . '.txt';
        file_put_contents(storage_path('app/public/' . $outside), 'not an invitation');
        $this->made[] = $outside;
        Package::whereKey($this->party->id)->update(['invitation_file' => '../' . $outside]);

        $this->asManager()
            ->putJson("/api/packages/{$this->party->id}", ['invitation_file' => 'data:application/pdf;base64,' . base64_encode('%PDF-1.4 newer invitation')])
            ->assertOk();
        $this->made[] = $this->party->fresh()->invitation_file;
        $this->assertFileExists(storage_path('app/public/' . $outside), 'a file outside the invitations folder is never deleted');
    }

    public function test_booking_emails_attach_only_invitations_kept_in_the_invitations_folder(): void
    {
        $inside = $this->storedFile('invitations', 'pdf', '%PDF-1.4 party invitation');
        $outside = 'save-files-outside-' . uniqid() . '.txt';
        file_put_contents(storage_path('app/public/' . $outside), 'secret');
        $this->made[] = $outside;
        $link = 'invitations/save-files-link-' . uniqid() . '.pdf';
        symlink(storage_path('app/public/' . $outside), storage_path('app/public/' . $link));
        $this->made[] = $link;

        $this->assertSame(realpath(storage_path('app/public/' . $inside)), Package::storedInvitationPath($inside));
        foreach (['../' . $outside, 'invitations/../' . $outside, '../../../.env', $link, 'invitations/missing.pdf', 'https://example.com/a.pdf', null] as $unsafe) {
            $this->assertNull(Package::storedInvitationPath($unsafe), var_export($unsafe, true) . ' is not attached');
        }

        $attached = function (?string $invitation) {
            $this->party->forceFill(['invitation_file' => $invitation])->save();
            $booking = new \App\Models\Booking(['package_id' => $this->party->id]);
            $booking->setRelation('package', $this->party->fresh());

            return (new \App\Mail\BookingConfirmation($booking))->build()->attachments;
        };

        $this->assertCount(1, $attached($inside));
        $this->assertSame([], $attached($outside), 'a file outside the invitations folder never reaches a guest email');
        $this->assertSame([], $attached('invitations/../' . $outside), 'nor does a path that climbs out of it');
        $this->assertSame([], $attached('https://169.254.169.254/latest/meta-data'), 'the server never fetches an address to attach');

        $notifications = new \App\Services\EmailNotificationService();
        $invitations = (new \ReflectionClass(\App\Services\InvitationService::class))->newInstanceWithoutConstructor();
        $viaServices = function (?string $invitation) use ($notifications, $invitations) {
            $this->party->forceFill(['invitation_file' => $invitation])->save();
            $booking = new \App\Models\Booking(['package_id' => $this->party->id]);
            $booking->setRelation('package', $this->party->fresh());

            return [
                count((new \ReflectionMethod($notifications, 'buildAttachments'))->invoke($notifications, $booking, 'booking', false)),
                count((new \ReflectionMethod($invitations, 'getInvitationAttachments'))->invoke($invitations, $booking)),
            ];
        };

        $this->assertSame([1, 1], $viaServices($inside), 'both other senders attach the real invitation');
        foreach ([$outside, 'invitations/../' . $outside, $link, 'https://169.254.169.254/latest/meta-data'] as $unsafe) {
            $this->assertSame([0, 0], $viaServices($unsafe), "{$unsafe} is never attached by the other senders");
        }
    }

    public function test_a_new_invitation_file_replaces_the_old_one_after_the_save(): void
    {
        $invitation = $this->storedFile('invitations', 'pdf', '%PDF-1.4 old invitation');
        $this->party->update(['invitation_file' => $invitation]);

        $this->asManager()
            ->putJson("/api/packages/{$this->party->id}", ['invitation_file' => 'data:application/pdf;base64,' . base64_encode('%PDF-1.4 new invitation')])
            ->assertOk();

        $replacement = $this->party->fresh()->invitation_file;
        $this->made[] = $replacement;
        $this->assertNotSame($invitation, $replacement);
        $this->assertFileExists(storage_path('app/public/' . $replacement));
        $this->assertFileDoesNotExist(storage_path('app/public/' . $invitation));
    }
}
