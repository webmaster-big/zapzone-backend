<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\EmailNotificationLog;
use App\Models\EscapeRoomSession;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\FollowUpOptOut;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Photo;
use App\Models\PhotoSession;
use App\Models\Promo;
use App\Models\User;
use App\Models\VisitFollowUp;
use App\Models\Waiver;
use App\Models\WaiverTemplate;
use App\Services\VisitFollowUpService;
use App\Services\WaiverService;
use App\Support\OperatingDay;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisitFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-03';

    private Company $company;

    private Location $location;

    private Location $otherLocation;

    private User $admin;

    private User $attendant;

    private User $otherAttendant;

    private Package $morgue;

    private Package $party;

    private Package $otherParty;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'app.frontend_url' => 'https://zapzone.test']);
        Storage::fake(config('filesystems.default'));
        Storage::fake('photos');
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse(self::TODAY . ' 13:30:00', 'America/Detroit'));

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = $this->makeLocation('Waterford');
        $this->otherLocation = $this->makeLocation('Brighton');

        $this->admin = $this->makeUser('company_admin', $this->location, 'admin');
        $this->attendant = $this->makeUser('attendant', $this->location, 'attendant');
        $this->otherAttendant = $this->makeUser('attendant', $this->otherLocation, 'brighton');

        $this->morgue = $this->makePackage('The Morgue', $this->location, true);
        $this->party = $this->makePackage('Birthday Party', $this->location, false);
        $this->otherParty = $this->makePackage('Brighton Party', $this->otherLocation, false);

        $general = $this->makeTemplate('General Activity Waiver', WaiverTemplate::KIND_STANDARD, true, [$this->party->id]);
        $this->makeTemplate('Escape Room Waiver', WaiverTemplate::KIND_ESCAPE_ROOM, true);
        $this->assertNotNull($general);

        DefaultEmailNotificationSeeder::seedForCompany($this->company);
    }

    private function makeLocation(string $name): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '1 Test Way',
            'city' => $name,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($name) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makeUser(string $role, Location $location, string $key): User
    {
        return User::create([
            'first_name' => ucfirst($key),
            'last_name' => 'Staff',
            'email' => $key . '@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);
    }

    private function makePackage(string $name, Location $location, bool $escapeRoom): Package
    {
        $package = Package::create([
            'location_id' => $location->id,
            'name' => $name,
            'description' => 'Test package',
            'category' => 'Adventure',
            'price' => 30,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 10,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
            'is_escape_room' => $escapeRoom,
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

    private function makeTemplate(string $title, string $kind, bool $default, array $packageIds = []): WaiverTemplate
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => $title,
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => $default,
            'body_text' => '<p>I release {{company_name}} for {{activity_name}}.</p>',
            'kind' => $kind,
            'assigned_package_ids' => $packageIds,
            'electronic_consent_enabled' => true,
        ]);

        app(WaiverService::class)->syncVersion($template);

        return $template->fresh();
    }

    private function makeBooking(Package $package, string $email = 'jordan@example.test', string $status = 'checked-in', string $time = '12:00', ?string $date = null): Booking
    {
        static $counter = 0;
        $counter++;

        return Booking::create([
            'reference_number' => 'FU' . $counter . $package->id,
            'booking_date' => $date ?? now('America/Detroit')->toDateString(),
            'booking_time' => $time,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $package->location_id,
            'package_id' => $package->id,
            'participants' => 4,
            'total_amount' => 120,
            'status' => $status,
            'guest_name' => 'Jordan Rivera',
            'guest_email' => $email,
            'guest_phone' => '2485550100',
        ]);
    }

    private function completeBooking(Booking $booking, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['status' => 'completed', 'change_reason' => 'The group finished their visit']);
    }

    private function signed(Package $room, string $time, string $first, string $email): Waiver
    {
        $response = $this->postJson("/api/waivers/escape-room/{$room->location_id}/submit", [
            'adult_first_name' => $first,
            'adult_last_name' => 'Player',
            'adult_email' => $email,
            'adult_phone' => '(248) 555-0142',
            'adult_dob' => '1990-05-05',
            'typed_legal_name' => $first . ' Player',
            'agreement_accepted' => true,
            'electronic_consent_accepted' => true,
            'package_id' => $room->id,
            'session_time' => $time,
        ]);

        $response->assertCreated();

        return Waiver::findOrFail($response->json('data.id'));
    }

    private function openGame(Package $room, string $time): array
    {
        return $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/escape-rooms/sessions', [
                'location_id' => $room->location_id,
                'package_id' => $room->id,
                'date' => self::TODAY,
                'time' => $time,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function withGroupPhoto(int $sessionId): PhotoSession
    {
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$sessionId}/photo-session", ['verbal_consent' => true])
            ->assertOk();

        $photoSession = PhotoSession::findOrFail(EscapeRoomSession::findOrFail($sessionId)->photo_session_id);
        Storage::disk('photos')->put('x/' . $photoSession->id . '/delivery.jpg', $this->jpeg());

        Photo::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $photoSession->company_id,
            'location_id' => $photoSession->location_id,
            'position' => 1,
            'source' => Photo::SOURCE_CAMERA,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/' . $photoSession->id . '/original.jpg',
            'delivery_path' => 'x/' . $photoSession->id . '/delivery.jpg',
            'slideshow_path' => 'x/' . $photoSession->id . '/slideshow.jpg',
            'thumbnail_path' => 'x/' . $photoSession->id . '/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        return $photoSession;
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(1600, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 64, 175));
        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return ob_get_clean();
    }

    private function completeGame(int $sessionId, array $payload = [])
    {
        return $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$sessionId}/complete", $payload + [
                'escaped' => true,
                'completion_time' => '47:12',
            ]);
    }

    private function emails(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }

    private function emailsTo(string $address): array
    {
        return array_values(array_filter(
            $this->emails(),
            fn ($email) => $email->getTo()[0]->getAddress() === $address && !str_starts_with((string) $email->getSubject(), 'Waiver Completed')
        ));
    }

    private function followUpEmails(): array
    {
        return array_values(array_filter($this->emails(), fn ($email) => !str_starts_with((string) $email->getSubject(), 'Waiver Completed')));
    }

    private function clearEmails(): void
    {
        app('mail.manager')->mailer('array')->getSymfonyTransport()->flush();
    }

    private function thanks(): EmailNotification
    {
        return EmailNotification::where('company_id', $this->company->id)
            ->where('default_key', EmailNotification::DEFAULT_THANKS_FOR_PLAYING)
            ->firstOrFail();
    }

    private function review(): EmailNotification
    {
        return EmailNotification::where('company_id', $this->company->id)
            ->where('default_key', EmailNotification::DEFAULT_REVIEW_REQUEST)
            ->firstOrFail();
    }

    private function makePromo(array $overrides = []): Promo
    {
        static $counter = 0;
        $counter++;

        return Promo::create(array_merge([
            'code' => 'COMEBACK' . $counter,
            'code_mode' => 'single',
            'name' => 'Come back soon',
            'type' => 'percentage',
            'value' => 20,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'usage_limit_per_user' => 1,
            'current_usage' => 0,
            'status' => 'active',
            'description' => 'Good for any package on your next visit',
            'created_by' => $this->admin->id,
            'deleted' => false,
        ], $overrides));
    }

    private function choosePromo(?Promo $promo, ?EmailNotification $notification = null)
    {
        $notification ??= $this->thanks();

        return $this->actingAs($this->admin, 'sanctum')->putJson("/api/email-notifications/{$notification->id}", [
            'promo_id' => $promo?->id,
        ]);
    }

    private function reviewRows(): \Illuminate\Support\Collection
    {
        return VisitFollowUp::where('kind', VisitFollowUp::KIND_REVIEW)->orderBy('id')->get();
    }

    public function test_the_two_follow_up_emails_are_seeded_for_every_company_and_name_no_brand(): void
    {
        $thanks = $this->thanks();
        $review = $this->review();

        $this->assertTrue($thanks->is_active);
        $this->assertTrue($review->is_active);
        $this->assertSame(EmailNotification::TRIGGER_VISIT_COMPLETED, $thanks->trigger_type);
        $this->assertSame(EmailNotification::TRIGGER_VISIT_FOLLOWUP, $review->trigger_type);
        $this->assertSame(EmailNotification::ENTITY_ALL, $thanks->entity_type);
        $this->assertNull($thanks->location_id);
        $this->assertSame([EmailNotification::RECIPIENT_CUSTOMER], $review->recipient_types);
        $this->assertSame(24, $review->send_after_hours);

        foreach ([$thanks, $review] as $notification) {
            foreach (['Zap Zone', 'Escape Room Zone', 'Best In Games', 'Waterford'] as $brand) {
                $this->assertStringNotContainsString($brand, $notification->getEffectiveSubject() . $notification->getEffectiveBody());
            }
        }

        $this->assertStringContainsString('{{promo_section}}', $thanks->getEffectiveBody());
        $this->assertStringContainsString('{{group_photo_section}}', $thanks->getEffectiveBody());
        $this->assertStringContainsString('{{game_result_section}}', $thanks->getEffectiveBody());
        $this->assertStringContainsString('{{rating_section}}', $review->getEffectiveBody());
    }

    public function test_marking_a_party_booking_completed_emails_the_booker_and_schedules_the_review(): void
    {
        $booking = $this->makeBooking($this->party);

        $response = $this->completeBooking($booking)->assertOk()
            ->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_SENT)
            ->assertJsonPath('follow_up.reviews.0.status', VisitFollowUp::STATUS_SCHEDULED)
            ->assertJsonPath('follow_up.handled_by_game', false);

        $sent = $this->emailsTo('jordan@example.test');
        $this->assertCount(1, $sent);
        $this->assertSame('Thanks for playing Birthday Party!', $sent[0]->getSubject());
        $html = $sent[0]->getHtmlBody();
        $this->assertStringContainsString('Hi Jordan,', $html);
        $this->assertStringContainsString('October 3, 2026 at 12:00 PM', $html);
        $this->assertStringNotContainsString('Your result', $html);
        $this->assertStringNotContainsString('Use code', $html);
        $this->assertStringNotContainsString('{{', $html);

        $due = Carbon::parse($response->json('follow_up.reviews.0.due_at'))->setTimezone('America/Detroit');
        $this->assertSame('2026-10-04 13:30', $due->format('Y-m-d H:i'));

        $this->assertSame(1, EmailNotificationLog::where('email_notification_id', $this->thanks()->id)->where('status', 'sent')->count());
    }

    public function test_the_thanks_email_carries_the_chosen_promo_and_a_new_promo_needs_no_code_change(): void
    {
        $first = $this->makePromo(['code' => 'THANKS20']);
        $second = $this->makePromo(['code' => 'NEXTTIME10', 'type' => 'fixed', 'value' => 10, 'end_date' => '2027-06-30']);

        $this->choosePromo($first)->assertOk();
        $this->completeBooking($this->makeBooking($this->party, 'first@example.test'))->assertOk();

        $html = $this->emailsTo('first@example.test')[0]->getHtmlBody();
        $this->assertStringContainsString('THANKS20', $html);
        $this->assertStringContainsString('20% off', $html);
        $this->assertStringContainsString('Valid until December 31, 2026', $html);
        $this->assertStringContainsString('Good for any package on your next visit', $html);

        $this->choosePromo($second)->assertOk();
        $this->completeBooking($this->makeBooking($this->party, 'second@example.test'))->assertOk();

        $html = $this->emailsTo('second@example.test')[0]->getHtmlBody();
        $this->assertStringContainsString('NEXTTIME10', $html);
        $this->assertStringContainsString('$10 off', $html);
        $this->assertStringNotContainsString('THANKS20', $html);

        $this->choosePromo(null)->assertOk();
        $this->completeBooking($this->makeBooking($this->party, 'third@example.test'))->assertOk();
        $this->assertStringNotContainsString('Use code', $this->emailsTo('third@example.test')[0]->getHtmlBody());
    }

    public function test_a_promo_that_cannot_be_used_is_left_out_rather_than_sending_a_dead_code(): void
    {
        $expired = $this->makePromo(['code' => 'OLDCODE', 'end_date' => '2026-10-01']);
        $this->choosePromo($expired)->assertOk();

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/email-notifications/{$this->thanks()->id}")
            ->assertOk()
            ->assertJsonPath('data.promo_summary.code', 'OLDCODE');
        $this->assertStringContainsString('expired', (string) $this->actingAs($this->admin, 'sanctum')->getJson("/api/email-notifications/{$this->thanks()->id}")->json('data.promo_summary.problem'));

        $this->completeBooking($this->makeBooking($this->party, 'expired@example.test'))->assertOk();
        $this->assertStringNotContainsString('OLDCODE', $this->emailsTo('expired@example.test')[0]->getHtmlBody());

        $brightonOnly = $this->makePromo(['code' => 'BRIGHTONONLY', 'location_ids' => [$this->otherLocation->id]]);
        $this->choosePromo($brightonOnly)->assertOk();

        $this->completeBooking($this->makeBooking($this->party, 'waterford@example.test'))->assertOk();
        $this->completeBooking($this->makeBooking($this->otherParty, 'brighton@example.test'), $this->otherAttendant)->assertOk();

        $this->assertStringNotContainsString('BRIGHTONONLY', $this->emailsTo('waterford@example.test')[0]->getHtmlBody());
        $this->assertStringContainsString('BRIGHTONONLY', $this->emailsTo('brighton@example.test')[0]->getHtmlBody());
    }

    public function test_batch_codes_and_other_emails_cannot_take_a_promo(): void
    {
        $batch = $this->makePromo(['code' => 'ONEUSE-1', 'code_mode' => 'unique', 'batch_id' => '11111111-2222-3333-4444-555555555555']);

        $this->choosePromo($batch)->assertStatus(422)->assertJsonValidationErrors(['promo_id']);

        $confirmation = EmailNotification::where('company_id', $this->company->id)
            ->where('default_key', EmailNotification::DEFAULT_BOOKING_CONFIRMATION_CUSTOMER)
            ->firstOrFail();

        $this->choosePromo($this->makePromo(), $confirmation)->assertStatus(422)->assertJsonValidationErrors(['promo_id']);
        $this->choosePromo($this->makePromo(), $this->review())->assertStatus(422)->assertJsonValidationErrors(['promo_id']);

        $otherCompany = Company::create(['company_name' => 'Other Co', 'email' => 'o@example.test', 'phone' => '1', 'address' => 'x']);
        $otherLocation = Location::create([
            'company_id' => $otherCompany->id, 'name' => 'Elsewhere', 'address' => '1', 'city' => 'X', 'state' => 'MI',
            'zip_code' => '1', 'phone' => '1', 'email' => 'elsewhere@example.test', 'timezone' => 'America/Detroit', 'is_active' => true,
        ]);
        $otherAdmin = User::create([
            'first_name' => 'Other', 'last_name' => 'Admin', 'email' => 'other-admin@example.test', 'password' => bcrypt('x'),
            'role' => 'company_admin', 'company_id' => $otherCompany->id, 'location_id' => $otherLocation->id,
        ]);
        $foreign = $this->makePromo(['code' => 'FOREIGN', 'created_by' => $otherAdmin->id]);

        $this->choosePromo($foreign)->assertStatus(422)->assertJsonValidationErrors(['promo_id']);
    }

    public function test_completing_again_or_reopening_never_resends_and_the_waiting_review_follows(): void
    {
        $booking = $this->makeBooking($this->party);

        $this->completeBooking($booking)->assertOk();
        $this->assertCount(1, $this->emailsTo('jordan@example.test'));
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $this->reviewRows()->first()->status);

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['status' => 'checked-in', 'change_reason' => 'Marked complete by mistake'])
            ->assertOk();

        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $this->reviewRows()->first()->status);

        $this->travel(2)->hours();
        $this->completeBooking($booking)->assertOk();

        $this->assertCount(1, $this->emailsTo('jordan@example.test'));
        $this->assertCount(1, $this->reviewRows());
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $this->reviewRows()->first()->status);
        $this->assertSame(1, VisitFollowUp::where('kind', VisitFollowUp::KIND_THANKS)->count());
    }

    public function test_only_a_staff_status_change_sends_anything(): void
    {
        $booking = $this->makeBooking($this->party, 'imported@example.test', 'completed');
        $booking->update(['status' => 'checked-in']);
        $booking->update(['status' => 'completed']);

        $this->assertSame([], $this->emails());
        $this->assertSame(0, VisitFollowUp::count());
    }

    public function test_an_escape_room_booking_is_left_to_the_game_screen(): void
    {
        $booking = $this->makeBooking($this->morgue, 'booker@example.test', 'checked-in', '14:00');

        $this->completeBooking($booking)->assertOk()
            ->assertJsonPath('follow_up.handled_by_game', true)
            ->assertJsonPath('follow_up.can_send_thanks', false);

        $this->assertSame([], $this->emails());
        $this->assertSame(0, VisitFollowUp::count());

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/visit-follow-ups/send-thanks', ['visit_type' => 'booking', 'visit_id' => $booking->id])
            ->assertStatus(422);
    }

    public function test_complete_and_send_emails_the_players_the_thanks_email_with_photo_time_and_promo(): void
    {
        $this->choosePromo($this->makePromo(['code' => 'ESCAPEAGAIN']))->assertOk();
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->signed($this->morgue, '14:00', 'Blake', 'blake@example.test');
        $this->signed($this->morgue, '14:00', 'Blake2', 'BLAKE@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->completeGame($game['id'])->assertOk();

        $sent = $this->emailsTo('avery@example.test');
        $this->assertCount(1, $sent);
        $this->assertSame('Thanks for playing The Morgue!', $sent[0]->getSubject());
        $html = $sent[0]->getHtmlBody();
        $this->assertStringContainsString('Your group escaped in 47:12!', $html);
        $this->assertStringContainsString('October 3, 2026 at 2:00 PM', $html);
        $this->assertStringContainsString('ESCAPEAGAIN', $html);
        $this->assertStringContainsString('https://zapzone.test/photos/', $html);
        $this->assertMatchesRegularExpression('/<img src="cid:group-photo-\d+-[a-z0-9]+@zapzone"/', $html);

        $parts = collect($sent[0]->getAttachments());
        $this->assertCount(2, $parts);
        $this->assertSame(1, $parts->filter(fn ($part) => $part->getDisposition() === 'inline')->count());

        $this->assertCount(2, $this->followUpEmails());
        $reviews = $this->reviewRows();
        $this->assertCount(2, $reviews);
        $this->assertSame(['avery@example.test', 'blake@example.test'], $reviews->pluck('recipient_email')->sort()->values()->all());
        $this->assertSame($avery->id, $reviews->firstWhere('recipient_email', 'avery@example.test')->waiver_id);
        $this->assertSame(2, EmailNotificationLog::where('notifiable_type', EscapeRoomSession::class)->where('status', 'sent')->count());

        $detail = $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/sessions/{$game['id']}")->assertOk();
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, collect($detail->json('data.players'))->firstWhere('waiver_id', $avery->id)['review']['status']);
        $detail->assertJsonPath('data.follow_up.thanks_email.active', true)
            ->assertJsonPath('data.follow_up.thanks_email.promo.code', 'ESCAPEAGAIN')
            ->assertJsonPath('data.follow_up.reviews.scheduled', 2);
    }

    public function test_switching_the_thanks_email_off_stops_the_photo_send_but_not_the_result(): void
    {
        $this->thanks()->update(['is_active' => false]);
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $detail = $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/sessions/{$game['id']}")->assertOk();
        $this->assertFalse($detail->json('data.can_complete'));
        $this->assertContains('The Thanks for Playing email is switched off in Email Notifications.', $detail->json('data.blockers'));

        $this->completeGame($game['id'])->assertStatus(422)
            ->assertJsonPath('message', 'The Thanks for Playing email is switched off in Email Notifications, so nothing can be emailed to the players. Switch it on there, or record the result only.');

        $this->assertNull(EscapeRoomSession::find($game['id'])->completed_at);
        $this->assertSame([], $this->followUpEmails());

        $second = $this->openGame($this->morgue, '15:00');
        $this->signed($this->morgue, '15:00', 'Casey', 'casey@example.test');

        $this->completeGame($second['id'], ['without_photo' => true, 'email_players' => true])->assertOk()->assertJsonPath('data.completed', true);

        $this->assertSame([], $this->followUpEmails());
        $this->assertSame(0, VisitFollowUp::where('kind', VisitFollowUp::KIND_THANKS)->count());
        $this->assertSame(['casey@example.test'], $this->reviewRows()->pluck('recipient_email')->all());
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $this->reviewRows()->first()->status);

        $this->review()->update(['is_active' => false]);
        $third = $this->openGame($this->morgue, '16:00');
        $this->signed($this->morgue, '16:00', 'Drew', 'drew@example.test');

        $this->completeGame($third['id'], ['without_photo' => true, 'email_players' => true])->assertStatus(422);
        $this->completeGame($third['id'], ['without_photo' => true])->assertOk()->assertJsonPath('data.completed', true);
        $this->assertSame(1, VisitFollowUp::count());
    }

    public function test_result_only_can_email_the_players_the_thanks_email_without_a_photo(): void
    {
        $casey = $this->signed($this->morgue, '15:00', 'Casey', 'casey@example.test');
        $game = $this->openGame($this->morgue, '15:00');

        $this->completeGame($game['id'], ['without_photo' => true, 'escaped' => false, 'completion_time' => null, 'email_players' => true])
            ->assertOk()
            ->assertJsonPath('data.completed', true);

        $sent = $this->emailsTo('casey@example.test');
        $this->assertCount(1, $sent);
        $html = $sent[0]->getHtmlBody();
        $this->assertStringContainsString("Your group didn&#039;t escape this time. Come back and try again!", $html);
        $this->assertStringNotContainsString('/photos/', $html);
        $this->assertCount(0, $sent[0]->getAttachments());

        $detail = $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/sessions/{$game['id']}")->assertOk();
        $player = collect($detail->json('data.players'))->firstWhere('waiver_id', $casey->id);
        $this->assertSame(VisitFollowUp::STATUS_SENT, $player['thanks_email']['status']);
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $player['review']['status']);
    }

    public function test_the_most_specific_active_copy_wins_and_a_copy_starts_as_an_editable_draft(): void
    {
        $copy = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$this->thanks()->id}/duplicate")
            ->assertOk()
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.is_active', false)
            ->json('data');

        $this->assertStringContainsString('{{promo_section}}', $copy['body']);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/email-notifications/{$copy['id']}", [
            'name' => 'Escape Room Zone thanks',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_PACKAGE,
            'entity_ids' => [$this->morgue->id],
            'subject' => 'Escape Room Zone: thanks for playing {{activity_name}}',
            'body' => $copy['body'],
            'recipient_types' => ['staff'],
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.recipient_types', ['customer']);

        $brighton = EmailNotification::create([
            'company_id' => $this->company->id,
            'location_id' => $this->otherLocation->id,
            'name' => 'Brighton thanks',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'entity_ids' => [],
            'subject' => 'Brighton says thanks',
            'body' => '<p>Hi {{customer_first_name}}</p>',
            'recipient_types' => ['customer'],
            'custom_emails' => [],
            'include_qr_code' => false,
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->assertNotNull($brighton);

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->completeGame($game['id'])->assertOk();

        $this->completeBooking($this->makeBooking($this->party, 'party@example.test'))->assertOk();
        $this->completeBooking($this->makeBooking($this->otherParty, 'brighton@example.test'), $this->otherAttendant)->assertOk();

        $this->assertSame('Escape Room Zone: thanks for playing The Morgue', $this->emailsTo('avery@example.test')[0]->getSubject());
        $this->assertSame('Thanks for playing Birthday Party!', $this->emailsTo('party@example.test')[0]->getSubject());
        $this->assertSame('Brighton says thanks', $this->emailsTo('brighton@example.test')[0]->getSubject());
        $this->assertStringContainsString('Your group escaped in 47:12!', $this->emailsTo('avery@example.test')[0]->getHtmlBody());
    }

    public function test_the_review_request_goes_out_after_the_delay_with_star_links_and_the_review_button(): void
    {
        $this->location->update(['review_url' => 'https://g.page/r/waterford-review']);
        $this->completeBooking($this->makeBooking($this->party))->assertOk();
        $this->clearEmails();

        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame([], $this->emails());

        $this->travelTo(Carbon::parse('2026-10-04 13:45:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $sent = $this->emailsTo('jordan@example.test');
        $this->assertCount(1, $sent);
        $this->assertSame('How was Birthday Party, Jordan?', $sent[0]->getSubject());

        $row = $this->reviewRows()->first();
        $this->assertSame(VisitFollowUp::STATUS_SENT, $row->status);
        $html = $sent[0]->getHtmlBody();

        foreach ([1, 2, 3, 4, 5] as $stars) {
            $this->assertStringContainsString('https://zapzone.test/feedback/' . $row->token . '?rating=' . $stars, $html);
        }

        $this->assertStringContainsString('https://g.page/r/waterford-review', $html);
        $this->assertStringContainsString('Leave a review', $html);
        $this->assertStringContainsString('https://zapzone.test/feedback/' . $row->token . '?unsubscribe=1', $html);
    }

    public function test_the_review_waits_for_daytime_and_a_location_without_a_link_gets_stars_only(): void
    {
        $this->review()->update(['send_after_hours' => 8]);
        $this->completeBooking($this->makeBooking($this->party))->assertOk();

        $due = $this->reviewRows()->first()->due_at->copy()->setTimezone('America/Detroit');
        $this->assertSame('2026-10-04 09:00', $due->format('Y-m-d H:i'));

        $this->travelTo(Carbon::parse('2026-10-04 09:05:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $html = $this->emailsTo('jordan@example.test')[0]->getHtmlBody();
        $this->assertStringContainsString('?rating=5', $html);
        $this->assertStringNotContainsString('Leave a review', $html);
    }

    public function test_a_guest_is_asked_for_a_review_at_most_once_every_thirty_days(): void
    {
        $this->completeBooking($this->makeBooking($this->party, 'regular@example.test'))->assertOk();
        $this->completeBooking($this->makeBooking($this->party, 'regular@example.test'))->assertOk();

        $rows = $this->reviewRows();
        $this->assertSame([VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_SCHEDULED], $rows->pluck('status')->all());

        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::STATUS_SENT, $rows->first()->fresh()->status);
        $this->assertSame(VisitFollowUp::STATUS_SKIPPED, $rows->last()->fresh()->status);
        $this->assertSame(VisitFollowUp::REASON_ASKED_RECENTLY, $rows->last()->fresh()->reason);
        $this->assertCount(1, array_filter($this->emailsTo('regular@example.test'), fn ($email) => str_starts_with((string) $email->getSubject(), 'How was')));

        $this->travelTo(Carbon::parse('2026-10-14 13:00:00', 'America/Detroit'));
        $this->completeBooking($this->makeBooking($this->party, 'regular@example.test'))->assertOk();
        $this->assertSame(VisitFollowUp::STATUS_SKIPPED, $this->reviewRows()->last()->status);
        $this->assertStringContainsString('once every 30 days', $this->reviewRows()->last()->error);

        $this->travelTo(Carbon::parse('2026-11-10 13:00:00', 'America/Detroit'));
        $this->completeBooking($this->makeBooking($this->party, 'regular@example.test'))->assertOk();
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $this->reviewRows()->last()->status);
    }

    public function test_the_feedback_page_records_a_rating_alerts_staff_once_and_unsubscribes(): void
    {
        $this->location->update(['review_url' => 'https://g.page/r/waterford-review']);
        $booking = $this->makeBooking($this->party, 'rater@example.test');
        $this->completeBooking($booking)->assertOk();
        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $row = $this->reviewRows()->first();

        $this->getJson('/api/visit-feedback/not-a-real-token-at-all-000000')->assertNotFound();

        $this->getJson("/api/visit-feedback/{$row->token}")
            ->assertOk()
            ->assertJsonPath('data.activity_name', 'Birthday Party')
            ->assertJsonPath('data.location_name', 'Waterford')
            ->assertJsonPath('data.review_url', 'https://g.page/r/waterford-review')
            ->assertJsonPath('data.rating', null)
            ->assertJsonMissingPath('data.recipient_email');

        $this->postJson("/api/visit-feedback/{$row->token}", ['rating' => 9])->assertStatus(422);
        $this->postJson("/api/visit-feedback/{$row->token}", ['rating' => 2, 'comment' => 'The room was too hot'])
            ->assertOk()
            ->assertJsonPath('data.rating', 2)
            ->assertJsonPath('data.review_url', 'https://g.page/r/waterford-review');

        $alerts = Notification::where('title', 'Low rating from a guest')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($this->location->id, $alerts->first()->location_id);
        $this->assertSame('/bookings/' . $booking->id, $alerts->first()->action_url);
        $this->assertStringContainsString('The room was too hot', $alerts->first()->message);

        $this->postJson("/api/visit-feedback/{$row->token}", ['rating' => 1])->assertOk();
        $this->assertSame(1, Notification::where('title', 'Low rating from a guest')->count());
        $this->assertSame('The room was too hot', $row->fresh()->comment);

        $this->postJson("/api/visit-feedback/{$row->token}/unsubscribe")->assertOk()->assertJsonPath('data.unsubscribed', true);
        $this->assertTrue(FollowUpOptOut::hasOptedOut($this->company->id, 'rater@example.test'));

        $this->travelTo(Carbon::parse('2026-11-20 13:00:00', 'America/Detroit'));
        $this->completeBooking($this->makeBooking($this->party, 'RATER@example.test'))->assertOk();
        $this->assertSame(VisitFollowUp::STATUS_SKIPPED, $this->reviewRows()->last()->status);
        $this->assertCount(1, array_filter($this->emailsTo('rater@example.test'), fn ($email) => str_starts_with((string) $email->getSubject(), 'How was')));
        $this->assertCount(2, array_filter($this->emailsTo('rater@example.test'), fn ($email) => str_starts_with((string) $email->getSubject(), 'Thanks for playing')));
    }

    public function test_staff_see_follow_ups_for_their_own_location_and_can_cancel_or_send_now(): void
    {
        $booking = $this->makeBooking($this->party);
        $this->completeBooking($booking)->assertOk();
        $row = $this->reviewRows()->first();

        $this->actingAs($this->otherAttendant, 'sanctum')
            ->getJson('/api/visit-follow-ups/visit?visit_type=booking&visit_id=' . $booking->id)
            ->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')
            ->postJson("/api/visit-follow-ups/{$row->id}/cancel")
            ->assertForbidden();

        $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/visit-follow-ups/visit?visit_type=booking&visit_id=' . $booking->id)
            ->assertOk()
            ->assertJsonPath('data.thanks_email.active', true)
            ->assertJsonPath('data.review_email.hours', 24)
            ->assertJsonPath('data.reviews.0.status', VisitFollowUp::STATUS_SCHEDULED)
            ->assertJsonMissingPath('data.reviews.0.token');

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$row->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.reviews.0.status', VisitFollowUp::STATUS_CANCELED);
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$row->id}/cancel")->assertStatus(422);

        $this->clearEmails();
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$row->id}/send-now")
            ->assertOk()
            ->assertJsonPath('data.reviews.0.status', VisitFollowUp::STATUS_SENT);
        $this->assertCount(1, $this->emailsTo('jordan@example.test'));

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$row->id}/send-now")->assertStatus(422);

        $customer = \App\Models\Customer::create([
            'first_name' => 'Pat', 'last_name' => 'Guest', 'email' => 'pat@example.test', 'password' => bcrypt('x'),
        ]);
        $token = $customer->createToken('customer')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/visit-follow-ups/visit?visit_type=booking&visit_id=' . $booking->id)
            ->assertForbidden();
    }

    public function test_a_failed_thanks_email_is_retried_and_the_log_resend_goes_through_the_follow_up(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        app('mail.manager')->forgetMailers();

        $booking = $this->makeBooking($this->party);
        $this->completeBooking($booking)->assertOk()->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_FAILED);

        $row = VisitFollowUp::where('kind', VisitFollowUp::KIND_THANKS)->firstOrFail();
        $this->assertSame(1, $row->attempts);
        $log = EmailNotificationLog::where('email_notification_id', $this->thanks()->id)->firstOrFail();
        $this->assertSame('failed', $log->status);

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();

        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::STATUS_FAILED, $row->fresh()->status);

        $this->travel(11)->minutes();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::STATUS_SENT, $row->fresh()->status);
        $this->assertCount(1, $this->emailsTo('jordan@example.test'));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$this->thanks()->id}/logs/{$log->id}/resend")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This email has already been sent.');
        $this->assertSame(0, EmailNotificationLog::where('email_notification_id', '!=', $this->thanks()->id)->count());
    }

    public function test_an_escape_room_log_cannot_be_resent_from_the_email_list(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        app('mail.manager')->forgetMailers();

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->completeGame($game['id'])->assertOk();

        $log = EmailNotificationLog::where('notifiable_type', EscapeRoomSession::class)->firstOrFail();
        $this->assertSame('failed', $log->status);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$this->thanks()->id}/logs/{$log->id}/resend")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This email carried an escape-room group photo. Resend it to the player from the escape-room game screen.');
    }

    public function test_an_event_purchase_marked_completed_gets_the_thanks_email_and_review(): void
    {
        $event = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => self::TODAY,
            'time_start' => '18:00',
            'time_end' => '21:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);
        $purchase = EventPurchase::create([
            'reference_number' => 'EVFU1',
            'event_id' => $event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Riley Glow',
            'guest_email' => 'riley@example.test',
            'purchase_date' => self::TODAY,
            'purchase_time' => '18:00',
            'quantity' => 2,
            'total_amount' => 30,
            'status' => 'checked-in',
        ]);

        $this->actingAs($this->attendant, 'sanctum')
            ->patchJson("/api/event-purchases/{$purchase->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_SENT);

        $this->assertSame('Thanks for playing Glow Night!', $this->emailsTo('riley@example.test')[0]->getSubject());
        $this->assertSame(1, VisitFollowUp::where('visit_type', VisitFollowUp::VISIT_EVENT_PURCHASE)->where('kind', VisitFollowUp::KIND_REVIEW)->count());

        $this->actingAs($this->attendant, 'sanctum')
            ->patchJson("/api/event-purchases/{$purchase->id}/status", ['status' => 'checked-in'])
            ->assertOk();
        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $this->reviewRows()->first()->status);
    }

    public function test_visit_email_rules_are_enforced_when_saving(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Review for escape rooms',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_FOLLOWUP,
            'entity_type' => EmailNotification::ENTITY_PACKAGE,
            'entity_ids' => [$this->morgue->id],
            'subject' => 'Rate us',
            'body' => '<p>{{rating_section}}</p>',
            'recipient_types' => ['staff', 'custom'],
            'custom_emails' => ['boss@example.test'],
            'send_after_hours' => 6,
        ])->assertCreated();

        $response->assertJsonPath('data.recipient_types', ['customer'])
            ->assertJsonPath('data.custom_emails', [])
            ->assertJsonPath('data.send_after_hours', 6);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/email-notifications/' . $response->json('data.id'), [
            'send_after_hours' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors(['send_after_hours']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Attraction thanks',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_ATTRACTION,
            'subject' => 'Thanks',
            'body' => '<p>Thanks</p>',
            'recipient_types' => ['customer'],
        ])->assertStatus(422)->assertJsonValidationErrors(['entity_type']);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/email-notifications/entities?entity_type=event&location_id=' . $this->location->id)->assertOk();
    }

    public function test_preview_shows_the_visit_sample_with_the_unsaved_promo(): void
    {
        $promo = $this->makePromo(['code' => 'PREVIEW25', 'value' => 25]);

        $html = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$this->thanks()->id}/preview", ['promo_id' => $promo->id])
            ->assertOk()
            ->json('data.html');

        $this->assertStringContainsString('PREVIEW25', $html);
        $this->assertStringContainsString('25% off', $html);
        $this->assertStringContainsString('Your group escaped in 47:12!', $html);
        $this->assertStringContainsString('The group photo appears here', $html);
        $this->assertStringNotContainsString('{{', $html);

        $reviewHtml = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$this->review()->id}/preview")
            ->assertOk()
            ->json('data.html');

        $this->assertStringContainsString('/feedback/sample?rating=5', $reviewHtml);
        $this->assertStringNotContainsString('{{', $reviewHtml);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$this->thanks()->id}/send-test", ['test_email' => 'me@example.test'])
            ->assertOk();
        $this->assertStringStartsWith('[TEST] Thanks for playing The Morgue!', $this->emailsTo('me@example.test')[0]->getSubject());

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/email-notifications/variables?trigger_type=visit_followup')
            ->assertOk()
            ->assertJsonPath('data.specific.Rating and review.rating_section', 'Five tappable stars that record the guest rating');
    }

    public function test_photo_settings_point_to_the_thanks_email_instead_of_the_old_wording(): void
    {
        $manager = $this->makeUser('location_manager', $this->location, 'manager');

        $data = $this->actingAs($manager, 'sanctum')->getJson('/api/photo-templates')->assertOk()->json('data');

        $this->assertNotContains('escape_room', $data['kinds']);
        $this->assertNotContains('escape_room', collect($data['templates'])->pluck('kind')->all());
        $this->assertSame($this->thanks()->id, $data['escape_room_email']['id']);

        $this->actingAs($manager, 'sanctum')->postJson('/api/photo-settings/test-message', [
            'location_id' => $this->location->id,
            'channel' => 'email',
            'destination' => 'me@example.test',
            'kind' => 'escape_room',
        ])->assertStatus(422);
    }

    public function test_a_location_review_link_must_be_a_web_address(): void
    {
        $manager = $this->makeUser('location_manager', $this->location, 'manager');

        $this->actingAs($manager, 'sanctum')->putJson("/api/locations/{$this->location->id}", ['review_url' => 'leave us a review'])
            ->assertStatus(422)->assertJsonValidationErrors(['review_url']);
        $this->actingAs($manager, 'sanctum')->putJson("/api/locations/{$this->location->id}", ['review_url' => 'https://g.page/r/abc'])
            ->assertOk();
        $this->assertSame('https://g.page/r/abc', $this->location->fresh()->review_url);

        $this->actingAs($this->attendant, 'sanctum')->putJson("/api/locations/{$this->location->id}", ['review_url' => 'https://evil.example'])
            ->assertForbidden();
    }

    public function test_resending_the_photo_to_a_corrected_address_moves_the_review_with_it(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@typo.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->completeGame($game['id'])->assertOk();

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$avery->id}/resend", ['email' => 'avery@example.test'])
            ->assertOk();

        $rows = $this->reviewRows();
        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $rows->firstWhere('recipient_email', 'avery@typo.test')->status);
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $rows->firstWhere('recipient_email', 'avery@example.test')->status);
        $this->assertCount(1, $this->emailsTo('avery@example.test'));
    }

    public function test_the_ratings_report_is_scoped_to_the_staff_members_location(): void
    {
        $this->completeBooking($this->makeBooking($this->party, 'one@example.test'))->assertOk();
        $this->completeBooking($this->makeBooking($this->otherParty, 'two@example.test'), $this->otherAttendant)->assertOk();
        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        foreach ($this->reviewRows() as $index => $row) {
            $this->postJson("/api/visit-feedback/{$row->token}", ['rating' => $index === 0 ? 5 : 3])->assertOk();
        }

        $this->actingAs($this->attendant, 'sanctum')->getJson('/api/visit-follow-ups/ratings')
            ->assertOk()
            ->assertJsonPath('data.summary.rated', 1)
            ->assertJsonPath('data.summary.requested', 1)
            ->assertJsonPath('data.summary.distribution.5', 1)
            ->assertJsonPath('data.ratings.0.location_name', 'Waterford');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/visit-follow-ups/ratings')
            ->assertOk()
            ->assertJsonPath('data.summary.rated', 2)
            ->assertJsonPath('data.summary.average', 4);
    }

    public function test_follow_up_rows_never_reach_another_company_through_the_resolver(): void
    {
        $service = app(VisitFollowUpService::class);
        $booking = $this->makeBooking($this->party, 'scoped@example.test', 'completed');
        $booking->forceFill(['completed_at' => now()])->save();

        $otherCompany = Company::create(['company_name' => 'Other Co', 'email' => 'o@example.test', 'phone' => '1', 'address' => 'x']);
        DefaultEmailNotificationSeeder::seedForCompany($otherCompany);
        EmailNotification::where('company_id', $otherCompany->id)
            ->where('default_key', EmailNotification::DEFAULT_THANKS_FOR_PLAYING)
            ->update(['subject' => 'WRONG COMPANY']);

        $service->bookingCompleted($booking->fresh(), $this->attendant);

        $this->assertSame('Thanks for playing Birthday Party!', $this->emailsTo('scoped@example.test')[0]->getSubject());
    }

    private function customerToken(): string
    {
        $customer = \App\Models\Customer::create([
            'first_name' => 'Pat', 'last_name' => 'Guest', 'email' => 'pat' . uniqid() . '@example.test', 'password' => bcrypt('x'),
        ]);
        $token = $customer->createToken('customer')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function makeEventPurchase(string $email = 'riley@example.test', string $status = 'checked-in'): EventPurchase
    {
        static $counter = 0;
        $counter++;

        $event = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => self::TODAY,
            'time_start' => '18:00',
            'time_end' => '21:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);

        return EventPurchase::create([
            'reference_number' => 'EVX' . $counter,
            'event_id' => $event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Riley Glow',
            'guest_email' => $email,
            'purchase_date' => now('America/Detroit')->toDateString(),
            'purchase_time' => '18:00',
            'quantity' => 2,
            'total_amount' => 30,
            'status' => $status,
        ]);
    }

    private function copyOf(EmailNotification $notification, array $changes): EmailNotification
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$notification->id}/duplicate")
            ->assertOk()
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/email-notifications/{$id}", $changes + ['is_active' => true])
            ->assertOk();

        return EmailNotification::findOrFail($id);
    }

    public function test_a_customer_token_or_staff_of_another_location_cannot_start_follow_ups(): void
    {
        $purchase = $this->makeEventPurchase('victim@example.test');
        $token = $this->customerToken();

        $this->withToken($token)
            ->putJson("/api/event-purchases/{$purchase->id}", ['status' => 'completed', 'guest_email' => 'attacker@example.test'])
            ->assertForbidden();
        $this->withToken($token)->patchJson("/api/event-purchases/{$purchase->id}/cancel")->assertForbidden();
        $this->withToken($token)->getJson('/api/email-notifications')->assertForbidden();
        $this->assertSame('checked-in', $purchase->fresh()->status);

        $this->app['auth']->forgetGuards();
        $booking = $this->makeBooking($this->party, 'waterford@example.test');

        $this->actingAs($this->otherAttendant, 'sanctum')
            ->patchJson("/api/bookings/{$booking->id}/status", ['status' => 'completed', 'change_reason' => 'Done'])
            ->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['status' => 'completed', 'change_reason' => 'Done'])
            ->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')
            ->patchJson("/api/event-purchases/{$purchase->id}/status", ['status' => 'completed'])
            ->assertForbidden();

        $this->assertSame('checked-in', $booking->fresh()->status);
        $this->assertSame([], $this->followUpEmails());
        $this->assertSame(0, VisitFollowUp::count());
    }

    public function test_old_and_future_visits_are_held_until_staff_choose_send_now(): void
    {
        $old = $this->makeBooking($this->party, 'old@example.test', 'checked-in', '12:00', '2026-09-20');

        $response = $this->completeBooking($old)->assertOk()
            ->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_SKIPPED)
            ->assertJsonPath('follow_up.thanks.0.reason', VisitFollowUp::REASON_VISIT_DATE)
            ->assertJsonPath('follow_up.reviews.0.status', VisitFollowUp::STATUS_SKIPPED);

        $this->assertStringContainsString('Sep 20, 2026', $response->json('follow_up.thanks.0.error'));
        $this->assertSame([], $this->followUpEmails());

        $thanksId = $response->json('follow_up.thanks.0.id');
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$thanksId}/send-now")
            ->assertOk()
            ->assertJsonPath('data.thanks.0.status', VisitFollowUp::STATUS_SENT)
            ->assertJsonPath('data.reviews.0.status', VisitFollowUp::STATUS_SCHEDULED);
        $this->assertCount(1, $this->emailsTo('old@example.test'));

        $future = $this->makeBooking($this->party, 'future@example.test', 'checked-in', '12:00', '2026-10-10');
        $this->completeBooking($future)->assertOk()
            ->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_SKIPPED);
        $this->assertStringContainsString('has not happened yet', VisitFollowUp::where('recipient_email', 'future@example.test')->where('kind', 'thanks')->value('error'));

        $recent = $this->makeBooking($this->party, 'recent@example.test', 'checked-in', '12:00', '2026-09-30');
        $this->completeBooking($recent)->assertOk()->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_SENT);
    }

    public function test_reopening_cancels_a_failed_thanks_and_completing_again_sends_it(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        app('mail.manager')->forgetMailers();

        $booking = $this->makeBooking($this->party);
        $this->completeBooking($booking)->assertOk()->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_FAILED);

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['status' => 'checked-in', 'change_reason' => 'Not done yet'])
            ->assertOk();

        $thanks = VisitFollowUp::where('kind', VisitFollowUp::KIND_THANKS)->firstOrFail();
        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $thanks->status);
        $this->assertSame(VisitFollowUp::REASON_REOPENED, $thanks->reason);

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();

        $this->completeBooking($booking)->assertOk()
            ->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_SENT)
            ->assertJsonPath('follow_up.thanks.0.sent_in_this_action', true)
            ->assertJsonPath('follow_up.reviews.0.status', VisitFollowUp::STATUS_SCHEDULED);
        $this->assertCount(1, $this->emailsTo('jordan@example.test'));

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['status' => 'checked-in', 'change_reason' => 'Again'])
            ->assertOk();
        $this->travel(1)->minutes();
        $this->completeBooking($booking)->assertOk()->assertJsonPath('follow_up.thanks.0.sent_in_this_action', false);
        $this->assertCount(1, $this->emailsTo('jordan@example.test'));
    }

    public function test_a_row_canceled_by_staff_stays_canceled_when_the_visit_is_completed_again(): void
    {
        $booking = $this->makeBooking($this->party);
        $this->completeBooking($booking)->assertOk();
        $review = $this->reviewRows()->first();

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$review->id}/cancel")->assertOk();
        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['status' => 'checked-in', 'change_reason' => 'Oops'])
            ->assertOk();
        $this->completeBooking($booking)->assertOk();

        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $review->fresh()->status);
        $this->assertSame(VisitFollowUp::REASON_STAFF, $review->fresh()->reason);
    }

    public function test_correcting_the_guest_email_after_completion_moves_the_waiting_review(): void
    {
        $booking = $this->makeBooking($this->party, 'jon@gmial.test');
        $this->completeBooking($booking)->assertOk();

        $response = $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['guest_email' => 'jon@gmail.test', 'change_reason' => 'Typo in the email'])
            ->assertOk()
            ->assertJsonPath('follow_up.can_send_thanks', true);

        $rows = $this->reviewRows();
        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $rows->firstWhere('recipient_email', 'jon@gmial.test')->status);
        $this->assertSame(VisitFollowUp::REASON_RECIPIENT_CHANGED, $rows->firstWhere('recipient_email', 'jon@gmial.test')->reason);
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $rows->firstWhere('recipient_email', 'jon@gmail.test')->status);
        $this->assertNotNull($response->json('follow_up'));

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/visit-follow-ups/send-thanks', ['visit_type' => 'booking', 'visit_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('data.can_send_thanks', false);
        $this->assertCount(1, $this->emailsTo('jon@gmail.test'));
    }

    public function test_moving_a_completed_booking_moves_its_follow_ups_to_the_new_location(): void
    {
        $booking = $this->makeBooking($this->party);
        $this->completeBooking($booking)->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/bookings/{$booking->id}/location", ['location_id' => $this->otherLocation->id, 'change_reason' => 'Wrong site'])
            ->assertOk();

        $this->assertSame([$this->otherLocation->id], VisitFollowUp::pluck('location_id')->unique()->values()->all());

        $review = $this->reviewRows()->first();
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$review->id}/cancel")->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')->postJson("/api/visit-follow-ups/{$review->id}/cancel")->assertOk();
    }

    public function test_a_custom_body_gets_the_offer_and_stars_and_placeholders_without_data_disappear(): void
    {
        $promo = $this->makePromo(['code' => 'CUSTOM15', 'value' => 15, 'package_ids' => [$this->party->id]]);
        $this->copyOf($this->thanks(), [
            'body' => '<p>Hi {{customer_first_name}}, thanks for {{activity_name}}!</p><p>You finished in {{completion_time}}.</p><p>{{mystery_variable}}See you soon.</p><div><p>&copy; {{current_year}} {{company_name}}</p></div>',
            'promo_id' => $promo->id,
        ]);

        $this->completeBooking($this->makeBooking($this->party, 'custom@example.test'))->assertOk();
        $html = $this->emailsTo('custom@example.test')[0]->getHtmlBody();

        $this->assertStringContainsString('CUSTOM15', $html);
        $this->assertStringContainsString('Valid on Birthday Party.', $html);
        $this->assertLessThan(strpos($html, '&copy;'), strpos($html, 'CUSTOM15'));
        $this->assertLessThan(strpos($html, 'See you soon.'), strpos($html, 'CUSTOM15'));
        $this->assertStringNotContainsString('You finished in', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringContainsString('See you soon.', $html);
        $this->assertStringContainsString("Don&#039;t want offers like this?", $html);
        $this->assertMatchesRegularExpression('#https://zapzone\.test/feedback/u\.[A-Za-z0-9_\-]+\.[a-f0-9]{32}\?unsubscribe=1#', $html);
        $this->assertStringContainsString('1 Test Way, Waterford, MI, 48327', $html);

        $this->copyOf($this->review(), ['body' => '<p>How did it go, {{customer_first_name}}?</p><p>Thanks!</p>']);
        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $review = $this->emailsTo('custom@example.test')[0]->getHtmlBody();
        $this->assertStringContainsString('?rating=5', $review);
        $this->assertStringContainsString('Unsubscribe', $review);
    }

    public function test_an_unusable_code_drops_the_sentence_that_mentions_it_and_alerts_staff_once(): void
    {
        $expired = $this->makePromo(['code' => 'GONE10', 'end_date' => '2026-10-01']);
        $this->copyOf($this->thanks(), [
            'body' => '<p>Hi {{customer_first_name}}</p><p>Use code {{promo_code}} for {{promo_offer}} next time.</p><p>Bye</p>',
            'promo_id' => $expired->id,
        ]);

        $this->completeBooking($this->makeBooking($this->party, 'a@example.test'))->assertOk();
        $this->completeBooking($this->makeBooking($this->party, 'b@example.test'))->assertOk();

        $html = $this->emailsTo('a@example.test')[0]->getHtmlBody();
        $this->assertStringNotContainsString('Use code', $html);
        $this->assertStringNotContainsString("Don&#039;t want offers", $html);
        $this->assertStringContainsString('Bye', $html);
        $this->assertSame(1, Notification::where('title', 'Return-visit offer left out')->count());
    }

    public function test_the_unsubscribe_link_in_an_offer_stops_offers_and_review_requests(): void
    {
        $this->choosePromo($this->makePromo(['code' => 'OFFER30', 'value' => 30]))->assertOk();
        $this->completeBooking($this->makeBooking($this->party, 'optout@example.test'))->assertOk();

        preg_match('#/feedback/(u\.[A-Za-z0-9_\-]+\.[a-f0-9]{32})\?unsubscribe=1#', $this->emailsTo('optout@example.test')[0]->getHtmlBody(), $match);
        $token = $match[1];

        $this->getJson("/api/visit-feedback/{$token}")->assertOk()
            ->assertJsonPath('data.opt_out_only', true)
            ->assertJsonPath('data.unsubscribed', false);
        $this->getJson('/api/visit-feedback/' . substr($token, 0, -1) . '0')->assertNotFound();
        $this->postJson("/api/visit-feedback/{$token}", ['rating' => 1])->assertNotFound();

        \App\Models\Contact::create(['company_id' => $this->company->id, 'email' => 'optout@example.test', 'status' => 'active']);
        $this->postJson("/api/visit-feedback/{$token}/unsubscribe")->assertOk()->assertJsonPath('data.unsubscribed', true);

        $this->assertTrue(FollowUpOptOut::hasOptedOut($this->company->id, 'optout@example.test'));
        $this->assertSame(VisitFollowUp::STATUS_SKIPPED, $this->reviewRows()->first()->status);
        $this->assertSame('inactive', \App\Models\Contact::where('email', 'optout@example.test')->value('status'));

        $this->travelTo(Carbon::parse('2026-11-20 13:00:00', 'America/Detroit'));
        $this->completeBooking($this->makeBooking($this->party, 'optout@example.test'))->assertOk();
        $latest = collect($this->emailsTo('optout@example.test'))->last()->getHtmlBody();
        $this->assertStringContainsString('Thank you for spending time with us', $latest);
        $this->assertStringNotContainsString('OFFER30', $latest);
        $this->assertSame(VisitFollowUp::REASON_OPTED_OUT, $this->reviewRows()->last()->reason);
    }

    public function test_a_low_rating_raises_one_alert_that_follows_later_changes(): void
    {
        $this->completeBooking($this->makeBooking($this->party, 'flip@example.test'))->assertOk();
        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $row = $this->reviewRows()->first();

        foreach ([1, 5, 1, 5, 2] as $rating) {
            $this->postJson("/api/visit-feedback/{$row->token}", ['rating' => $rating])->assertOk();
        }
        $this->postJson("/api/visit-feedback/{$row->token}", ['rating' => 2, 'comment' => 'Staff were rude'])->assertOk();

        $alerts = Notification::where('title', 'Low rating from a guest')->get();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Staff were rude', $alerts->first()->message);
        $this->assertStringContainsString('2 out of 5', $alerts->first()->message);
        $this->assertSame($alerts->first()->id, $row->fresh()->alert_notification_id);
    }

    public function test_only_admins_change_company_wide_follow_up_emails_and_managers_get_their_own_copy(): void
    {
        $manager = $this->makeUser('location_manager', $this->location, 'manager');
        $thanks = $this->thanks();

        $this->actingAs($this->attendant, 'sanctum')->putJson("/api/email-notifications/{$thanks->id}", ['subject' => 'Hacked'])->assertForbidden();
        $this->actingAs($this->attendant, 'sanctum')->patchJson("/api/email-notifications/{$thanks->id}/toggle-status")->assertForbidden();
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/email-notifications/{$thanks->id}/duplicate")->assertForbidden();
        $this->actingAs($manager, 'sanctum')->putJson("/api/email-notifications/{$thanks->id}", ['promo_id' => $this->makePromo()->id])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->patchJson("/api/email-notifications/{$thanks->id}/toggle-status")->assertForbidden();
        $this->assertTrue($thanks->fresh()->is_active);
        $this->assertFalse($this->actingAs($manager, 'sanctum')->getJson("/api/email-notifications/{$thanks->id}")->json('data.can_edit'));

        $copy = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/email-notifications/{$thanks->id}/duplicate")
            ->assertOk()
            ->assertJsonPath('data.location_id', $this->location->id)
            ->json('data');

        $this->actingAs($manager, 'sanctum')->putJson("/api/email-notifications/{$copy['id']}", ['location_id' => $this->otherLocation->id])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->putJson("/api/email-notifications/{$copy['id']}", ['location_id' => null, 'is_active' => true, 'subject' => 'Waterford thanks you'])->assertOk();
        $this->assertSame($this->location->id, EmailNotification::find($copy['id'])->location_id);

        $this->actingAs($manager, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Brighton thanks',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'subject' => 'Hi',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['customer'],
            'location_id' => $this->otherLocation->id,
        ])->assertForbidden();

        $this->completeBooking($this->makeBooking($this->party, 'wf@example.test'))->assertOk();
        $this->assertSame('Waterford thanks you', $this->emailsTo('wf@example.test')[0]->getSubject());

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/email-notifications/{$thanks->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_a_newer_copy_with_the_same_scope_wins_and_the_default_says_so(): void
    {
        $copy = $this->copyOf($this->thanks(), ['subject' => 'Version two', 'promo_id' => $this->makePromo(['code' => 'V2CODE'])->id]);

        $this->completeBooking($this->makeBooking($this->party, 'v2@example.test'))->assertOk();
        $this->assertSame('Version two', $this->emailsTo('v2@example.test')[0]->getSubject());

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/email-notifications/{$this->thanks()->id}")
            ->assertOk()
            ->assertJsonPath('data.visit_overrides.0.id', $copy->id)
            ->assertJsonPath('data.visit_overrides.0.promo_code', 'V2CODE')
            ->assertJsonPath('data.visit_overrides.0.covers_everything', true);
    }

    public function test_an_escape_room_filter_covers_every_room_including_new_ones_and_the_sender_can_be_renamed(): void
    {
        $this->copyOf($this->thanks(), [
            'subject' => 'Escape Room Zone thanks you',
            'activity_filter' => EmailNotification::ACTIVITY_ESCAPE_ROOM,
            'from_name' => 'Escape Room Zone',
        ]);
        $vault = $this->makePackage('The Vault', $this->location, true);

        $this->signed($vault, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($vault, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->completeGame($game['id'])->assertOk();
        $this->completeBooking($this->makeBooking($this->party, 'party@example.test'))->assertOk();

        $escape = $this->emailsTo('avery@example.test')[0];
        $this->assertSame('Escape Room Zone thanks you', $escape->getSubject());
        $this->assertSame('Thanks for playing Birthday Party!', $this->emailsTo('party@example.test')[0]->getSubject());

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Event thanks',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_EVENT,
            'activity_filter' => EmailNotification::ACTIVITY_ESCAPE_ROOM,
            'subject' => 'Hi',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['customer'],
        ])->assertStatus(422)->assertJsonValidationErrors(['activity_filter']);

        $this->thanks()->update(['is_active' => false]);
        $data = $this->actingAs($this->admin, 'sanctum')->getJson('/api/photo-templates')->assertOk()->json('data');
        $this->assertTrue($data['escape_room_email']['is_active']);
    }

    public function test_the_review_link_and_sender_can_be_set_on_the_email(): void
    {
        $this->location->update(['review_url' => 'https://g.page/r/zapzone']);
        $this->copyOf($this->review(), [
            'review_url' => 'https://g.page/r/escape-room-zone',
            'from_name' => 'Escape Room Zone',
        ]);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/email-notifications/{$this->review()->id}", ['review_url' => 'not a link'])
            ->assertStatus(422)->assertJsonValidationErrors(['review_url']);

        $this->completeBooking($this->makeBooking($this->party, 'reviewer@example.test'))->assertOk();
        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $sent = $this->emailsTo('reviewer@example.test')[0];
        $this->assertStringContainsString('https://g.page/r/escape-room-zone', $sent->getHtmlBody());
        $this->assertStringNotContainsString('https://g.page/r/zapzone"', $sent->getHtmlBody());
        $this->assertSame('Escape Room Zone', $sent->getFrom()[0]->getName());

        $this->getJson('/api/visit-feedback/' . $this->reviewRows()->first()->token)
            ->assertOk()
            ->assertJsonPath('data.review_url', 'https://g.page/r/escape-room-zone')
            ->assertJsonPath('data.brand_name', 'Escape Room Zone');
    }

    public function test_result_only_players_can_be_emailed_later_and_emailed_players_cannot_be_moved(): void
    {
        $casey = $this->signed($this->morgue, '15:00', 'Casey', 'casey@example.test');
        $drew = $this->signed($this->morgue, '15:00', 'Drew', 'drew@example.test');
        $game = $this->openGame($this->morgue, '15:00');
        $this->completeGame($game['id'], ['without_photo' => true])->assertOk();
        $this->assertSame(0, VisitFollowUp::count());

        $detail = $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/sessions/{$game['id']}")->assertOk();
        $detail->assertJsonPath('data.can_send_new', true)->assertJsonPath('data.counts.new_players', 2);

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertOk();
        $this->assertCount(1, $this->emailsTo('casey@example.test'));
        $this->assertCount(1, $this->emailsTo('drew@example.test'));
        $this->assertSame(2, $this->reviewRows()->count());
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertStatus(409);

        $detail = $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/sessions/{$game['id']}")->assertOk();
        $detail->assertJsonPath('data.can_send_new', false)->assertJsonPath('data.counts.thanks_sent', 2);
        $this->assertTrue(collect($detail->json('data.players'))->firstWhere('waiver_id', $casey->id)['sent']);
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$drew->id}/remove")->assertStatus(422);
    }

    public function test_an_escape_room_review_log_can_be_resent_from_the_email_list(): void
    {
        $this->signed($this->morgue, '15:00', 'Casey', 'casey@example.test');
        $game = $this->openGame($this->morgue, '15:00');
        $this->completeGame($game['id'], ['without_photo' => true, 'email_players' => true])->assertOk();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        app('mail.manager')->forgetMailers();
        $this->travelTo(Carbon::parse('2026-10-04 16:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $log = EmailNotificationLog::where('email_notification_id', $this->review()->id)->firstOrFail();
        $this->assertSame('failed', $log->status);

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        $this->clearEmails();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$this->review()->id}/logs/{$log->id}/resend")
            ->assertOk();
        $this->assertCount(1, $this->emailsTo('casey@example.test'));
        $this->actingAs($this->otherAttendant, 'sanctum')
            ->postJson("/api/email-notifications/{$this->review()->id}/logs/{$log->id}/resend")
            ->assertStatus(403);
    }

    public function test_reviews_that_come_due_at_night_wait_for_morning_and_a_stuck_last_try_alerts_staff(): void
    {
        $this->completeBooking($this->makeBooking($this->party, 'night@example.test'))->assertOk();
        $row = $this->reviewRows()->first();

        $this->travelTo(Carbon::parse('2026-10-05 22:30:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $this->assertSame([], $this->followUpEmails());
        $this->assertSame('2026-10-06 09:00', $row->fresh()->due_at->copy()->setTimezone('America/Detroit')->format('Y-m-d H:i'));

        $this->travelTo(Carbon::parse('2026-10-06 09:05:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::STATUS_SENT, $row->fresh()->status);

        $stuck = VisitFollowUp::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'visit_type' => VisitFollowUp::VISIT_BOOKING,
            'visit_id' => 999999,
            'kind' => VisitFollowUp::KIND_THANKS,
            'recipient_email' => 'stuck@example.test',
            'status' => VisitFollowUp::STATUS_SENDING,
            'attempts' => VisitFollowUp::MAX_ATTEMPTS,
        ]);
        VisitFollowUp::whereKey($stuck->id)->toBase()->update(['updated_at' => now()->subHour()]);

        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertSame(1, Notification::where('title', 'Thanks for Playing email not delivered')->count());
    }

    public function test_an_escape_room_package_without_an_escape_waiver_is_emailed_like_any_booking(): void
    {
        WaiverTemplate::where('kind', WaiverTemplate::KIND_ESCAPE_ROOM)->update(['status' => WaiverTemplate::STATUS_ARCHIVED]);
        $booking = $this->makeBooking($this->morgue, 'nowaiver@example.test', 'checked-in', '14:00');

        $this->completeBooking($booking)->assertOk()
            ->assertJsonPath('follow_up.handled_by_game', false)
            ->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_SENT);
        $this->assertCount(1, $this->emailsTo('nowaiver@example.test'));
    }

    public function test_the_entity_picker_and_saved_targets_stay_inside_the_company(): void
    {
        $otherCompany = Company::create(['company_name' => 'Other Co', 'email' => 'o@example.test', 'phone' => '1', 'address' => 'x']);
        $foreignLocation = Location::create([
            'company_id' => $otherCompany->id, 'name' => 'Elsewhere', 'address' => '1', 'city' => 'X', 'state' => 'MI',
            'zip_code' => '1', 'phone' => '1', 'email' => 'elsewhere2@example.test', 'timezone' => 'America/Detroit', 'is_active' => true,
        ]);
        $foreign = $this->makePackage('Foreign Room', $foreignLocation, true);
        $this->admin->forceFill(['location_id' => null])->save();

        $names = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/email-notifications/entities?entity_type=package')->assertOk()->json('data'))->pluck('name');
        $this->assertContains('The Morgue', $names->all());
        $this->assertNotContains('Foreign Room', $names->all());

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Sneaky',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_PACKAGE,
            'entity_ids' => [$foreign->id],
            'subject' => 'Hi',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['customer'],
        ])->assertStatus(422)->assertJsonValidationErrors(['entity_ids']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Sneaky location',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'subject' => 'Hi',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['customer'],
            'location_id' => $foreignLocation->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['location_id']);
    }

    public function test_a_stale_promo_on_the_email_does_not_block_saving_other_changes(): void
    {
        $promo = $this->makePromo(['code' => 'SOONGONE']);
        $this->choosePromo($promo)->assertOk();
        $promo->update(['deleted' => true]);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/email-notifications/{$this->thanks()->id}", [
            'subject' => 'Fixed a typo',
            'promo_id' => $promo->id,
        ])->assertOk();

        $this->assertSame('Fixed a typo', $this->thanks()->subject);
        $this->assertStringContainsString('deleted', (string) $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/email-notifications/{$this->thanks()->id}")->json('data.promo_summary.problem'));

        $promo->forceDelete();
        $this->assertSame($promo->id, $this->thanks()->promo_id);
        $this->assertStringContainsString('no longer exists', (string) $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/email-notifications/{$this->thanks()->id}")->json('data.promo_summary.problem'));
    }

    public function test_preview_and_test_send_match_the_real_email(): void
    {
        $this->party->update(['name' => "Kids' Night & Pizza"]);
        $copy = $this->copyOf($this->thanks(), [
            'entity_type' => EmailNotification::ENTITY_PACKAGE,
            'entity_ids' => [$this->party->id],
            'subject' => 'Thanks for {{activity_name}}',
            'body' => '<p>Hi {{customer_first_name}}</p><p>Bye</p>',
            'promo_id' => $this->makePromo(['code' => 'PREVIEWME'])->id,
        ]);

        $preview = $this->actingAs($this->admin, 'sanctum')->postJson("/api/email-notifications/{$copy->id}/preview")->assertOk()->json('data');

        $this->assertSame("Thanks for Kids' Night & Pizza", $preview['subject']);
        $this->assertStringContainsString('PREVIEWME', $preview['html']);
        $this->assertStringNotContainsString('Your result', $preview['html']);
        $this->assertStringContainsString("Don&#039;t want offers like this?", $preview['html']);
    }

    private function failMail(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        app('mail.manager')->forgetMailers();
    }

    private function workingMail(): void
    {
        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
    }

    public function test_a_second_visit_still_gets_its_review_when_the_first_one_is_withdrawn(): void
    {
        $first = $this->makeBooking($this->party, 'twice@example.test');
        $second = $this->makeBooking($this->party, 'twice@example.test');
        $this->completeBooking($first)->assertOk();
        $this->completeBooking($second)->assertOk();

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$first->id}", ['status' => 'checked-in', 'change_reason' => 'Not finished'])
            ->assertOk();

        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $sent = VisitFollowUp::where('kind', 'review')->where('status', 'sent')->get();
        $this->assertSame([$second->id], $sent->pluck('visit_id')->map(fn ($id) => (int) $id)->all());
        $this->assertCount(1, $this->emailsTo('twice@example.test'));
    }

    public function test_nothing_is_sent_to_an_old_address_after_the_guest_email_was_corrected(): void
    {
        $this->failMail();
        $booking = $this->makeBooking($this->party, 'jon@gmial.test');
        $this->completeBooking($booking)->assertOk()->assertJsonPath('follow_up.thanks.0.status', VisitFollowUp::STATUS_FAILED);
        $oldThanks = VisitFollowUp::where('kind', 'thanks')->where('recipient_email', 'jon@gmial.test')->firstOrFail();
        $log = EmailNotificationLog::where('recipient_email', 'jon@gmial.test')->firstOrFail();
        $this->workingMail();

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['guest_email' => 'jon@gmail.test', 'change_reason' => 'Typo'])
            ->assertOk();
        $this->assertSame(VisitFollowUp::REASON_RECIPIENT_CHANGED, $oldThanks->fresh()->reason);
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::where('kind', 'review')->where('recipient_email', 'jon@gmail.test')->value('status'));

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$oldThanks->id}/send-now")->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/email-notifications/{$this->thanks()->id}/logs/{$log->id}/resend")->assertStatus(422);

        $this->assertSame([], $this->emailsTo('jon@gmial.test'));
        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $oldThanks->fresh()->status);

        $held = $this->makeBooking($this->party, 'old@typo.test', 'checked-in', '12:00', '2026-09-20');
        $this->completeBooking($held)->assertOk();
        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$held->id}", ['guest_email' => 'old@fixed.test', 'change_reason' => 'Typo'])
            ->assertOk();
        $heldRow = VisitFollowUp::where('kind', 'thanks')->where('recipient_email', 'old@typo.test')->firstOrFail();
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$heldRow->id}/send-now")->assertStatus(422);
        $this->assertSame([], $this->emailsTo('old@typo.test'));
    }

    public function test_send_thanks_now_reports_a_failed_send_instead_of_success(): void
    {
        $booking = $this->makeBooking($this->party, '');
        $this->completeBooking($booking)->assertOk();
        $booking->forceFill(['guest_email' => 'late@example.test'])->save();
        $this->failMail();

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/visit-follow-ups/send-thanks', ['visit_type' => 'booking', 'visit_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.thanks.0.status', VisitFollowUp::STATUS_FAILED);
        $this->assertStringContainsString('will be tried again', (string) $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/visit-follow-ups/send-thanks', ['visit_type' => 'booking', 'visit_id' => $booking->id])->json('message'));
    }

    public function test_a_held_review_is_scheduled_even_when_the_first_send_now_attempt_fails(): void
    {
        $old = $this->makeBooking($this->party, 'held@example.test', 'checked-in', '12:00', '2026-09-20');
        $this->completeBooking($old)->assertOk();
        $thanks = VisitFollowUp::where('kind', 'thanks')->firstOrFail();

        $this->failMail();
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$thanks->id}/send-now")->assertStatus(422);

        $this->assertSame(VisitFollowUp::STATUS_FAILED, $thanks->fresh()->status);
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $this->reviewRows()->first()->status);
    }

    public function test_a_player_taken_out_of_a_game_gets_none_of_its_follow_ups(): void
    {
        $this->thanks()->update(['is_active' => false]);
        $walkIn = $this->signed($this->morgue, '15:00', 'Jordan', 'walkin@example.test');
        $stays = $this->signed($this->morgue, '15:00', 'Casey', 'casey@example.test');
        $game = $this->openGame($this->morgue, '15:00');
        $this->completeGame($game['id'], ['without_photo' => true, 'email_players' => true])->assertOk();
        $this->assertSame(2, $this->reviewRows()->where('status', 'scheduled')->count());

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$walkIn->id}/remove")
            ->assertOk();

        $removed = $this->reviewRows()->firstWhere('recipient_email', 'walkin@example.test');
        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $removed->status);
        $this->assertSame(VisitFollowUp::REASON_LEFT_GAME, $removed->reason);
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$removed->id}/send-now")->assertStatus(422);

        $stays->forceFill(['escape_room_session_id' => null])->save();
        $this->travelTo(Carbon::parse('2026-10-04 16:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();

        $this->assertSame(VisitFollowUp::REASON_LEFT_GAME, $this->reviewRows()->firstWhere('recipient_email', 'casey@example.test')->reason);
        $this->assertSame([], $this->followUpEmails());
    }

    public function test_resending_a_photo_does_not_undo_a_canceled_or_answered_review_request(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $blake = $this->signed($this->morgue, '14:00', 'Blake', 'blake@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->completeGame($game['id'])->assertOk();

        $averyReview = $this->reviewRows()->firstWhere('recipient_email', 'avery@example.test');
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/visit-follow-ups/{$averyReview->id}/cancel")->assertOk();
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$avery->id}/resend", ['email' => 'avery@work.test'])
            ->assertOk();
        $this->assertNull(VisitFollowUp::where('recipient_email', 'avery@work.test')->where('kind', 'review')->first());

        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::STATUS_SENT, $this->reviewRows()->firstWhere('recipient_email', 'blake@example.test')->status);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$blake->id}/resend", ['email' => 'blake@work.test'])
            ->assertOk();
        $this->assertNull(VisitFollowUp::where('recipient_email', 'blake@work.test')->where('kind', 'review')->first());
    }

    public function test_players_who_share_an_email_are_emailed_once_and_counted_as_emailed(): void
    {
        $this->signed($this->morgue, '15:00', 'Pat', 'family@example.test');
        $second = $this->signed($this->morgue, '15:00', 'Sam', 'family@example.test');
        $game = $this->openGame($this->morgue, '15:00');
        $this->completeGame($game['id'], ['without_photo' => true, 'email_players' => true])->assertOk();

        $detail = $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/sessions/{$game['id']}")->assertOk();
        $detail->assertJsonPath('data.can_send_new', false)->assertJsonPath('data.counts.new_players', 0);
        $this->assertSame(VisitFollowUp::STATUS_SENT, collect($detail->json('data.players'))->firstWhere('waiver_id', $second->id)['thanks_email']['status']);
        $this->assertCount(1, $this->emailsTo('family@example.test'));

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertStatus(409);
    }

    public function test_a_code_paragraph_survives_when_only_a_secondary_value_is_blank(): void
    {
        $this->location->update(['review_url' => null]);
        $this->copyOf($this->thanks(), [
            'body' => '<p>Hi {{customer_first_name}}</p><p>Use code <strong>{{promo_code}}</strong> for {{promo_offer}}. {{promo_terms}}</p><p>Bye</p>',
            'promo_id' => $this->makePromo(['code' => 'KEEPME'])->id,
        ]);
        $this->copyOf($this->review(), [
            'body' => '<p>Rate us <a href="{{rating_link}}">here</a> or review us <a href="{{review_link}}">online</a>.</p><p>Thanks</p>',
        ]);

        $this->completeBooking($this->makeBooking($this->party, 'keep@example.test'))->assertOk();
        $html = $this->emailsTo('keep@example.test')[0]->getHtmlBody();
        $this->assertStringContainsString('Use code <strong>KEEPME</strong>', $html);
        $this->assertStringNotContainsString('A thank-you for your next visit', $html);
        $this->assertSame(1, substr_count($html, 'KEEPME'));

        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $review = $this->emailsTo('keep@example.test')[0]->getHtmlBody();
        $this->assertStringContainsString('Rate us', $review);
        $this->assertStringContainsString('/feedback/', $review);
    }

    public function test_a_payment_saved_on_a_completed_booking_keeps_it_completed(): void
    {
        $booking = $this->makeBooking($this->party, 'payer@example.test');
        $this->completeBooking($booking)->assertOk();

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['amount_paid' => 120, 'status' => 'confirmed', 'change_reason' => 'Balance paid'])
            ->assertOk();

        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $this->reviewRows()->first()->status);
    }

    public function test_restoring_a_deleted_completed_booking_brings_its_review_back(): void
    {
        $booking = $this->makeBooking($this->party, 'restore@example.test');
        $this->completeBooking($booking)->assertOk();
        $booking->delete();

        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::REASON_VISIT_GONE, $this->reviewRows()->first()->reason);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/bookings/{$booking->id}/restore", ['change_reason' => 'Deleted by mistake'])->assertOk();
        $this->assertSame(VisitFollowUp::STATUS_SCHEDULED, $this->reviewRows()->first()->status);

        $this->travelTo(Carbon::parse('2026-10-05 15:00:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertCount(1, $this->emailsTo('restore@example.test'));

        $token = $this->customerToken();
        $this->withToken($token)->postJson('/api/event-purchases/1/restore')->assertForbidden();
    }

    public function test_a_game_on_a_later_day_cannot_be_completed_and_a_switched_off_email_is_explained(): void
    {
        $tomorrow = $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/escape-rooms/sessions', [
                'location_id' => $this->location->id,
                'package_id' => $this->morgue->id,
                'date' => '2026-10-04',
                'time' => '14:00',
            ])
            ->assertCreated()
            ->json('data');
        $this->completeGame($tomorrow['id'])->assertStatus(422)->assertJsonPath('message', 'This game is on a later day. Complete it after it has been played.');

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->completeGame($game['id'])->assertOk();
        $this->thanks()->update(['is_active' => false]);

        $this->assertStringContainsString('switched off', (string) $this->actingAs($this->attendant, 'sanctum')
            ->getJson("/api/escape-rooms/sessions/{$game['id']}")->json('data.send_blocker'));
    }

    public function test_preview_follows_a_cleared_review_link_before_it_is_saved(): void
    {
        $this->location->update(['review_url' => 'https://g.page/r/location-link']);
        $copy = $this->copyOf($this->review(), ['review_url' => 'https://g.page/r/brand-link']);

        $html = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$copy->id}/preview", ['review_url' => null])
            ->assertOk()
            ->json('data.html');

        $this->assertStringContainsString('https://g.page/r/location-link', $html);
        $this->assertStringNotContainsString('brand-link', $html);
    }

    public function test_moving_a_completed_booking_from_the_edit_page_moves_its_follow_ups(): void
    {
        $booking = $this->makeBooking($this->party, 'mover@example.test');
        $this->completeBooking($booking)->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", [
                'location_id' => $this->otherLocation->id,
                'package_id' => $this->otherParty->id,
                'change_reason' => 'Booked at the wrong site',
            ])
            ->assertOk();

        $this->assertSame($this->otherLocation->id, (int) $booking->fresh()->location_id);
        $this->assertSame([$this->otherLocation->id], VisitFollowUp::pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    public function test_any_path_that_takes_a_visit_out_of_completed_stops_its_waiting_review(): void
    {
        $booking = $this->makeBooking($this->party, 'refund@example.test');
        $this->completeBooking($booking)->assertOk();
        $booking->fresh()->update(['status' => 'cancelled', 'payment_status' => 'refunded']);

        $this->assertSame(VisitFollowUp::STATUS_CANCELED, $this->reviewRows()->first()->status);
        $this->assertSame(VisitFollowUp::REASON_REOPENED, $this->reviewRows()->first()->reason);

        $purchase = $this->makeEventPurchase('evrefund@example.test');
        $this->actingAs($this->attendant, 'sanctum')->patchJson("/api/event-purchases/{$purchase->id}/status", ['status' => 'completed'])->assertOk();
        $purchase->fresh()->update(['status' => 'cancelled']);
        $this->assertSame(VisitFollowUp::STATUS_CANCELED, VisitFollowUp::where('visit_type', 'event_purchase')->where('kind', 'review')->value('status'));
    }

    public function test_a_waiting_review_follows_an_email_change_made_outside_the_edit_page(): void
    {
        $booking = $this->makeBooking($this->party, 'first@example.test');
        $this->completeBooking($booking)->assertOk();
        $booking->forceFill(['guest_email' => 'second@example.test'])->save();

        $this->travelTo(Carbon::parse('2026-10-04 14:00:00', 'America/Detroit'));
        $this->clearEmails();
        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertSame(VisitFollowUp::REASON_RECIPIENT_CHANGED, $this->reviewRows()->firstWhere('recipient_email', 'first@example.test')->reason);
        $this->assertSame([], $this->emailsTo('first@example.test'));

        $this->artisan('visits:send-follow-ups')->assertSuccessful();
        $this->assertCount(1, $this->emailsTo('second@example.test'));
    }
}
