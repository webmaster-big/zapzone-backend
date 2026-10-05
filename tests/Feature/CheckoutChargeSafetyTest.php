<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\AuthorizeNetAccount;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverTemplate;
use App\Services\AuthorizeNetCharger;
use App\Services\EmailNotificationService;
use App\Services\Payments\AuthorizeNetGateway;
use App\Services\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use Tests\TestCase;

class CheckoutChargeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private FakeAuthorizeNetGateway $gateway;

    private Company $company;

    private Location $location;

    private Package $package;

    private Room $roomOne;

    private Room $roomTwo;

    private AuthorizeNetAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'google_calendar.auto_sync' => false,
            'booking_rules.slot_conflict' => 'enforce',
            'checkout.enforce_charge_within_due' => false,
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->gateway = new FakeAuthorizeNetGateway();
        $this->app->instance(AuthorizeNetGateway::class, $this->gateway);

        $this->company = Company::create([
            'company_name' => 'Escape Co',
            'email' => 'escape@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Escape St',
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

        $this->roomOne = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Rage Room 1',
            'capacity' => 20,
            'is_available' => true,
        ]);

        $this->roomTwo = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Rage Room 2',
            'capacity' => 20,
            'is_available' => true,
        ]);

        $this->package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Demolition Rage',
            'description' => 'Break things',
            'category' => 'Rage Room',
            'price' => 103.98,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 20,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $this->account = AuthorizeNetAccount::create([
            'location_id' => $this->location->id,
            'api_login_id' => 'test-login',
            'transaction_key' => 'test-transaction-key',
            'public_client_key' => 'test-client-key',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->freshControllers();

        return parent::postJson($uri, $data, $headers, $options);
    }

    public function deleteJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->freshControllers();

        return parent::deleteJson($uri, $data, $headers, $options);
    }

    private function freshControllers(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }

    private function staff(): User
    {
        return User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk.' . uniqid() . '@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function bookingPayload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'guest_name' => 'La Shawn Thomas',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '6165550000',
            'package_id' => $this->package->id,
            'location_id' => $this->location->id,
            'room_id' => $room->id,
            'type' => 'package',
            'booking_date' => now()->addDays(3)->toDateString(),
            'booking_time' => '20:00',
            'participants' => 2,
            'duration' => 20,
            'duration_unit' => 'minutes',
            'total_amount' => 109.04,
            'amount_paid' => 109.04,
            'payment_method' => 'authorize.net',
            'send_email' => false,
        ], $overrides);
    }

    private function chargePayload(int $payableId, float $amount = 109.04, string $type = Payment::TYPE_BOOKING): array
    {
        return [
            'location_id' => $this->location->id,
            'opaqueData' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'opaque-token'],
            'amount' => $amount,
            'order_id' => 'P' . $this->package->id . '-12345678',
            'payable_id' => $payableId,
            'payable_type' => $type,
            'send_email' => false,
            'customer' => ['first_name' => 'La Shawn', 'last_name' => 'Thomas', 'email' => 'guest@example.com', 'zip' => '48335'],
        ];
    }

    private function transactionReply(string $responseCode, string $transId = '60200000001', ?string $approvedAmount = null, string $avs = 'Y'): AnetAPI\CreateTransactionResponse
    {
        $transaction = (new AnetAPI\TransactionResponseType())
            ->setResponseCode($responseCode)
            ->setTransId($transId)
            ->setAuthCode($responseCode === '1' ? 'ABC123' : '')
            ->setAvsResultCode($avs)
            ->setCvvResultCode('M')
            ->setAccountNumber('XXXX2020')
            ->setAccountType('Visa')
            ->setMessages([
                (new AnetAPI\TransactionResponseType\MessagesAType\MessageAType())
                    ->setCode($responseCode === '1' ? '1' : '253')
                    ->setDescription($responseCode === '1' ? 'This transaction has been approved.' : 'Your order has been received. Thank you for your business!'),
            ]);

        if ($approvedAmount !== null) {
            $transaction->setPrePaidCard(
                (new AnetAPI\TransactionResponseType\PrePaidCardAType())
                    ->setRequestedAmount('109.04')
                    ->setApprovedAmount($approvedAmount)
                    ->setBalanceOnCard('0.00')
            );
        }

        return (new AnetAPI\CreateTransactionResponse())
            ->setMessages($this->apiMessages('Ok'))
            ->setTransactionResponse($transaction);
    }

    private function declineReply(string $transId = '60200000099'): AnetAPI\CreateTransactionResponse
    {
        $transaction = (new AnetAPI\TransactionResponseType())
            ->setResponseCode('2')
            ->setTransId($transId)
            ->setErrors([
                (new AnetAPI\TransactionResponseType\ErrorsAType\ErrorAType())
                    ->setErrorCode('2')
                    ->setErrorText('This transaction has been declined.'),
            ]);

        return (new AnetAPI\CreateTransactionResponse())
            ->setMessages($this->apiMessages('Error'))
            ->setTransactionResponse($transaction);
    }

    private function apiMessages(string $resultCode): AnetAPI\MessagesType
    {
        return (new AnetAPI\MessagesType())
            ->setResultCode($resultCode)
            ->setMessage([
                (new AnetAPI\MessagesType\MessageAType())
                    ->setCode($resultCode === 'Ok' ? 'I00001' : 'E00027')
                    ->setText($resultCode === 'Ok' ? 'Successful.' : 'The transaction was unsuccessful.'),
            ]);
    }

    private function heldUpdateReply(string $resultCode): AnetAPI\UpdateHeldTransactionResponse
    {
        return (new AnetAPI\UpdateHeldTransactionResponse())->setMessages($this->apiMessages($resultCode));
    }

    private function voidReply(string $resultCode): AnetAPI\CreateTransactionResponse
    {
        return (new AnetAPI\CreateTransactionResponse())->setMessages($this->apiMessages($resultCode));
    }

    private function createBooking(Room $room, array $overrides = []): int
    {
        return (int) $this->postJson('/api/bookings', $this->bookingPayload($room, $overrides))
            ->assertStatus(201)
            ->json('data.id');
    }

    private function bookAndPay(Room $room, string $transId, array $overrides = []): Booking
    {
        $bookingId = $this->createBooking($room, $overrides);

        $this->gateway->replies[] = $this->transactionReply('1', $transId);
        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertOk()
            ->assertJsonPath('success', true);

        return Booking::findOrFail($bookingId);
    }

    public function test_a_guest_booking_a_second_space_at_the_same_time_is_asked_first_and_then_gets_its_own_booking(): void
    {
        $first = $this->bookAndPay($this->roomOne, '60200000001');

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomTwo))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOKED_SAME_TIME')
            ->assertJsonPath('reference_number', $first->reference_number);

        $this->assertSame(1, Booking::count(), 'nothing is booked or charged until the guest says yes');

        $second = $this->bookAndPay($this->roomTwo, '60200000002', ['book_another' => true]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Booking::count());

        foreach ([$first, $second] as $booking) {
            $booking->refresh();
            $this->assertSame('confirmed', $booking->status);
            $this->assertEqualsWithDelta(109.04, (float) $booking->amount_paid, 0.001);
            $this->assertSame(1, Payment::where('payable_type', Payment::TYPE_BOOKING)->where('payable_id', $booking->id)->count());
        }
    }

    public function test_staff_are_not_asked_before_booking_the_same_guest_into_another_space(): void
    {
        $this->bookAndPay($this->roomOne, '60200000001');

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomTwo))
            ->assertStatus(201);

        $this->assertSame(2, Booking::count());
    }

    public function test_booking_the_same_space_again_never_charges_the_first_booking(): void
    {
        $first = $this->bookAndPay($this->roomOne, '60200000001');

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOKED_SAME_TIME');

        $insisted = $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['book_another' => true]));
        $this->assertSame(409, $insisted->status(), 'the space is taken by the first booking');
        $this->assertStringContainsString('taken', (string) $insisted->json('message'), 'the space is refused, never handed back to be charged again');

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
        $this->assertEqualsWithDelta(109.04, (float) $first->fresh()->amount_paid, 0.001);
    }

    public function test_a_retry_with_the_same_checkout_key_after_paying_shows_the_booking_instead_of_charging(): void
    {
        $first = $this->bookAndPay($this->roomOne, '60200000001', ['checkout_key' => 'attempt-one']);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'attempt-one']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_BOOKED')
            ->assertJsonPath('reference_number', $first->reference_number);

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_an_identical_retry_of_an_unpaid_checkout_reuses_its_booking(): void
    {
        $firstId = $this->createBooking($this->roomOne);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne))
            ->assertOk()
            ->assertJsonPath('message', 'Booking already exists')
            ->assertJsonPath('data.id', $firstId);

        $this->assertSame(1, Booking::count());
    }

    public function test_a_guest_cannot_charge_something_already_paid_in_full(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000001');
        $this->travel(3)->minutes();

        $this->postJson('/api/payments/charge', $this->chargePayload($booking->id))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ALREADY_PAID');

        $this->assertSame(1, Payment::count());
        $this->assertCount(1, $this->gateway->sent, 'the gateway must not be asked to charge again');
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_staff_can_still_take_another_card_payment_on_a_paid_booking(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000001');
        $this->travel(3)->minutes();

        $this->gateway->replies[] = $this->transactionReply('1', '60200000009');

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/payments/charge', $this->chargePayload($booking->id, 20.00))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(2, Payment::count());
    }

    public function test_the_public_delete_cannot_remove_a_paid_booking(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000001');

        $this->deleteJson("/api/bookings/{$booking->id}/force-delete")->assertStatus(403);
        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only an unpaid pending booking can be removed this way.');

        $this->assertNotNull(Booking::find($booking->id), 'the paid booking stays in the bookings list');
    }

    public function test_the_public_delete_still_rolls_back_an_unpaid_pending_booking(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->deleteJson("/api/bookings/{$bookingId}")->assertOk();
        $this->assertTrue(Booking::withTrashed()->findOrFail($bookingId)->trashed());

        $this->deleteJson("/api/bookings/{$bookingId}/force-delete")->assertOk();
        $this->assertNull(Booking::withTrashed()->find($bookingId));
    }

    public function test_staff_can_still_delete_a_paid_booking(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000001');

        $this->actingAs($this->staff(), 'sanctum')
            ->deleteJson("/api/bookings/{$booking->id}", ['change_reason' => 'Guest asked to cancel'])
            ->assertOk();

        $this->assertTrue(Booking::withTrashed()->findOrFail($booking->id)->trashed());
    }

    public function test_a_deleted_booking_cannot_be_charged(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        Booking::findOrFail($bookingId)->delete();

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))->assertStatus(422);

        $this->assertSame(0, Payment::count());
        $this->assertCount(0, $this->gateway->sent);
    }

    public function test_a_charge_held_for_review_is_not_treated_as_paid(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->replies[] = $this->transactionReply('4', '60200000003');
        $this->gateway->replies[] = $this->heldUpdateReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'PAYMENT_NOT_APPROVED');

        $this->assertSame(0, Payment::count());
        $this->assertNull(Booking::withTrashed()->find($bookingId), 'the unpaid booking is rolled back');
        $this->assertSame(AnetController\UpdateHeldTransactionController::class, $this->gateway->sent[1]['controller']);
        $this->assertSame('decline', $this->gateway->sent[1]['held_action']);
        $this->assertSame('60200000003', $this->gateway->sent[1]['ref_trans_id']);
        $this->assertSame(0, Notification::where('title', 'Card payment needs action in Authorize.Net')->count());
    }

    public function test_a_held_charge_that_cannot_be_cancelled_alerts_staff(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->replies[] = $this->transactionReply('4', '60200000004');
        $this->gateway->replies[] = $this->heldUpdateReply('Error');
        $this->gateway->replies[] = $this->voidReply('Error');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'PAYMENT_NOT_APPROVED');

        $this->assertSame(0, Payment::count());
        $this->assertSame('voidTransaction', $this->gateway->sent[2]['transaction_type']);
        $this->assertSame([], $this->gateway->replies);
        $alert = Notification::where('title', 'Card payment needs action in Authorize.Net')->first();
        $this->assertNotNull($alert);
        $this->assertSame($this->location->id, (int) $alert->location_id);
        $this->assertStringContainsString('60200000004', $alert->message);
    }

    public function test_a_partly_approved_charge_is_voided_and_refused(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->replies[] = $this->transactionReply('1', '60200000005', '50.00');
        $this->gateway->replies[] = $this->heldUpdateReply('Error');
        $this->gateway->replies[] = $this->voidReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'PAYMENT_NOT_APPROVED');

        $this->assertSame(0, Payment::count());
        $this->assertSame('voidTransaction', $this->gateway->sent[2]['transaction_type']);
        $this->assertSame('60200000005', $this->gateway->sent[2]['ref_trans_id']);
    }

    public function test_a_declined_card_shows_the_banks_reason(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->replies[] = $this->declineReply();

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(400)
            ->assertJsonPath('message', 'This transaction has been declined.')
            ->assertJsonPath('transaction_error_code', '2');

        $this->assertSame(0, Payment::count());
        $this->assertNull(Booking::withTrashed()->find($bookingId));
    }

    public function test_no_answer_from_the_gateway_tells_the_guest_to_check_and_alerts_staff(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->replies[] = FakeAuthorizeNetGateway::NO_ANSWER;

        $response = $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))->assertStatus(400);

        $this->assertStringContainsString("can't tell whether your card was charged", $response->json('message'));
        $alert = Notification::where('title', 'Card payment needs checking in Authorize.Net')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('P' . $this->package->id . '-12345678', $alert->message);
    }

    public function test_an_approved_charge_that_fails_the_zip_check_and_cannot_be_voided_alerts_staff(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->replies[] = $this->transactionReply('1', '60200000007', null, 'N');
        $this->gateway->replies[] = $this->voidReply('Error');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'AVS_MISMATCH');

        $alert = Notification::where('title', 'Card payment needs action in Authorize.Net')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('60200000007', $alert->message);
        $this->assertSame('avs_void_failed', $alert->metadata['reason']);
    }

    public function test_an_approved_charge_still_confirms_the_booking(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000006');

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('paid', $booking->payment_status);
        $payment = Payment::firstOrFail();
        $this->assertSame('completed', $payment->status);
        $this->assertSame('60200000006', $payment->transaction_id);
        $this->assertSame('authCaptureTransaction', $this->gateway->sent[0]['transaction_type']);
    }

    public function test_a_declined_checkout_does_not_leave_a_waiver_that_sends_visit_reminders(): void
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General Activity Waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'assigned_package_ids' => [],
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        $bookingId = $this->createBooking($this->roomOne);
        $waiver = Waiver::where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(Waiver::STATUS_PENDING, $waiver->status);

        $this->gateway->replies[] = $this->declineReply();
        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))->assertStatus(400);

        $this->assertNull(Booking::withTrashed()->find($bookingId));
        $this->assertTrue(Waiver::withTrashed()->findOrFail($waiver->id)->trashed());
        $this->assertFalse(app(WaiverService::class)->dueForReminder(24 * 7)->contains('id', $waiver->id));
    }

    public function test_an_online_checkout_that_was_never_paid_gets_no_visit_reminder(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $staff = $this->staff();

        $unpaidOnline = $this->createBooking($this->roomOne, ['booking_date' => $tomorrow]);
        $paidOnline = $this->bookAndPay($this->roomTwo, '60200000012', ['booking_date' => $tomorrow, 'guest_email' => 'paid@example.com']);
        $payLater = (int) $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomOne, [
                'booking_date' => $tomorrow,
                'booking_time' => '15:00',
                'guest_email' => 'desk@example.com',
                'payment_method' => 'paylater',
                'amount_paid' => 0,
            ]))
            ->assertStatus(201)
            ->json('data.id');

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertFalse((bool) Booking::findOrFail($unpaidOnline)->reminder_sent, 'an unpaid online checkout is not a booking to remind about');
        $this->assertTrue((bool) $paidOnline->fresh()->reminder_sent);
        $this->assertTrue((bool) Booking::findOrFail($payLater)->reminder_sent, 'a pay-later reservation made by staff is still reminded');
    }

    private function defaultWaiverTemplate(): WaiverTemplate
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General Activity Waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'assigned_package_ids' => [],
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        return $template;
    }

    private function glowNight(): Event
    {
        return Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => now()->addDay()->toDateString(),
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);
    }

    private function eventPurchasePayload(Event $event, string $email, array $overrides = []): array
    {
        return array_merge([
            'event_id' => $event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Event Guest',
            'guest_email' => $email,
            'purchase_date' => $event->start_date instanceof \DateTimeInterface ? $event->start_date->format('Y-m-d') : (string) $event->start_date,
            'purchase_time' => '18:00',
            'quantity' => 1,
            'total_amount' => 15,
            'amount_paid' => 0,
            'payment_method' => 'authorize.net',
        ], $overrides);
    }

    private function recordPayment(string $type, int $payableId, float $amount): Payment
    {
        return Payment::create([
            'location_id' => $this->location->id,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'in-store',
            'status' => 'completed',
            'transaction_id' => 'TXN' . uniqid(),
            'payable_type' => $type,
            'payable_id' => $payableId,
            'paid_at' => now(),
        ]);
    }

    public function test_a_pending_booking_that_already_took_a_payment_is_never_deleted_by_the_public_routes(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        $this->recordPayment(Payment::TYPE_BOOKING, $bookingId, 40.00);

        $this->deleteJson("/api/bookings/{$bookingId}")->assertStatus(403);
        $this->deleteJson("/api/bookings/{$bookingId}/force-delete")
            ->assertStatus(403)
            ->assertJsonPath('message', 'This booking has payments, so it cannot be removed this way.');

        $this->gateway->replies[] = $this->declineReply();
        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId, 69.04))->assertStatus(400);

        $this->assertNotNull(Booking::find($bookingId), 'a declined second payment does not throw away a booking that already took money');
    }

    public function test_a_paid_booking_in_the_trash_cannot_be_purged_through_the_public_route(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000001');
        $booking->delete();

        $this->deleteJson("/api/bookings/{$booking->id}/force-delete")
            ->assertStatus(403)
            ->assertJsonPath('message', 'This booking has payments, so it cannot be removed this way.');

        $this->assertNotNull(Booking::withTrashed()->find($booking->id));
    }

    public function test_charging_something_that_does_not_exist_is_refused_before_the_gateway(): void
    {
        $this->postJson('/api/payments/charge', $this->chargePayload(999999))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PAYABLE_NOT_FOUND');

        $this->assertCount(0, $this->gateway->sent);
    }

    public function test_a_booking_removed_while_its_card_was_being_charged_has_the_charge_voided(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->duringNextCall = fn () => Booking::findOrFail($bookingId)->forceDelete();
        $this->gateway->replies[] = $this->transactionReply('1', '60200000020');
        $this->gateway->replies[] = $this->voidReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYABLE_REMOVED');

        $this->assertSame(0, Payment::count(), 'no payment is recorded against a booking that no longer exists');
        $this->assertSame('voidTransaction', $this->gateway->sent[1]['transaction_type']);
        $this->assertSame('60200000020', $this->gateway->sent[1]['ref_trans_id']);
    }

    public function test_a_split_tender_partial_approval_is_voided_by_its_split_tender_id(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $reply = $this->transactionReply('4', '60200000021', '50.00');
        $reply->getTransactionResponse()->setSplitTenderId('900001');
        $this->gateway->replies[] = $reply;
        $this->gateway->replies[] = $this->voidReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'PAYMENT_NOT_APPROVED');

        $this->assertSame('voidTransaction', $this->gateway->sent[1]['transaction_type']);
        $this->assertSame('900001', $this->gateway->sent[1]['split_tender_id']);
        $this->assertSame(0, Payment::count());
    }

    public function test_an_unreadable_gateway_answer_is_treated_as_unknown(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->replies[] = new AnetAPI\CreateTransactionResponse();

        $response = $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))->assertStatus(400);

        $this->assertStringContainsString("can't tell whether your card was charged", $response->json('message'));
        $this->assertNotNull(Notification::where('title', 'Card payment needs checking in Authorize.Net')->first());
    }

    public function test_an_online_card_checkout_starts_with_nothing_paid_until_the_charge_goes_through(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        $this->assertEqualsWithDelta(0.0, (float) Booking::findOrFail($bookingId)->amount_paid, 0.001, 'an unpaid checkout must look unpaid so the sweeper can clear it');

        $this->gateway->replies[] = $this->transactionReply('1', '60200000022');
        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))->assertOk();

        $this->assertEqualsWithDelta(109.04, (float) Booking::findOrFail($bookingId)->amount_paid, 0.001);
    }

    public function test_a_keyed_retry_finds_its_booking_with_no_email_and_after_the_time_changed(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomOne, [
                'guest_email' => null,
                'payment_method' => 'in-store',
                'checkout_key' => 'desk-attempt-1',
            ]))
            ->assertStatus(201);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomTwo, [
                'guest_email' => null,
                'payment_method' => 'in-store',
                'booking_time' => '21:00',
                'checkout_key' => 'desk-attempt-1',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_BOOKED')
            ->assertJsonPath('booking.booking_time', '20:00');

        $this->assertSame(1, Booking::count());
    }

    public function test_already_booked_describes_the_booking_that_exists_not_the_edited_form(): void
    {
        $first = $this->bookAndPay($this->roomOne, '60200000001', ['checkout_key' => 'attempt-one']);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'attempt-one', 'participants' => 4, 'total_amount' => 200]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_BOOKED')
            ->assertJsonPath('reference_number', $first->reference_number)
            ->assertJsonPath('booking.participants', 2)
            ->assertJsonPath('booking.amount_paid', 109.04)
            ->assertJsonPath('confirmation_pending', false);
    }

    private function copyOfWaiver(Waiver $waiver, array $changes): Waiver
    {
        $copy = $waiver->replicate();
        $copy->forceFill($changes + ['access_token' => \Illuminate\Support\Str::random(48)]);
        if (Waiver::supportsReferenceNumber()) {
            $copy->reference_number = Waiver::generateReference();
        }
        $copy->save();

        return $copy;
    }

    private function eventPurchaseWaiver(int $eventPurchaseId): Waiver
    {
        return app(WaiverService::class)->ensureForEventPurchase(EventPurchase::findOrFail($eventPurchaseId));
    }

    public function test_the_waiver_cleanup_leaves_signed_and_staff_assigned_waivers_alone(): void
    {
        $this->defaultWaiverTemplate();

        $bookingId = $this->createBooking($this->roomOne);
        $placeholder = Waiver::where('booking_id', $bookingId)->firstOrFail();

        $signed = $this->copyOfWaiver($placeholder, ['status' => Waiver::STATUS_COMPLETED]);
        $assigned = $this->copyOfWaiver($placeholder, ['is_manager_assigned' => true]);

        $this->deleteJson("/api/bookings/{$bookingId}/force-delete")->assertOk();

        $this->assertTrue(Waiver::withTrashed()->findOrFail($placeholder->id)->trashed());
        $this->assertFalse(Waiver::withTrashed()->findOrFail($signed->id)->trashed());
        $this->assertFalse(Waiver::withTrashed()->findOrFail($assigned->id)->trashed());
    }

    public function test_declined_attraction_and_event_checkouts_do_not_leave_waivers_that_send_visit_reminders(): void
    {
        $this->defaultWaiverTemplate();
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        $purchaseId = (int) $this->postJson('/api/attraction-purchases', [
            'attraction_id' => $attraction->id,
            'guest_name' => 'Attraction Guest',
            'guest_email' => 'axes@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 20,
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '18:00',
            'payment_method' => 'authorize.net',
        ])->assertStatus(201)->json('data.id');

        $this->assertEqualsWithDelta(0.0, (float) AttractionPurchase::findOrFail($purchaseId)->amount_paid, 0.001);
        $attractionWaiver = Waiver::where('attraction_purchase_id', $purchaseId)->firstOrFail();

        $this->gateway->replies[] = $this->declineReply();
        $this->postJson('/api/payments/charge', $this->chargePayload($purchaseId, 20.00, Payment::TYPE_ATTRACTION_PURCHASE))->assertStatus(400);

        $eventPurchase = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($this->glowNight(), 'glow@example.com'))->assertSuccessful();
        $eventPurchaseId = (int) ($eventPurchase->json('data.id') ?? $eventPurchase->json('id'));
        $eventWaiver = $this->eventPurchaseWaiver($eventPurchaseId);
        $this->assertSame($eventPurchaseId, (int) $eventWaiver->event_purchase_id);

        $this->gateway->replies[] = $this->declineReply('60200000098');
        $this->postJson('/api/payments/charge', $this->chargePayload($eventPurchaseId, 15.00, Payment::TYPE_EVENT_PURCHASE))->assertStatus(400);

        $this->assertNull(AttractionPurchase::withTrashed()->find($purchaseId));
        $this->assertNull(EventPurchase::withTrashed()->find($eventPurchaseId));
        $this->assertTrue(Waiver::withTrashed()->findOrFail($attractionWaiver->id)->trashed());
        $this->assertTrue(Waiver::withTrashed()->findOrFail($eventWaiver->id)->trashed());

        $due = app(WaiverService::class)->dueForReminder(24 * 7)->pluck('id');
        $this->assertFalse($due->contains($attractionWaiver->id));
        $this->assertFalse($due->contains($eventWaiver->id));
    }

    public function test_waiver_reminders_skip_cancelled_bookings_and_checkouts_that_were_never_paid(): void
    {
        $this->defaultWaiverTemplate();
        $tomorrow = now()->addDay()->toDateString();

        $unpaidId = $this->createBooking($this->roomOne, ['booking_date' => $tomorrow]);
        $paid = $this->bookAndPay($this->roomTwo, '60200000030', ['booking_date' => $tomorrow, 'guest_email' => 'paid@example.com']);
        $cancelled = $this->bookAndPay($this->roomOne, '60200000031', ['booking_date' => $tomorrow, 'booking_time' => '15:00', 'guest_email' => 'cancelled@example.com']);
        $cancelled->update(['status' => 'cancelled']);

        $due = app(WaiverService::class)->dueForReminder(48)->pluck('booking_id');

        $this->assertTrue($due->contains($paid->id));
        $this->assertFalse($due->contains($unpaidId), 'an online checkout that was never paid is not a visit');
        $this->assertFalse($due->contains($cancelled->id), 'a cancelled booking is not a visit');
    }

    public function test_the_event_shortcut_reuses_only_an_identical_unpaid_purchase(): void
    {
        $event = $this->glowNight();

        $first = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'glow@example.com'))->assertSuccessful();
        $firstId = (int) ($first->json('data.id') ?? $first->json('id'));

        $same = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'glow@example.com'))->assertSuccessful();
        $this->assertSame($firstId, (int) ($same->json('data.id') ?? $same->json('id')), 'an identical retry still reuses the unpaid purchase');

        $changed = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'glow@example.com', ['total_amount' => 18]))->assertSuccessful();
        $this->assertNotSame($firstId, (int) ($changed->json('data.id') ?? $changed->json('id')), 'a different total is a different purchase');

        $this->recordPayment(Payment::TYPE_EVENT_PURCHASE, $firstId, 15.00);
        $afterPayment = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'glow@example.com'))->assertSuccessful();
        $this->assertNotSame($firstId, (int) ($afterPayment->json('data.id') ?? $afterPayment->json('id')), 'a purchase that took money is never handed to a new checkout');
    }

    public function test_rolling_back_one_guests_order_leaves_other_guests_waivers_alone(): void
    {
        $this->defaultWaiverTemplate();
        $event = $this->glowNight();
        $eventDate = $event->start_date instanceof \DateTimeInterface ? $event->start_date->format('Y-m-d') : (string) $event->start_date;

        $other = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'someone-else@example.com'))->assertSuccessful();
        $otherWaiver = $this->eventPurchaseWaiver((int) ($other->json('data.id') ?? $other->json('id')));

        $order = $this->postJson('/api/ticket-orders', [
            'items' => [['type' => 'event', 'id' => $event->id, 'quantity' => 1, 'scheduled_date' => $eventDate, 'scheduled_time' => '18:00']],
            'guest_name' => 'Cart Guest',
            'guest_email' => 'cart@example.com',
            'payment_method' => 'authorize.net',
        ])->assertSuccessful();
        $orderId = (int) ($order->json('data.id') ?? $order->json('id'));
        $cartWaiver = Waiver::where('event_id', $event->id)->where('adult_email', 'cart@example.com')->firstOrFail();

        $this->deleteJson("/api/ticket-orders/{$orderId}/rollback")->assertSuccessful();

        $this->assertFalse(Waiver::withTrashed()->findOrFail($otherWaiver->id)->trashed(), "another guest's waiver for the same event and day survives");
        $this->assertTrue(Waiver::withTrashed()->findOrFail($cartWaiver->id)->trashed(), "the cart guest's own unsigned waiver goes with the order");
    }

    public function test_a_changed_retry_while_the_first_attempt_may_still_be_charging_is_asked_to_wait(): void
    {
        $firstId = $this->createBooking($this->roomOne, ['checkout_key' => 'attempt-one']);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'attempt-one', 'participants' => 3]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ATTEMPT_IN_PROGRESS');

        $this->assertNotNull(Booking::find($firstId));
    }

    public function test_an_abandoned_uncharged_attempt_is_released_so_its_own_retry_can_book_the_space(): void
    {
        $firstId = $this->createBooking($this->roomOne, ['checkout_key' => 'attempt-one']);
        $this->travel(3)->minutes();

        $retryId = (int) $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'attempt-one', 'participants' => 3]))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertNotSame($firstId, $retryId);
        $this->assertNull(Booking::withTrashed()->find($firstId), 'the abandoned attempt no longer holds the space');
        $this->assertSame(1, Booking::count());
    }

    public function test_an_identical_retry_long_after_the_first_attempt_makes_a_fresh_booking(): void
    {
        $firstId = $this->createBooking($this->roomOne, ['checkout_key' => 'attempt-one']);
        $this->travel(31)->minutes();

        $retryId = (int) $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'attempt-one']))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertNotSame($firstId, $retryId, 'an attempt the sweeper may be about to remove is never reused');
    }

    public function test_a_stale_key_on_the_next_guest_starts_a_new_booking(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['payment_method' => 'in-store', 'checkout_key' => 'desk-attempt-1']))
            ->assertStatus(201);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomTwo, [
                'guest_name' => 'Next Walk In',
                'guest_email' => 'next@example.com',
                'payment_method' => 'in-store',
                'checkout_key' => 'desk-attempt-1',
            ]))
            ->assertStatus(201);

        $this->assertSame(2, Booking::count());
    }

    public function test_a_confirmed_booking_whose_confirmation_never_went_out_is_flagged(): void
    {
        $bookingId = $this->createBooking($this->roomOne, ['checkout_key' => 'gift-attempt']);
        Booking::findOrFail($bookingId)->update(['status' => 'confirmed', 'payment_status' => 'paid', 'total_amount' => 0]);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'gift-attempt']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_BOOKED')
            ->assertJsonPath('booking_id', $bookingId)
            ->assertJsonPath('confirmation_pending', true);
    }

    public function test_a_staff_card_booking_starts_unpaid_until_its_charge_goes_through(): void
    {
        $staff = $this->staff();

        $bookingId = (int) $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['amount_paid' => 109.04]))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertEqualsWithDelta(0.0, (float) Booking::findOrFail($bookingId)->amount_paid, 0.001, 'a kept booking must never read as paid before the card is charged');
    }

    public function test_the_public_soft_delete_cannot_remove_a_paid_or_used_purchase(): void
    {
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
        $staff = $this->staff();
        $payload = [
            'attraction_id' => $attraction->id,
            'guest_name' => 'Axe Guest',
            'guest_email' => 'axes@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 20,
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '18:00',
        ];

        $paidId = (int) $this->actingAs($staff, 'sanctum')
            ->postJson('/api/attraction-purchases', $payload + ['payment_method' => 'in-store'])
            ->assertStatus(201)->json('data.id');
        $this->app['auth']->forgetGuards();

        $this->deleteJson("/api/attraction-purchases/{$paidId}")->assertStatus(403);
        $this->assertNotNull(AttractionPurchase::find($paidId));

        $pendingId = (int) $this->postJson('/api/attraction-purchases', array_merge($payload, ['guest_email' => 'pending@example.com', 'payment_method' => 'authorize.net']))
            ->assertStatus(201)->json('data.id');
        $this->deleteJson("/api/attraction-purchases/{$pendingId}")->assertOk();

        $event = $this->glowNight();
        $eventPaid = $this->actingAs($staff, 'sanctum')
            ->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'eventpaid@example.com', ['payment_method' => 'in-store', 'amount_paid' => 15]))
            ->assertSuccessful();
        $eventPaidId = (int) ($eventPaid->json('data.id') ?? $eventPaid->json('id'));
        $this->recordPayment(Payment::TYPE_EVENT_PURCHASE, $eventPaidId, 15.00);
        $this->app['auth']->forgetGuards();

        $this->deleteJson("/api/event-purchases/{$eventPaidId}")->assertStatus(403);
        $this->assertNotNull(EventPurchase::find($eventPaidId));
    }

    public function test_the_guest_is_told_when_a_charge_for_a_removed_booking_could_not_be_cancelled(): void
    {
        $bookingId = $this->createBooking($this->roomOne);

        $this->gateway->duringNextCall = fn () => Booking::findOrFail($bookingId)->forceDelete();
        $this->gateway->replies[] = $this->transactionReply('1', '60200000040');
        $this->gateway->replies[] = $this->voidReply('Error');

        $response = $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYABLE_REMOVED');

        $this->assertStringContainsString('could not cancel the payment automatically', $response->json('message'));
        $this->assertNotNull(Notification::where('title', 'Card payment needs action in Authorize.Net')->first());
    }

    public function test_an_event_purchase_without_a_total_is_not_a_server_error(): void
    {
        $event = $this->glowNight();
        $payload = $this->eventPurchasePayload($event, 'nototal@example.com');
        unset($payload['total_amount']);

        $response = $this->actingAs($this->staff(), 'sanctum')->postJson('/api/event-purchases', $payload);

        $this->assertLessThan(500, $response->status(), $response->getContent());
    }

    public function test_a_pending_booking_that_already_took_money_is_still_reminded(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $bookingId = $this->createBooking($this->roomOne, ['booking_date' => $tomorrow]);
        $this->recordPayment(Payment::TYPE_BOOKING, $bookingId, 40.00);
        Booking::findOrFail($bookingId)->update(['amount_paid' => 40]);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertTrue((bool) Booking::findOrFail($bookingId)->reminder_sent);
    }

    public function test_a_declined_cart_order_does_not_leave_an_event_day_waiver_that_sends_reminders(): void
    {
        $this->defaultWaiverTemplate();
        $event = $this->glowNight();
        $eventDate = $event->start_date instanceof \DateTimeInterface ? $event->start_date->format('Y-m-d') : (string) $event->start_date;

        $order = $this->postJson('/api/ticket-orders', [
            'items' => [['type' => 'event', 'id' => $event->id, 'quantity' => 1, 'scheduled_date' => $eventDate, 'scheduled_time' => '18:00']],
            'guest_name' => 'Cart Guest',
            'guest_email' => 'cart@example.com',
            'payment_method' => 'authorize.net',
        ])->assertSuccessful();
        $orderId = (int) ($order->json('data.id') ?? $order->json('id'));
        $orderModel = \App\Models\TicketOrder::findOrFail($orderId);
        $lineId = (int) $orderModel->eventPurchases()->value('id');

        $waiver = Waiver::where('event_id', $event->id)->where('adult_email', 'cart@example.com')->firstOrFail();
        $this->assertSame($lineId, (int) $waiver->event_purchase_id, 'the event day waiver is tied to the order line it was made for');

        $this->gateway->replies[] = $this->declineReply('60200000041');
        $this->postJson('/api/payments/charge', $this->chargePayload($orderId, (float) $orderModel->total_amount, Payment::TYPE_TICKET_ORDER))->assertStatus(400);

        $this->assertTrue(Waiver::withTrashed()->findOrFail($waiver->id)->trashed());
        $this->assertFalse(app(WaiverService::class)->dueForReminder(24 * 7)->contains('id', $waiver->id));
    }

    public function test_an_event_confirmation_never_links_another_guests_waiver(): void
    {
        $this->defaultWaiverTemplate();
        $event = $this->glowNight();

        $first = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'first@example.com'))->assertSuccessful();
        $this->eventPurchaseWaiver((int) ($first->json('data.id') ?? $first->json('id')));

        $second = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'second@example.com'))->assertSuccessful();
        $secondPurchase = EventPurchase::findOrFail((int) ($second->json('data.id') ?? $second->json('id')));

        $service = app(\App\Services\EmailNotificationService::class);
        $link = (fn () => $this->waiverLinkFor($secondPurchase, 'event'))->call($service);

        $this->assertSame('', $link, "the second guest must not be sent the first guest's waiver");
    }

    public function test_a_held_gift_card_charge_is_refused_and_released(): void
    {
        $this->gateway->replies[] = $this->transactionReply('4', '60200000011');
        $this->gateway->replies[] = $this->heldUpdateReply('Ok');

        $result = app(AuthorizeNetCharger::class)->charge(
            $this->account,
            25.00,
            ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'opaque-token'],
            ['first_name' => 'Gift', 'last_name' => 'Buyer', 'email' => 'buyer@example.com'],
            'GC1',
            'GC1',
            'Zap Zone gift card'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('decline', $this->gateway->sent[1]['held_action']);
    }

    public function test_an_attraction_purchase_for_another_day_is_not_merged_into_an_unpaid_one(): void
    {
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        $payload = fn (string $date) => [
            'attraction_id' => $attraction->id,
            'guest_name' => 'La Shawn Thomas',
            'guest_email' => 'guest@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => $date,
            'scheduled_time' => '18:00',
            'payment_method' => 'authorize.net',
        ];

        $first = $this->postJson('/api/attraction-purchases', $payload(now()->addDays(2)->toDateString()))->assertStatus(201);
        $retry = $this->postJson('/api/attraction-purchases', $payload(now()->addDays(2)->toDateString()))->assertOk();
        $this->assertSame($first->json('data.id'), $retry->json('data.id'));

        $otherDay = $this->postJson('/api/attraction-purchases', $payload(now()->addDays(5)->toDateString()))->assertStatus(201);
        $this->assertNotSame($first->json('data.id'), $otherDay->json('data.id'));
        $this->assertSame(2, AttractionPurchase::count());
    }

    public function test_event_walk_ins_without_an_email_are_not_merged(): void
    {
        $event = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => now()->addDays(7)->toDateString(),
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);

        $walkIn = [
            'event_id' => $event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Walk In',
            'guest_email' => '',
            'purchase_date' => now()->addDays(7)->toDateString(),
            'purchase_time' => '18:00',
            'quantity' => 1,
            'total_amount' => 15,
            'payment_method' => 'paylater',
        ];

        $staff = $this->staff();
        $this->actingAs($staff, 'sanctum')->postJson('/api/event-purchases', $walkIn)->assertSuccessful();
        $this->actingAs($staff, 'sanctum')->postJson('/api/event-purchases', $walkIn)->assertSuccessful();

        $this->assertSame(2, EventPurchase::count());
    }
    private function customer(string $email): Customer
    {
        return Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Customer',
            'email' => $email,
            'phone' => '7345550000',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
    }

    private function chargeLock(int $payableId, string $type = Payment::TYPE_BOOKING): \Illuminate\Contracts\Cache\Lock
    {
        return Cache::lock('payable-charge:' . $type . ':' . $payableId, 180);
    }

    private function qrPayload(array $overrides = []): array
    {
        return array_merge(['qr_code' => 'data:image/png;base64,' . base64_encode("\x89PNG test")], $overrides);
    }

    public function test_a_second_charge_while_the_first_is_still_running_does_not_reach_the_gateway(): void
    {
        config(['checkout.charge_lock_wait_seconds' => 0]);
        $bookingId = $this->createBooking($this->roomOne);
        $firstCharge = $this->chargeLock($bookingId);
        $this->assertTrue($firstCharge->get());

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CHARGE_IN_PROGRESS');

        $this->assertSame([], $this->gateway->sent, 'the second charge never reaches the card processor');
        $this->assertTrue(Booking::whereKey($bookingId)->exists(), 'the booking the first charge is paying for is left alone');

        $firstCharge->release();
    }

    public function test_the_charge_lock_is_let_go_after_a_charge_succeeds_or_fails(): void
    {
        $paid = $this->bookAndPay($this->roomOne, '60200000040');
        $afterSuccess = $this->chargeLock($paid->id);
        $this->assertTrue($afterSuccess->get(), 'a finished charge leaves the booking free to be charged again');
        $afterSuccess->release();

        $declinedId = $this->createBooking($this->roomTwo, ['guest_email' => 'declined@example.com']);
        $this->gateway->replies[] = $this->declineReply();
        $this->postJson('/api/payments/charge', $this->chargePayload($declinedId))->assertStatus(400);

        $afterDecline = $this->chargeLock($declinedId);
        $this->assertTrue($afterDecline->get(), 'a declined charge leaves nothing locked');
        $afterDecline->release();
    }

    public function test_an_approved_charge_that_cannot_be_saved_is_voided_and_not_booked(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        Payment::creating(function () {
            throw new \RuntimeException('The payments table is unavailable');
        });

        $this->gateway->replies[] = $this->transactionReply('1', '60200000041');
        $this->gateway->replies[] = $this->voidReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'PAYMENT_NOT_RECORDED');

        $this->assertSame('voidTransaction', $this->gateway->sent[1]['transaction_type']);
        $this->assertSame('60200000041', $this->gateway->sent[1]['ref_trans_id']);
        $this->assertFalse(Booking::withTrashed()->whereKey($bookingId)->exists(), 'the unpaid checkout is rolled back');
        $this->assertSame(0, Notification::where('title', 'Card payment needs action in Authorize.Net')->count(), 'a charge that was voided needs no staff action');
    }

    public function test_an_approved_charge_that_cannot_be_saved_or_voided_alerts_staff(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        Payment::creating(function () {
            throw new \RuntimeException('The payments table is unavailable');
        });

        $this->gateway->replies[] = $this->transactionReply('1', '60200000042');
        $this->gateway->replies[] = $this->voidReply('Error');

        $response = $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'PAYMENT_NOT_RECORDED');

        $this->assertStringContainsString('will refund', $response->json('message'));
        $alert = Notification::where('title', 'Card payment needs action in Authorize.Net')->firstOrFail();
        $this->assertSame('payment_not_recorded', $alert->metadata['reason']);
        $this->assertSame('60200000042', $alert->metadata['transaction_id']);
    }

    public function test_a_charge_saved_just_before_a_later_error_is_reported_as_paid_and_never_voided(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        Payment::created(function () {
            throw new \RuntimeException('A listener failed after the payment was saved');
        });

        $this->gateway->replies[] = $this->transactionReply('1', '60200000043');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction_id', '60200000043');

        $this->assertCount(1, $this->gateway->sent, 'a payment that was saved is never voided');
        $this->assertSame(1, Payment::where('transaction_id', '60200000043')->count());
        $this->assertTrue(Booking::whereKey($bookingId)->exists(), 'the paid booking is kept');
    }

    public function test_a_failure_updating_the_booking_after_the_payment_is_saved_still_reports_paid(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        Booking::updating(function () {
            throw new \RuntimeException('The booking could not be updated');
        });

        $this->gateway->replies[] = $this->transactionReply('1', '60200000044');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(1, $this->gateway->sent);
        $this->assertSame(1, Payment::where('payable_id', $bookingId)->where('status', 'completed')->count());
        $this->assertTrue(Booking::whereKey($bookingId)->exists());
    }

    public function test_a_pending_checkout_marked_paid_without_any_payment_gets_no_visit_reminder(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $bookingId = $this->createBooking($this->roomOne, ['booking_date' => $tomorrow]);
        Booking::findOrFail($bookingId)->update(['amount_paid' => 109.04]);

        $this->artisan('bookings:send-reminders')->assertSuccessful();

        $this->assertFalse((bool) Booking::findOrFail($bookingId)->reminder_sent, 'an amount typed onto a checkout that never took a payment is not money');
    }

    public function test_a_guest_cannot_send_a_confirmation_for_an_unpaid_booking_through_the_qr_route(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        $this->mock(EmailNotificationService::class, fn ($mock) => $mock->shouldNotReceive('triggerBookingNotification'));

        $this->postJson("/api/bookings/{$bookingId}/qrcode", $this->qrPayload())
            ->assertOk()
            ->assertJsonPath('data.email_sent', false);

        $this->assertNotEmpty(Booking::findOrFail($bookingId)->qr_code_path, 'the QR code itself is still stored');
    }

    public function test_a_guest_cannot_replace_a_bookings_qr_code_or_resend_its_confirmation(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000045');
        $booking->update(['qr_code_path' => 'qrcodes/original.png']);
        $this->mock(EmailNotificationService::class, fn ($mock) => $mock->shouldNotReceive('triggerBookingNotification'));

        $this->postJson("/api/bookings/{$booking->id}/qrcode", $this->qrPayload())
            ->assertOk()
            ->assertJsonPath('message', 'QR code already stored')
            ->assertJsonPath('data.email_sent', false);

        $this->assertSame('qrcodes/original.png', $booking->fresh()->qr_code_path);
    }

    public function test_a_guest_cannot_use_the_qr_route_on_an_old_booking(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000046');
        Booking::whereKey($booking->id)->update(['created_at' => now()->subDays(3)]);
        $this->mock(EmailNotificationService::class, fn ($mock) => $mock->shouldNotReceive('triggerBookingNotification'));

        $this->postJson("/api/bookings/{$booking->id}/qrcode", $this->qrPayload())
            ->assertOk()
            ->assertJsonPath('data.email_sent', false);

        $this->assertNull($booking->fresh()->qr_code_path);
    }

    public function test_a_confirmed_booking_without_a_qr_code_still_gets_its_confirmation(): void
    {
        $booking = $this->bookAndPay($this->roomOne, '60200000047');
        $this->mock(EmailNotificationService::class, fn ($mock) => $mock->shouldReceive('triggerBookingNotification')->once());

        $this->postJson("/api/bookings/{$booking->id}/qrcode", $this->qrPayload())
            ->assertOk()
            ->assertJsonPath('data.email_sent', true);
    }

    public function test_staff_can_still_send_a_confirmation_for_a_pay_later_booking(): void
    {
        $staff = $this->staff();
        $bookingId = (int) $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['payment_method' => 'paylater', 'amount_paid' => 0]))
            ->assertStatus(201)
            ->json('data.id');
        $this->mock(EmailNotificationService::class, fn ($mock) => $mock->shouldReceive('triggerBookingNotification')->once());

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/bookings/{$bookingId}/qrcode", $this->qrPayload())
            ->assertOk()
            ->assertJsonPath('data.email_sent', true);
    }

    public function test_the_customer_booking_list_needs_a_login_and_shows_customers_only_their_own(): void
    {
        $mine = $this->createBooking($this->roomOne, ['guest_email' => 'pat@example.com', 'guest_name' => 'Pat Customer']);
        $this->createBooking($this->roomTwo, ['guest_email' => 'someone@example.com', 'guest_name' => 'Someone Else']);

        $this->getJson('/api/customers/bookings')->assertStatus(401);
        $this->getJson('/api/customers/bookings?guest_email=someone@example.com')->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer ' . $this->customer('pat@example.com')->createToken('portal')->plainTextToken);

        $this->assertSame([$mine], collect($this->getJson('/api/customers/bookings')->assertOk()->json('data.bookings'))->pluck('id')->all());
        $this->assertSame([], $this->getJson('/api/customers/bookings?guest_email=someone@example.com')->assertOk()->json('data.bookings'), 'a customer cannot look another guest up by email');
    }

    public function test_the_customer_purchase_lists_need_a_login_and_show_customers_only_their_own(): void
    {
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
        $attractionPayload = fn (string $email) => [
            'attraction_id' => $attraction->id,
            'guest_name' => 'Axe Guest',
            'guest_email' => $email,
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 0,
            'payment_method' => 'authorize.net',
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '18:00',
        ];
        $myAttraction = (int) $this->postJson('/api/attraction-purchases', $attractionPayload('pat@example.com'))->assertStatus(201)->json('data.id');
        $this->postJson('/api/attraction-purchases', $attractionPayload('someone@example.com'))->assertStatus(201);

        $event = $this->glowNight();
        $mine = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'pat@example.com'))->assertSuccessful();
        $myEvent = (int) ($mine->json('data.id') ?? $mine->json('id'));
        $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'someone@example.com'))->assertSuccessful();

        $this->getJson('/api/attraction-purchases/customer')->assertStatus(401);
        $this->getJson('/api/event-purchases/customer?guest_email=someone@example.com')->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer ' . $this->customer('pat@example.com')->createToken('portal')->plainTextToken);

        $this->assertSame([$myAttraction], collect($this->getJson('/api/attraction-purchases/customer?guest_email=pat@example.com')->assertOk()->json('data.purchases'))->pluck('id')->all());
        $this->assertSame([], $this->getJson('/api/attraction-purchases/customer?guest_email=someone@example.com')->assertOk()->json('data.purchases'));
        $this->assertSame([$myEvent], collect($this->getJson('/api/event-purchases/customer?guest_email=pat@example.com')->assertOk()->json('data.purchases'))->pluck('id')->all());
        $this->assertSame([], $this->getJson('/api/event-purchases/customer?guest_email=someone@example.com')->assertOk()->json('data.purchases'));
    }

    public function test_staff_can_still_look_any_guest_up_in_the_customer_lists(): void
    {
        $theirs = $this->createBooking($this->roomTwo, ['guest_email' => 'someone@example.com', 'guest_name' => 'Someone Else']);

        $this->actingAs($this->staff(), 'sanctum');

        $this->assertSame([$theirs], collect($this->getJson('/api/customers/bookings?guest_email=someone@example.com')->assertOk()->json('data.bookings'))->pluck('id')->all());
        $this->getJson('/api/attraction-purchases/customer')->assertOk();
        $this->getJson('/api/event-purchases/customer')->assertOk();
    }

    public function test_a_waiver_left_behind_by_a_deleted_checkout_gets_no_reminder(): void
    {
        $this->defaultWaiverTemplate();
        $tomorrow = now()->addDay()->toDateString();
        $paid = $this->bookAndPay($this->roomOne, '60200000050', ['booking_date' => $tomorrow]);
        $paidWaiver = Waiver::where('booking_id', $paid->id)->firstOrFail();

        $orphan = $this->copyOfWaiver($paidWaiver, ['booking_id' => null, 'adult_email' => 'gone@example.com']);
        $assigned = $this->copyOfWaiver($paidWaiver, ['booking_id' => null, 'adult_email' => 'assigned@example.com', 'is_manager_assigned' => true]);
        $kiosk = $this->copyOfWaiver($paidWaiver, ['booking_id' => null, 'adult_email' => 'kiosk@example.com', 'source' => Waiver::SOURCE_KIOSK]);

        $due = app(WaiverService::class)->dueForReminder(48)->pluck('id');

        $this->assertTrue($due->contains($paidWaiver->id), 'the paid booking keeps its reminder');
        $this->assertFalse($due->contains($orphan->id), 'a checkout placeholder whose booking is gone is not reminded');
        $this->assertTrue($due->contains($assigned->id), 'a waiver a manager sent is still reminded');
        $this->assertTrue($due->contains($kiosk->id), 'a waiver started at the kiosk is still reminded');
    }

    public function test_releasing_an_abandoned_attempt_is_written_to_the_activity_log(): void
    {
        $firstId = $this->createBooking($this->roomOne, ['checkout_key' => 'attempt-logged']);
        $this->travel(3)->minutes();

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'attempt-logged', 'participants' => 3]))
            ->assertStatus(201);

        $this->assertTrue(\App\Models\ActivityLog::where('action', 'Booking Force Deleted (Abandoned Checkout)')->where('entity_id', $firstId)->exists());
    }

    public function test_a_pay_later_booking_is_never_released_by_a_changed_retry(): void
    {
        $staff = $this->staff();
        $firstId = (int) $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'desk-attempt', 'payment_method' => 'paylater', 'amount_paid' => 0]))
            ->assertStatus(201)
            ->json('data.id');
        $this->travel(3)->minutes();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomTwo, ['checkout_key' => 'desk-attempt', 'payment_method' => 'paylater', 'amount_paid' => 0, 'participants' => 3]))
            ->assertStatus(201);

        $this->assertNotNull(Booking::find($firstId), 'a pay-later reservation is not an abandoned card checkout');
    }

    public function test_a_gift_card_charge_with_no_answer_alerts_staff_and_says_the_outcome_is_unknown(): void
    {
        $this->gateway->replies[] = FakeAuthorizeNetGateway::NO_ANSWER;

        $result = app(AuthorizeNetCharger::class)->charge(
            $this->account,
            25.00,
            ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'opaque-token'],
            ['first_name' => 'Gift', 'last_name' => 'Buyer', 'email' => 'buyer@example.com'],
            'GC2',
            'GC2',
            'Zap Zone gift card'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AuthorizeNetGateway::NO_ANSWER_MESSAGE, $result['error']);
        $alert = Notification::where('title', 'Card payment needs checking in Authorize.Net')->firstOrFail();
        $this->assertSame('no_gateway_answer', $alert->metadata['reason']);
        $this->assertSame('GC2', $alert->metadata['reference']);
    }

    public function test_a_saved_card_charge_with_no_answer_alerts_staff(): void
    {
        $this->gateway->replies[] = FakeAuthorizeNetGateway::NO_ANSWER;

        $result = app(\App\Services\AuthorizeNetProfileService::class)->chargeProfile($this->account, '900100', '900200', 30.00, 'MB1-1', 'Membership renewal - Explorer');

        $this->assertFalse($result['success']);
        $this->assertSame(AuthorizeNetGateway::NO_ANSWER_MESSAGE, $result['error']);
        $this->assertSame('no_gateway_answer', Notification::where('title', 'Card payment needs checking in Authorize.Net')->firstOrFail()->metadata['reason']);
    }

    public function test_a_declined_online_membership_purchase_sends_no_payment_failed_notice(): void
    {
        $plan = \App\Models\MembershipPlan::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Local Explorer',
            'slug' => 'local-explorer',
            'tier' => 'basic',
            'price' => 29.99,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        $token = $this->customer('member@example.com')->createToken('portal')->plainTextToken;
        $this->gateway->replies[] = $this->declineReply();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/memberships/purchase', [
                'membership_plan_id' => $plan->id,
                'home_location_id' => $this->location->id,
                'opaque_data' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'opaque-token'],
                'terms_accepted' => true,
                'recurring_billing_authorized' => true,
            ])
            ->assertStatus(402);

        $this->assertSame(0, \App\Models\MembershipPayment::count(), 'no failed payment is recorded, so no "update your payment method" notice goes out');
        $this->assertSame(0, \App\Models\Membership::count());
    }

    public function test_a_cancelled_event_checkout_leaves_an_older_waiver_it_picked_up_alone(): void
    {
        $this->defaultWaiverTemplate();
        $event = $this->glowNight();
        $customer = $this->customer('pat@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $customer->createToken('portal')->plainTextToken);

        $earlier = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'pat@example.com', ['customer_id' => $customer->id, 'quantity' => 2, 'total_amount' => 30]))->assertSuccessful();
        $earlierWaiver = $this->eventPurchaseWaiver((int) ($earlier->json('data.id') ?? $earlier->json('id')));
        Waiver::whereKey($earlierWaiver->id)->update(['event_purchase_id' => null, 'created_at' => now()->subHour()]);

        $later = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'pat@example.com', ['customer_id' => $customer->id]))->assertSuccessful();
        $laterId = (int) ($later->json('data.id') ?? $later->json('id'));
        $this->assertSame($laterId, (int) Waiver::findOrFail($earlierWaiver->id)->event_purchase_id, 'the new checkout picked up the older waiver');

        $this->deleteJson("/api/event-purchases/{$laterId}/force-delete")->assertOk();

        $kept = Waiver::find($earlierWaiver->id);
        $this->assertNotNull($kept, 'a waiver the cancelled checkout did not create is left alone');
        $this->assertSame(Waiver::STATUS_PENDING, $kept->status);
    }

    public function test_a_checkout_without_a_staff_login_cannot_mark_itself_paid_at_the_venue(): void
    {
        $booking = $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['payment_method' => 'in-store', 'amount_paid' => 109.04]))
            ->assertStatus(201);
        $this->assertSame('paylater', $booking->json('data.payment_method'));
        $this->assertSame('pending', $booking->json('data.status'));
        $this->assertEquals(0, (float) $booking->json('data.amount_paid'));

        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
        $purchase = $this->postJson('/api/attraction-purchases', [
            'attraction_id' => $attraction->id,
            'guest_name' => 'Axe Guest',
            'guest_email' => 'axes@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 20,
            'payment_method' => 'card',
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '18:00',
        ])->assertStatus(201);
        $this->assertSame('paylater', $purchase->json('data.payment_method'));
        $this->assertSame('pending', $purchase->json('data.status'));
        $this->assertEquals(0, (float) $purchase->json('data.amount_paid'));

        $eventPurchase = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($this->glowNight(), 'event@example.com', ['payment_method' => 'in-store', 'amount_paid' => 15]))
            ->assertSuccessful();
        $eventId = (int) ($eventPurchase->json('data.id') ?? $eventPurchase->json('id'));
        $stored = EventPurchase::findOrFail($eventId);
        $this->assertSame('paylater', $stored->payment_method);
        $this->assertSame('pending', $stored->status);
        $this->assertEquals(0, (float) $stored->amount_paid);
    }

    public function test_staff_can_still_record_a_payment_taken_at_the_venue(): void
    {
        $booking = $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['payment_method' => 'in-store', 'amount_paid' => 109.04]))
            ->assertStatus(201);

        $this->assertSame('in-store', $booking->json('data.payment_method'));
        $this->assertSame('confirmed', $booking->json('data.status'));
    }

    public function test_a_keyed_retry_of_a_cancelled_booking_books_again_instead_of_showing_it_as_confirmed(): void
    {
        $cancelled = $this->bookAndPay($this->roomOne, '60200000060', ['checkout_key' => 'cancelled-attempt']);
        $cancelled->update(['status' => 'cancelled']);

        $retryId = (int) $this->postJson('/api/bookings', $this->bookingPayload($this->roomTwo, ['checkout_key' => 'cancelled-attempt']))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertNotSame($cancelled->id, $retryId);
    }

    public function test_a_deleted_checkout_gives_back_the_membership_benefit_it_used(): void
    {
        $customer = $this->customer('member@example.com');
        $plan = \App\Models\MembershipPlan::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Local Explorer',
            'slug' => 'local-explorer',
            'tier' => 'basic',
            'price' => 29.99,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        $membership = \App\Models\Membership::create([
            'customer_id' => $customer->id,
            'membership_plan_id' => $plan->id,
            'home_location_id' => $this->location->id,
            'status' => 'active',
            'billing_amount' => $plan->price,
        ]);
        $bookingId = $this->createBooking($this->roomOne);
        $redemption = \App\Models\MembershipBenefitRedemption::create([
            'membership_id' => $membership->id,
            'customer_id' => $customer->id,
            'location_id' => $this->location->id,
            'benefit_type' => 'free_visit',
            'value_mode' => 'count',
            'value_applied' => 1,
            'redeemable_type' => Booking::findOrFail($bookingId)->getMorphClass(),
            'redeemable_id' => $bookingId,
        ]);

        $this->deleteJson("/api/bookings/{$bookingId}/force-delete")->assertOk();

        $this->assertNotNull($redemption->fresh()->reversed_at, 'the member gets the benefit back when the checkout never happened');
        $this->assertSame('checkout_deleted', $redemption->fresh()->reversal_reason);
    }

    public function test_a_booking_cancelled_or_trashed_while_its_card_was_being_charged_has_the_charge_voided(): void
    {
        $cancelledId = $this->createBooking($this->roomOne);
        $this->gateway->duringNextCall = fn () => Booking::findOrFail($cancelledId)->update(['status' => 'cancelled']);
        $this->gateway->replies[] = $this->transactionReply('1', '60200000070');
        $this->gateway->replies[] = $this->voidReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($cancelledId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYABLE_REMOVED');
        $this->assertSame('voidTransaction', $this->gateway->sent[1]['transaction_type']);
        $this->assertSame('60200000070', $this->gateway->sent[1]['ref_trans_id']);

        $trashedId = $this->createBooking($this->roomTwo, ['guest_email' => 'trashed@example.com']);
        $this->gateway->duringNextCall = fn () => Booking::findOrFail($trashedId)->delete();
        $this->gateway->replies[] = $this->transactionReply('1', '60200000071');
        $this->gateway->replies[] = $this->voidReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($trashedId))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYABLE_REMOVED');
        $this->assertSame('voidTransaction', $this->gateway->sent[3]['transaction_type']);
        $this->assertSame(0, Payment::count());
    }

    public function test_an_approved_answer_that_is_only_part_of_a_split_payment_is_refused(): void
    {
        $bookingId = $this->createBooking($this->roomOne);
        $reply = $this->transactionReply('1', '60200000072');
        $reply->getTransactionResponse()->setSplitTenderId('900002');
        $this->gateway->replies[] = $reply;
        $this->gateway->replies[] = $this->voidReply('Ok');

        $this->postJson('/api/payments/charge', $this->chargePayload($bookingId))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'PAYMENT_NOT_APPROVED');

        $this->assertSame('900002', $this->gateway->sent[1]['split_tender_id']);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_public_rollback_cannot_remove_a_paid_attraction_or_event_purchase(): void
    {
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
        $attractionId = (int) $this->postJson('/api/attraction-purchases', [
            'attraction_id' => $attraction->id,
            'guest_name' => 'Axe Guest',
            'guest_email' => 'axes@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 0,
            'payment_method' => 'authorize.net',
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '18:00',
        ])->assertStatus(201)->json('data.id');
        $this->recordPayment(Payment::TYPE_ATTRACTION_PURCHASE, $attractionId, 20.00);

        $this->deleteJson("/api/attraction-purchases/{$attractionId}/force-delete")->assertStatus(403);
        $this->assertNotNull(AttractionPurchase::find($attractionId));

        $event = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($this->glowNight(), 'event@example.com'))->assertSuccessful();
        $eventId = (int) ($event->json('data.id') ?? $event->json('id'));
        $this->recordPayment(Payment::TYPE_EVENT_PURCHASE, $eventId, 15.00);

        $this->deleteJson("/api/event-purchases/{$eventId}/force-delete")->assertStatus(403);
        $this->assertNotNull(EventPurchase::find($eventId));
    }

    private function axeThrowing(): Attraction
    {
        return Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
    }

    private function attractionPayload(Attraction $attraction, string $email, array $overrides = []): array
    {
        return array_merge([
            'attraction_id' => $attraction->id,
            'guest_name' => 'Axe Guest',
            'guest_email' => $email,
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 0,
            'payment_method' => 'authorize.net',
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '18:00',
        ], $overrides);
    }

    public function test_retrying_a_paid_attraction_checkout_with_its_key_shows_it_instead_of_charging_again(): void
    {
        $attraction = $this->axeThrowing();
        $payload = $this->attractionPayload($attraction, 'axes@example.com', ['checkout_key' => 'axe-attempt']);
        $firstId = (int) $this->postJson('/api/attraction-purchases', $payload)->assertStatus(201)->json('data.id');
        $this->gateway->replies[] = $this->transactionReply('1', '60200000080');
        $this->postJson('/api/payments/charge', $this->chargePayload($firstId, 20.00, Payment::TYPE_ATTRACTION_PURCHASE))->assertOk();

        $this->postJson('/api/attraction-purchases', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_PURCHASED')
            ->assertJsonPath('data.id', $firstId);

        $this->assertSame(1, AttractionPurchase::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_a_stale_attraction_key_on_the_next_guest_starts_a_new_purchase(): void
    {
        $attraction = $this->axeThrowing();
        $firstId = (int) $this->postJson('/api/attraction-purchases', $this->attractionPayload($attraction, 'first@example.com', ['checkout_key' => 'kiosk-key']))->assertStatus(201)->json('data.id');
        $this->gateway->replies[] = $this->transactionReply('1', '60200000081');
        $this->postJson('/api/payments/charge', $this->chargePayload($firstId, 20.00, Payment::TYPE_ATTRACTION_PURCHASE))->assertOk();

        $this->postJson('/api/attraction-purchases', $this->attractionPayload($attraction, 'next@example.com', ['checkout_key' => 'kiosk-key']))
            ->assertStatus(201);

        $this->assertSame(2, AttractionPurchase::count());
    }

    public function test_retrying_a_paid_event_checkout_with_its_key_shows_it_instead_of_charging_again(): void
    {
        $event = $this->glowNight();
        $payload = $this->eventPurchasePayload($event, 'event@example.com', ['checkout_key' => 'event-attempt']);
        $first = $this->postJson('/api/event-purchases', $payload)->assertSuccessful();
        $firstId = (int) ($first->json('data.id') ?? $first->json('id'));
        $this->gateway->replies[] = $this->transactionReply('1', '60200000082');
        $this->postJson('/api/payments/charge', $this->chargePayload($firstId, 15.00, Payment::TYPE_EVENT_PURCHASE))->assertOk();

        $this->postJson('/api/event-purchases', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_PURCHASED')
            ->assertJsonPath('data.id', $firstId);

        $this->assertSame(1, EventPurchase::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_retrying_a_paid_cart_order_with_its_key_shows_it_instead_of_charging_again(): void
    {
        $event = $this->glowNight();
        $eventDate = $event->start_date instanceof \DateTimeInterface ? $event->start_date->format('Y-m-d') : (string) $event->start_date;
        $payload = [
            'items' => [['type' => 'event', 'id' => $event->id, 'quantity' => 1, 'scheduled_date' => $eventDate, 'scheduled_time' => '18:00']],
            'guest_name' => 'Cart Guest',
            'guest_email' => 'cart@example.com',
            'payment_method' => 'authorize.net',
            'checkout_key' => 'cart-attempt',
        ];
        $order = $this->postJson('/api/ticket-orders', $payload)->assertSuccessful();
        $orderId = (int) ($order->json('data.id') ?? $order->json('id'));
        $orderModel = \App\Models\TicketOrder::findOrFail($orderId);
        $this->gateway->replies[] = $this->transactionReply('1', '60200000090');
        $this->postJson('/api/payments/charge', $this->chargePayload($orderId, (float) $orderModel->total_amount, Payment::TYPE_TICKET_ORDER))->assertOk();

        $this->postJson('/api/ticket-orders', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_PURCHASED')
            ->assertJsonPath('data.id', $orderId);

        $this->assertSame(1, \App\Models\TicketOrder::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_a_logged_in_customer_cannot_place_a_cart_order_marked_paid_at_the_venue(): void
    {
        $event = $this->glowNight();
        $eventDate = $event->start_date instanceof \DateTimeInterface ? $event->start_date->format('Y-m-d') : (string) $event->start_date;
        $this->withHeader('Authorization', 'Bearer ' . $this->customer('pat@example.com')->createToken('portal')->plainTextToken);

        $this->postJson('/api/ticket-orders', [
            'items' => [['type' => 'event', 'id' => $event->id, 'quantity' => 1, 'scheduled_date' => $eventDate, 'scheduled_time' => '18:00']],
            'guest_name' => 'Pat Customer',
            'guest_email' => 'pat@example.com',
            'payment_method' => 'in-store',
        ])->assertStatus(422);

        $this->assertSame(0, \App\Models\TicketOrder::count());
    }

    public function test_waiver_reminders_skip_unpaid_attraction_and_event_checkouts_but_keep_paid_ones(): void
    {
        $this->defaultWaiverTemplate();
        $attraction = $this->axeThrowing();
        $tomorrow = now()->addDay()->toDateString();

        $unpaidId = (int) $this->postJson('/api/attraction-purchases', $this->attractionPayload($attraction, 'unpaid@example.com', ['scheduled_date' => $tomorrow]))->assertStatus(201)->json('data.id');
        $paidId = (int) $this->postJson('/api/attraction-purchases', $this->attractionPayload($attraction, 'paid@example.com', ['scheduled_date' => $tomorrow, 'scheduled_time' => '19:00']))->assertStatus(201)->json('data.id');
        $this->gateway->replies[] = $this->transactionReply('1', '60200000100');
        $this->postJson('/api/payments/charge', $this->chargePayload($paidId, 20.00, Payment::TYPE_ATTRACTION_PURCHASE))->assertOk();

        $event = $this->glowNight();
        $unpaidEvent = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'unpaid-event@example.com'))->assertSuccessful();
        $unpaidEventId = (int) ($unpaidEvent->json('data.id') ?? $unpaidEvent->json('id'));
        $paidEvent = $this->postJson('/api/event-purchases', $this->eventPurchasePayload($event, 'paid-event@example.com', ['purchase_time' => '19:00']))->assertSuccessful();
        $paidEventId = (int) ($paidEvent->json('data.id') ?? $paidEvent->json('id'));
        $this->gateway->replies[] = $this->transactionReply('1', '60200000101');
        $this->postJson('/api/payments/charge', $this->chargePayload($paidEventId, 15.00, Payment::TYPE_EVENT_PURCHASE))->assertOk();

        $unpaidWaiver = Waiver::where('attraction_purchase_id', $unpaidId)->firstOrFail();
        $paidWaiver = Waiver::where('attraction_purchase_id', $paidId)->firstOrFail();
        $unpaidEventWaiver = $this->eventPurchaseWaiver($unpaidEventId);
        $paidEventWaiver = $this->eventPurchaseWaiver($paidEventId);

        $due = app(WaiverService::class)->dueForReminder(48)->pluck('id');

        $this->assertFalse($due->contains($unpaidWaiver->id), 'an attraction checkout that was never paid is not reminded');
        $this->assertTrue($due->contains($paidWaiver->id), 'a paid attraction purchase is still reminded');
        $this->assertFalse($due->contains($unpaidEventWaiver->id), 'an event checkout that was never paid is not reminded');
        $this->assertTrue($due->contains($paidEventWaiver->id), 'a paid event purchase is still reminded');
    }

    public function test_an_identical_keyed_retry_of_an_unpaid_checkout_reuses_its_booking(): void
    {
        $payload = $this->bookingPayload($this->roomOne, ['checkout_key' => 'resend-attempt']);
        $firstId = (int) $this->postJson('/api/bookings', $payload)->assertStatus(201)->json('data.id');

        $this->postJson('/api/bookings', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Booking already exists')
            ->assertJsonPath('data.id', $firstId);

        $this->assertSame(1, Booking::count());
    }

    public function test_a_logged_in_customer_booking_the_same_time_again_is_asked_first(): void
    {
        $customer = $this->customer('pat@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $customer->createToken('portal')->plainTextToken);
        $this->bookAndPay($this->roomOne, '60200000102', ['customer_id' => $customer->id, 'guest_email' => 'pat@example.com']);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomTwo, ['customer_id' => $customer->id, 'guest_email' => 'pat@example.com', 'checkout_key' => 'second-room']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOKED_SAME_TIME');
    }

    public function test_a_keyed_retry_with_corrected_details_after_the_first_went_through_asks_before_booking_again(): void
    {
        $this->bookAndPay($this->roomOne, '60200000103', ['checkout_key' => 'typo-attempt', 'guest_email' => 'pat@gmial.com']);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomTwo, ['checkout_key' => 'typo-attempt', 'guest_email' => 'pat@gmail.com']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOKED_SAME_TIME')
            ->assertJsonMissingPath('reference_number');

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomTwo, ['checkout_key' => 'typo-attempt', 'guest_email' => 'pat@gmail.com', 'book_another' => true]))
            ->assertStatus(201);
        $this->assertSame(2, Booking::count());
    }

    public function test_a_keyed_retry_with_changed_notes_is_not_treated_as_the_same_unpaid_attempt(): void
    {
        $firstId = $this->createBooking($this->roomOne, ['checkout_key' => 'notes-attempt', 'notes' => 'Birthday']);

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'notes-attempt', 'notes' => 'Anniversary']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ATTEMPT_IN_PROGRESS');

        $this->assertSame('Birthday', Booking::findOrFail($firstId)->notes);
    }

    public function test_releasing_an_abandoned_attempt_gives_its_gift_card_money_back(): void
    {
        $card = \App\Models\GiftCard::create([
            'code' => 'GIFTTEST0001',
            'type' => 'fixed',
            'initial_value' => 50,
            'balance' => 50,
            'max_usage' => 5,
            'status' => 'active',
            'location_id' => $this->location->id,
            'created_by' => $this->staff()->id,
        ]);
        $firstId = $this->createBooking($this->roomOne, ['checkout_key' => 'gift-attempt', 'gift_card_code' => 'GIFTTEST0001']);
        $this->assertEqualsWithDelta(0.0, (float) $card->fresh()->balance, 0.001, 'the first attempt spent the card');
        $this->travel(3)->minutes();

        $this->postJson('/api/bookings', $this->bookingPayload($this->roomOne, ['checkout_key' => 'gift-attempt', 'participants' => 3]))
            ->assertStatus(201);

        $this->assertNull(Booking::withTrashed()->find($firstId));
        $this->assertEqualsWithDelta(50.0, (float) $card->fresh()->balance, 0.001, 'the abandoned attempt gave the card its money back');
    }

    public function test_a_held_saved_card_charge_is_refused_and_released(): void
    {
        $this->gateway->replies[] = $this->transactionReply('4', '60200000104');
        $this->gateway->replies[] = $this->heldUpdateReply('Ok');

        $result = app(\App\Services\AuthorizeNetProfileService::class)->chargeProfile($this->account, '900100', '900200', 30.00, 'MB1-2', 'Membership renewal - Explorer');

        $this->assertFalse($result['success']);
        $this->assertSame('decline', $this->gateway->sent[1]['held_action']);
        $this->assertSame('60200000104', $this->gateway->sent[1]['ref_trans_id']);
    }

    public function test_a_held_online_membership_charge_is_refused_and_released(): void
    {
        $plan = \App\Models\MembershipPlan::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Local Explorer',
            'slug' => 'local-explorer',
            'tier' => 'basic',
            'price' => 29.99,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        $token = $this->customer('member@example.com')->createToken('portal')->plainTextToken;
        $this->gateway->replies[] = $this->transactionReply('4', '60200000105');
        $this->gateway->replies[] = $this->heldUpdateReply('Ok');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/memberships/purchase', [
                'membership_plan_id' => $plan->id,
                'home_location_id' => $this->location->id,
                'opaque_data' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'opaque-token'],
                'terms_accepted' => true,
                'recurring_billing_authorized' => true,
            ])
            ->assertStatus(402);

        $this->assertSame('decline', $this->gateway->sent[1]['held_action']);
        $this->assertSame(0, \App\Models\Membership::count());
    }

}

class FakeAuthorizeNetGateway extends AuthorizeNetGateway
{
    public const NO_ANSWER = 'no-answer';

    public array $replies = [];

    public array $sent = [];

    public ?\Closure $duringNextCall = null;

    public function execute(AnetController\base\ApiOperationBase $controller, string $environment)
    {
        $apiRequest = \Closure::bind(fn () => $this->apiRequest, $controller, AnetController\base\ApiOperationBase::class)();

        $transaction = method_exists($apiRequest, 'getTransactionRequest') ? $apiRequest->getTransactionRequest() : null;
        $held = method_exists($apiRequest, 'getHeldTransactionRequest') ? $apiRequest->getHeldTransactionRequest() : null;

        $this->sent[] = [
            'controller' => get_class($controller),
            'transaction_type' => $transaction?->getTransactionType(),
            'ref_trans_id' => $transaction?->getRefTransId() ?? $held?->getRefTransId(),
            'split_tender_id' => $transaction?->getSplitTenderId(),
            'held_action' => $held?->getAction(),
        ];

        if ($this->duringNextCall) {
            $during = $this->duringNextCall;
            $this->duringNextCall = null;
            $during();
        }

        if ($this->replies === []) {
            throw new \RuntimeException('The fake gateway was called with no reply queued');
        }

        $reply = array_shift($this->replies);

        return $reply === self::NO_ANSWER ? null : $reply;
    }
}
