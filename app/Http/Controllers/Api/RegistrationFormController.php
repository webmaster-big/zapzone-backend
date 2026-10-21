<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailNotification;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\WaiverProfile;
use App\Services\EventRegistrationService;
use App\Support\SchemaSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RegistrationFormController extends Controller
{
    private const NOT_FOUND = 'This registration form could not be found.';

    private const RELOAD = 'Please reload the page and try again.';

    public function __construct(private EventRegistrationService $registrations)
    {
    }

    public function show(string $code): JsonResponse
    {
        $event = $this->findEvent($code);

        if ($event === null) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($event),
        ]);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $event = $this->findEvent($code);

        if ($event === null) {
            return $this->notFound();
        }

        $submissionKey = $request->input('submission_key');
        if (!is_string($submissionKey) || preg_match('/^[A-Za-z0-9-]{8,64}$/', $submissionKey) !== 1) {
            return $this->fieldError('submission_key', self::RELOAD);
        }

        $existing = $this->registrations->findBySubmissionKey($event, $submissionKey);

        if ($existing !== null) {
            $clean = $this->clean($request);

            if ($clean instanceof JsonResponse) {
                return $clean;
            }

            return $this->sameSubmission($existing, $clean)
                ? $this->confirmation($event, 200, true)
                : $this->fieldError('submission_key', self::RELOAD);
        }

        $status = $event->status();
        if ($status !== RegistrationEvent::STATUS_OPEN) {
            return response()->json([
                'success' => false,
                'code' => 'REGISTRATION_CLOSED',
                'reason' => $status,
                'message' => 'Registration for this event is closed.',
            ], 422)->header('Cache-Control', 'no-store');
        }

        $clean = $this->clean($request);

        if ($clean instanceof JsonResponse) {
            return $clean;
        }

        $clean['submission_key'] = $submissionKey;

        try {
            $result = $this->registrations->register($event, $clean);
        } catch (\RuntimeException $e) {
            Log::error('Event registration could not be saved', [
                'registration_event_id' => $event->id,
                'reason' => $e->getMessage(),
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => "We couldn't save your registration. Please tap Register again — you won't be registered twice.",
            ], 503)->header('Cache-Control', 'no-store');
        }

        if ($result['replayed']) {
            return $this->sameSubmission($result['entry'], $clean)
                ? $this->confirmation($event, 200, true)
                : $this->fieldError('submission_key', self::RELOAD);
        }

        return $this->confirmation($event, 201, false);
    }

    private function findEvent(string $code): ?RegistrationEvent
    {
        if (!RegistrationEvent::isAvailable()) {
            return null;
        }

        return RegistrationEvent::query()
            ->with(SchemaSupport::hasColumn('registration_events', 'event_id') ? ['location', 'event'] : ['location'])
            ->where('public_code', strtolower($code))
            ->first();
    }

    private function present(RegistrationEvent $event): array
    {
        $status = $event->status();

        return [
            'code' => $event->public_code,
            'name' => $event->name,
            'description' => $event->description,
            'event_date' => $event->event_date->toDateString(),
            'starts_on' => $this->startsOn($event),
            'status' => $status,
            'location_name' => $event->location?->name,
            'location_logo_path' => $event->location?->logo_path,
            'header_image_path' => $event->header_image_path,
            'header_image_width' => $event->header_image_width,
            'header_image_height' => $event->header_image_height,
            'confirmation_message' => $event->confirmation_message,
            'next_edition' => $status === RegistrationEvent::STATUS_OPEN ? null : $this->nextEditionLink($event),
        ];
    }

    private function startsOn(RegistrationEvent $event): ?string
    {
        if (!SchemaSupport::hasColumn('registration_events', 'event_id') || $event->event_id === null) {
            return null;
        }

        $linked = $event->event;
        if ($linked === null || (int) $linked->location_id !== (int) $event->location_id || $linked->start_date === null || $linked->end_date === null) {
            return null;
        }

        $start = $linked->start_date->toDateString();
        $date = $event->event_date->toDateString();

        return $start < $date && $date <= $linked->end_date->toDateString() ? $start : null;
    }

    private function nextEditionLink(RegistrationEvent $event): ?array
    {
        $next = RegistrationEvent::query()
            ->with('location')
            ->where('previous_edition_id', $event->id)
            ->where('company_id', $event->company_id)
            ->orderBy('event_date')
            ->get()
            ->first(fn (RegistrationEvent $candidate) => $candidate->status() === RegistrationEvent::STATUS_OPEN);

        return $next === null ? null : ['name' => $next->name, 'url' => $next->publicUrl()];
    }

    private function clean(Request $request): array|JsonResponse
    {
        $name = $request->input('name');
        $email = $request->input('email');
        $phone = $request->input('phone');

        if (is_string($name)) {
            $name = mb_check_encoding($name, 'UTF-8') ? $name : '';
            $name = (string) preg_replace('/(?:(?![\t\n\x0B\f\r])[\p{Cc}\p{Cf}])+/u', '', $name);
            $name = trim((string) preg_replace('/[\t\n\x0B\f\r\p{Z}]+/u', ' ', $name));
        }

        if (is_string($email)) {
            $email = mb_check_encoding($email, 'UTF-8') ? trim((string) EmailNotification::scrubEmail($email)) : '';
        }

        if (is_string($phone)) {
            $phone = mb_check_encoding($phone, 'UTF-8') ? trim($phone) : '';
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'phone' => $phone],
            [
                'name' => ['bail', 'required', 'string', 'min:2', 'max:120', 'regex:/\p{L}/u'],
                'email' => ['bail', 'required', 'string', 'email', 'max:190'],
                'phone' => ['bail', 'required', 'string', 'max:40'],
            ],
            [
                'name.required' => 'Please enter your name.',
                'name.string' => 'Please enter your name.',
                'name.min' => 'Please enter your full name.',
                'name.max' => 'Please keep your name under 120 characters.',
                'name.regex' => 'Please enter your name using letters.',
                'email.required' => 'Please enter your email address.',
                'email.string' => 'Please enter your email address.',
                'email.email' => 'Please enter a valid email address, like name@example.com.',
                'email.max' => 'Please use an email address under 190 characters.',
                'phone.required' => 'Please enter your phone number.',
                'phone.string' => 'Please enter your phone number.',
                'phone.max' => WaiverProfile::PHONE_NOT_RECOGNISED,
            ]
        );

        if ($validator->fails()) {
            $errors = $validator->errors();

            return response()->json([
                'success' => false,
                'message' => $errors->first(),
                'errors' => $errors->toArray(),
            ], 422)->header('Cache-Control', 'no-store');
        }

        $storedPhone = WaiverProfile::storedPhoneFor($phone);
        $phoneKey = EventRegistrationService::phoneKey($storedPhone);

        if ($storedPhone === null || $phoneKey === null) {
            return $this->fieldError('phone', WaiverProfile::phoneProblem($phone));
        }

        return [
            'name' => $name,
            'name_key' => EventRegistrationService::nameKey($name),
            'email' => $email,
            'email_key' => EventRegistrationService::emailKey($email),
            'phone' => $storedPhone,
            'phone_key' => $phoneKey,
        ];
    }

    private function sameSubmission(RegistrationEntry $entry, array $clean): bool
    {
        $contact = $entry->contact;

        return $contact !== null
            && $entry->name_key === $clean['name_key']
            && $contact->email_key === $clean['email_key']
            && $contact->phone_key === $clean['phone_key'];
    }

    private function confirmation(RegistrationEvent $event, int $status, bool $replayed): JsonResponse
    {
        $body = [
            'success' => true,
            'data' => [
                'event_name' => $event->name,
                'confirmation_message' => $event->confirmation_message,
            ],
        ];

        if ($replayed) {
            $body['replayed'] = true;
        }

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    private function fieldError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422)->header('Cache-Control', 'no-store');
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::NOT_FOUND,
        ], 404);
    }
}
