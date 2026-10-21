<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\RegistrationContact;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\User;
use App\Services\EventRegistrationService;
use App\Support\RegistrationHeaderImage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RegistrationEventController extends Controller
{
    use ScopesByAuthUser;

    private const IMAGE_PROBLEM = "We couldn't use that image. Please upload a PNG, JPEG or WebP under 8 MB, ideally about 1600 × 800.";

    public function __construct(private EventRegistrationService $registrations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $locationIds = $this->staffLocationIds($request);
        $requested = $request->input('location_id');

        if (is_scalar($requested) && $requested !== '') {
            $requested = filter_var($requested, FILTER_VALIDATE_INT);
            $locationIds = $requested !== false && in_array($requested, $locationIds, true) ? [$requested] : [];
        }

        $events = RegistrationEvent::query()
            ->with(['location', 'previousEdition'])
            ->where('company_id', $user->company_id)
            ->whereIn('location_id', $locationIds)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $counts = $this->registrations->listCounts($events->pluck('id')->map(fn ($id) => (int) $id)->all());
        $next = $this->nextEditionsFor($events->pluck('id')->all());

        return $this->noStore(response()->json([
            'success' => true,
            'data' => [
                'events' => $events->map(fn (RegistrationEvent $event) => $this->summary($event, $counts, $next))->values()->all(),
            ],
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $data = $this->validatedInput($request, true);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $locationId = (int) $data['location_id'];
        if (!Location::query()->where('company_id', $user->company_id)->whereKey($locationId)->exists()) {
            return $this->fieldError('location_id', 'Choose a location for this form.');
        }

        if ($denied = $this->denyStaffElsewhere($locationId, 'location')) {
            return $denied;
        }

        $previousId = isset($data['previous_edition_id']) ? (int) $data['previous_edition_id'] : null;
        if ($problem = $this->editionProblem(null, (int) $user->company_id, $locationId, $data['event_date'], $previousId, true)) {
            return $problem;
        }

        $image = null;
        if (($data['header_image'] ?? null) instanceof UploadedFile) {
            $image = RegistrationHeaderImage::process($data['header_image']);
            if ($image === null) {
                return $this->fieldError('header_image', self::IMAGE_PROBLEM);
            }
            $image['path'] = RegistrationHeaderImage::store($image['binary']);
        }

        $event = new RegistrationEvent([
            'location_id' => $locationId,
            'name' => $data['name'],
            'event_date' => $data['event_date'],
            'description' => $data['description'] ?? null,
            'confirmation_message' => $data['confirmation_message'] ?? null,
            'previous_edition_id' => $previousId,
            'is_open' => array_key_exists('is_open', $data) ? (bool) $data['is_open'] : true,
            'header_image_path' => $image['path'] ?? null,
            'header_image_width' => $image['width'] ?? null,
            'header_image_height' => $image['height'] ?? null,
        ]);
        $event->company_id = $user->company_id;
        $event->created_by = $user->id;

        try {
            $event->save();
        } catch (\Throwable $e) {
            RegistrationHeaderImage::delete($image['path'] ?? null);

            throw $e;
        }

        ActivityLog::log(
            'registration_event_created',
            'event_registration',
            sprintf('Created the registration form "%s"', $event->name),
            $user->id,
            (int) $event->location_id,
            'registration_event',
            (int) $event->id,
            ['resource_name' => $event->name, 'event_date' => $event->event_date->toDateString()]
        );

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $this->detail($event->fresh(['location', 'previousEdition'])),
        ], 201));
    }

    public function show(Request $request, int $registrationEvent): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $event = $this->accessibleEvent($request, $registrationEvent);
        if ($event instanceof JsonResponse) {
            return $event;
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $this->detail($event),
        ]));
    }

    public function update(Request $request, int $registrationEvent): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $event = $this->accessibleEvent($request, $registrationEvent);
        if ($event instanceof JsonResponse) {
            return $event;
        }

        $data = $this->validatedInput($request, false);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $locationId = array_key_exists('location_id', $data) ? (int) $data['location_id'] : (int) $event->location_id;
        if ($locationId !== (int) $event->location_id) {
            if ($this->locationLocked($event)) {
                return $this->fieldError('location_id', "The location can't be changed once people have registered or another form links to this one.");
            }

            if (!Location::query()->where('company_id', $user->company_id)->whereKey($locationId)->exists()) {
                return $this->fieldError('location_id', 'Choose a location for this form.');
            }

            if ($denied = $this->denyStaffElsewhere($locationId, 'location')) {
                return $denied;
            }
        }

        $eventDate = $data['event_date'] ?? $event->event_date->toDateString();
        $previousSent = array_key_exists('previous_edition_id', $data);
        $previousId = $previousSent
            ? ($data['previous_edition_id'] !== null ? (int) $data['previous_edition_id'] : null)
            : ($event->previous_edition_id !== null ? (int) $event->previous_edition_id : null);

        if (!$previousSent && $previousId !== null && !RegistrationEvent::query()->whereKey($previousId)->exists()) {
            $previousId = null;
        }

        $previousChanged = $previousSent && $previousId !== ($event->previous_edition_id !== null ? (int) $event->previous_edition_id : null);
        if ($problem = $this->editionProblem($event, (int) $user->company_id, $locationId, $eventDate, $previousId, $previousChanged || $locationId !== (int) $event->location_id)) {
            return $problem;
        }

        $image = null;
        if (($data['header_image'] ?? null) instanceof UploadedFile) {
            $image = RegistrationHeaderImage::process($data['header_image']);
            if ($image === null) {
                return $this->fieldError('header_image', self::IMAGE_PROBLEM);
            }
            $image['path'] = RegistrationHeaderImage::store($image['binary']);
        }

        $previousImage = $event->header_image_path;
        $attributes = ['location_id' => $locationId, 'event_date' => $eventDate, 'previous_edition_id' => $previousId];

        foreach (['name', 'description', 'confirmation_message'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('is_open', $data)) {
            $attributes['is_open'] = (bool) $data['is_open'];
        }

        if ($image !== null) {
            $attributes['header_image_path'] = $image['path'];
            $attributes['header_image_width'] = $image['width'];
            $attributes['header_image_height'] = $image['height'];
        } elseif (!empty($data['remove_header_image'])) {
            $attributes['header_image_path'] = null;
            $attributes['header_image_width'] = null;
            $attributes['header_image_height'] = null;
        }

        $event->fill($attributes);
        $changed = array_keys($event->getDirty());

        try {
            $event->save();
        } catch (\Throwable $e) {
            RegistrationHeaderImage::delete($image['path'] ?? null);

            throw $e;
        }

        if ($previousImage !== null && $previousImage !== $event->header_image_path) {
            $this->deleteImageIfUnused($previousImage);
        }

        if ($changed !== []) {
            ActivityLog::log(
                'registration_event_updated',
                'event_registration',
                sprintf('Updated the registration form "%s"', $event->name),
                $user->id,
                (int) $event->location_id,
                'registration_event',
                (int) $event->id,
                ['resource_name' => $event->name, 'changed' => array_values(array_diff($changed, ['header_image_width', 'header_image_height']))]
            );
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $this->detail($event->fresh(['location', 'previousEdition'])),
        ]));
    }

    public function destroy(Request $request, int $registrationEvent): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $event = $this->accessibleEvent($request, $registrationEvent);
        if ($event instanceof JsonResponse) {
            return $event;
        }

        $next = RegistrationEvent::query()
            ->where('previous_edition_id', $event->id)
            ->orderBy('event_date')
            ->first();

        if ($next !== null) {
            return response()->json([
                'success' => false,
                'message' => sprintf('"%s" links to this form as last year\'s edition. Unlink it there first.', $next->name),
            ], 422);
        }

        $event->delete();

        ActivityLog::log(
            'registration_event_deleted',
            'event_registration',
            sprintf('Deleted the registration form "%s"', $event->name),
            $user->id,
            (int) $event->location_id,
            'registration_event',
            (int) $event->id,
            ['resource_name' => $event->name]
        );

        return response()->json([
            'success' => true,
            'message' => 'Registration form deleted.',
        ]);
    }

    public function registrations(Request $request, int $registrationEvent): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $event = $this->accessibleEvent($request, $registrationEvent);
        if ($event instanceof JsonResponse) {
            return $event;
        }

        $validator = Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'string', 'in:' . implode(',', EventRegistrationService::FILTERS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        try {
            $result = $this->registrations->groups(
                $event,
                is_string($request->query('search')) ? $request->query('search') : null,
                (string) ($request->query('filter') ?: 'all'),
                (int) ($request->query('page') ?: 1),
                (int) ($request->query('per_page') ?: 25)
            );
            $counts = $this->registrations->counts($event);
        } catch (QueryException $e) {
            return $this->queryFailed($request, $event, $e);
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => [
                'groups' => $result['groups'],
                'pagination' => $result['pagination'],
                'counts' => $counts,
            ],
        ]));
    }

    public function export(Request $request, int $registrationEvent): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $event = $this->accessibleEvent($request, $registrationEvent);
        if ($event instanceof JsonResponse) {
            return $event;
        }

        try {
            $export = $this->registrations->export($event);
        } catch (QueryException $e) {
            return $this->queryFailed($request, $event, $e);
        }

        ActivityLog::log(
            'registration_entries_exported',
            'event_registration',
            sprintf('Exported %d registrations from "%s"', count($export['rows']), $event->name),
            $user->id,
            (int) $event->location_id,
            'registration_event',
            (int) $event->id,
            ['resource_name' => $event->name, 'rows' => count($export['rows']), 'capped' => $export['capped']]
        );

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $export,
        ]));
    }

    public function contactHistory(Request $request, int $registrationEvent, int $registrationContact): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $event = $this->accessibleEvent($request, $registrationEvent);
        if ($event instanceof JsonResponse) {
            return $event;
        }

        $contact = RegistrationContact::query()
            ->where('company_id', $user->company_id)
            ->whereKey($registrationContact)
            ->first();

        try {
            $history = $contact === null ? null : $this->registrations->history($event, $contact, $this->staffLocationIds($request));
        } catch (QueryException $e) {
            return $this->queryFailed($request, $event, $e);
        }

        if ($history === null) {
            return response()->json([
                'success' => false,
                'message' => 'That registration could not be found.',
            ], 404);
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $history,
        ]));
    }

    public function hideEntry(Request $request, int $registrationEvent, int $registrationEntry): JsonResponse
    {
        return $this->toggleEntry($request, $registrationEvent, $registrationEntry, true);
    }

    public function restoreEntry(Request $request, int $registrationEvent, int $registrationEntry): JsonResponse
    {
        return $this->toggleEntry($request, $registrationEvent, $registrationEntry, false);
    }

    private function toggleEntry(Request $request, int $registrationEvent, int $registrationEntry, bool $hide): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $event = $this->accessibleEvent($request, $registrationEvent);
        if ($event instanceof JsonResponse) {
            return $event;
        }

        $entry = RegistrationEntry::query()
            ->where('registration_event_id', $event->id)
            ->whereKey($registrationEntry)
            ->first();

        if ($entry === null) {
            return response()->json([
                'success' => false,
                'message' => 'That registration could not be found.',
            ], 404);
        }

        if ($hide !== ($entry->hidden_at !== null)) {
            $entry->forceFill([
                'hidden_at' => $hide ? now() : null,
                'hidden_by' => $hide ? $user->id : null,
            ])->save();

            ActivityLog::log(
                $hide ? 'registration_entry_hidden' : 'registration_entry_restored',
                'event_registration',
                sprintf('%s registration #%d on "%s"', $hide ? 'Hid' : 'Restored', $entry->id, $event->name),
                $user->id,
                (int) $event->location_id,
                'registration_entry',
                (int) $entry->id,
                ['resource_name' => $event->name, 'registration_event_id' => (int) $event->id]
            );
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => ['counts' => $this->registrations->counts($event)],
        ]));
    }

    private function staffUser(Request $request): User
    {
        $user = $this->resolveAuthUser($request);

        if (!$user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function accessibleEvent(Request $request, int $id): RegistrationEvent|JsonResponse
    {
        $user = $this->staffUser($request);

        $event = RegistrationEvent::query()
            ->with(['location', 'previousEdition'])
            ->where('company_id', $user->company_id)
            ->whereKey($id)
            ->first();

        if ($event === null) {
            return response()->json([
                'success' => false,
                'message' => 'That registration form could not be found.',
            ], 404);
        }

        if ($denied = $this->denyStaffElsewhere($event->location_id, 'registration form')) {
            return $denied;
        }

        return $event;
    }

    private function validatedInput(Request $request, bool $creating): array|JsonResponse
    {
        $input = [];

        foreach (['name', 'event_date', 'description', 'confirmation_message'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                $input[$field] = is_string($value) ? trim($value) : $value;
            }
        }

        foreach (['location_id', 'previous_edition_id', 'is_open', 'remove_header_image'] as $field) {
            if ($request->has($field)) {
                $input[$field] = $request->input($field);
            }
        }

        foreach (['description', 'confirmation_message', 'previous_edition_id'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }

        $upload = $request->file('header_image');
        if ($upload !== null) {
            $input['header_image'] = $upload;
        } elseif ($request->has('header_image') && !in_array($request->input('header_image'), [null, ''], true)) {
            $input['header_image'] = $request->input('header_image');
        }

        $present = $creating ? 'required' : 'sometimes';

        $validator = Validator::make($input, [
            'name' => [$present, 'required', 'string', 'max:120', 'regex:/^[^\r\n]+$/'],
            'location_id' => [$present, 'required', 'integer'],
            'event_date' => [$present, 'required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:2000'],
            'confirmation_message' => ['nullable', 'string', 'max:500'],
            'previous_edition_id' => ['nullable', 'integer'],
            'is_open' => ['sometimes', 'boolean'],
            'remove_header_image' => ['sometimes', 'boolean'],
            'header_image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192', 'dimensions:max_width=8000,max_height=8000'],
        ], [
            'name.required' => 'Please give the form a name.',
            'name.max' => 'Please keep the name under 120 characters.',
            'name.regex' => 'Please keep the name on one line.',
            'location_id.required' => 'Choose a location for this form.',
            'location_id.integer' => 'Choose a location for this form.',
            'event_date.required' => 'Choose the event date.',
            'event_date.date_format' => 'Choose the event date.',
            'description.max' => 'Please keep the description under 2,000 characters.',
            'confirmation_message.max' => 'Please keep the confirmation message under 500 characters.',
            'previous_edition_id.integer' => "Choose last year's edition from the list.",
            'header_image.file' => self::IMAGE_PROBLEM,
            'header_image.uploaded' => self::IMAGE_PROBLEM,
            'header_image.mimes' => self::IMAGE_PROBLEM,
            'header_image.max' => self::IMAGE_PROBLEM,
            'header_image.dimensions' => self::IMAGE_PROBLEM,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        return $validator->validated();
    }

    private function editionProblem(?RegistrationEvent $event, int $companyId, int $locationId, string $eventDate, ?int $previousId, bool $previousChanged): ?JsonResponse
    {
        if ($previousId !== null) {
            $candidate = RegistrationEvent::query()
                ->whereKey($previousId)
                ->when($event !== null, fn ($query) => $query->whereKeyNot($event->id))
                ->where('company_id', $companyId)
                ->where('location_id', $locationId);

            if (!(clone $candidate)->exists()) {
                return $this->fieldError('previous_edition_id', 'Choose an earlier event from the same location.');
            }

            if (!$candidate->where('event_date', '<', $eventDate)->exists()) {
                return $previousChanged
                    ? $this->fieldError('previous_edition_id', 'Choose an earlier event from the same location.')
                    : $this->fieldError('event_date', "The date must stay between last year's edition and next year's edition.");
            }
        }

        if ($event !== null) {
            $nextDate = RegistrationEvent::query()
                ->where('previous_edition_id', $event->id)
                ->min('event_date');

            if ($nextDate !== null && $eventDate >= substr((string) $nextDate, 0, 10)) {
                return $this->fieldError('event_date', "The date must stay between last year's edition and next year's edition.");
            }
        }

        return null;
    }

    private function locationLocked(RegistrationEvent $event): bool
    {
        return RegistrationEntry::query()->where('registration_event_id', $event->id)->exists()
            || RegistrationEvent::query()->where('previous_edition_id', $event->id)->exists();
    }

    private function nextEditionsFor(array $eventIds): Collection
    {
        if ($eventIds === []) {
            return collect();
        }

        return RegistrationEvent::query()
            ->whereIn('previous_edition_id', $eventIds)
            ->orderBy('event_date')
            ->orderBy('id')
            ->get(['id', 'name', 'event_date', 'previous_edition_id'])
            ->groupBy(fn (RegistrationEvent $next) => (int) $next->previous_edition_id)
            ->map(fn (Collection $editions) => $editions->first());
    }

    private function summary(RegistrationEvent $event, array $counts, Collection $next): array
    {
        $previous = $event->previousEdition;
        $following = $next->get((int) $event->id);

        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'public_code' => $event->public_code,
            'public_url' => $event->publicUrl(),
            'event_date' => $event->event_date->toDateString(),
            'status' => $event->status(),
            'is_open' => (bool) $event->is_open,
            'description' => $event->description,
            'confirmation_message' => $event->confirmation_message,
            'header_image_path' => $event->header_image_path,
            'header_image_width' => $event->header_image_width,
            'header_image_height' => $event->header_image_height,
            'location_id' => (int) $event->location_id,
            'location_name' => $event->location?->name,
            'previous_edition' => $previous === null ? null : [
                'id' => (int) $previous->id,
                'name' => $previous->name,
                'event_date' => $previous->event_date->toDateString(),
            ],
            'next_edition' => $following === null ? null : [
                'id' => (int) $following->id,
                'name' => $following->name,
                'event_date' => $following->event_date->toDateString(),
            ],
            'total_entries' => (int) ($counts[(int) $event->id]['total_entries'] ?? 0),
            'unique_registrations' => (int) ($counts[(int) $event->id]['unique_registrations'] ?? 0),
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }

    private function detail(RegistrationEvent $event): array
    {
        $counts = $this->registrations->counts($event);
        $summary = $this->summary(
            $event,
            [(int) $event->id => ['total_entries' => $counts['total_entries'], 'unique_registrations' => $counts['unique_registrations']]],
            $this->nextEditionsFor([(int) $event->id])
        );

        return array_merge($summary, [
            'counts' => $counts,
            'location_locked' => $this->locationLocked($event),
        ]);
    }

    private function deleteImageIfUnused(string $path): void
    {
        $stillUsed = RegistrationEvent::withTrashed()->where('header_image_path', $path)->exists();

        if (!$stillUsed) {
            RegistrationHeaderImage::delete($path);
        }
    }

    private function queryFailed(Request $request, RegistrationEvent $event, QueryException $e): JsonResponse
    {
        Log::error('Registration forms request failed', [
            'registration_event_id' => $event->id,
            'sqlstate' => $e->errorInfo[0] ?? null,
            'driver_code' => $e->errorInfo[1] ?? null,
            'request_id' => $request->attributes->get('request_id'),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Something went wrong. Please try again.',
        ], 500);
    }

    private function fieldError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'MODULE_UNAVAILABLE',
            'message' => 'Registration forms are not set up yet.',
        ], 503);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store');
    }
}
