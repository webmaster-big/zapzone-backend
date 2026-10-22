<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ManagesRegistrationForms;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\Location;
use App\Models\RegistrationContact;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Models\User;
use App\Services\EventRegistrationService;
use App\Support\DataUriImage;
use App\Support\RegistrationFormGrouping;
use App\Support\RegistrationHeaderImage;
use App\Support\SchemaSupport;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RegistrationEventController extends Controller
{
    use ScopesByAuthUser;
    use ManagesRegistrationForms;

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
            ->with(['location', 'previousEdition', 'event', 'group'])
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

        if (!RegistrationEventGroup::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $data = $this->validatedInput($request, true);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $result = $this->createEventWithForms($request, $user, $data);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $fresh = collect($result['forms'])->map(fn (RegistrationEvent $event) => $event->fresh(['location', 'previousEdition', 'event', 'group']));

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $this->detail($fresh->first()),
            'created' => $fresh->map(fn (RegistrationEvent $event) => $this->summary($event, [], collect()))->values()->all(),
        ], 201));
    }

    private function syncSoleFormGroup(RegistrationEvent $event, ?RegistrationEventGroup $group): void
    {
        if ($group === null || RegistrationEvent::query()->where('group_id', $group->id)->count() !== 1) {
            return;
        }

        $group->forceFill([
            'event_date' => $event->event_date,
            'description' => $event->description,
            'confirmation_message' => $event->confirmation_message,
            'header_image_path' => $event->header_image_path,
            'header_image_width' => $event->header_image_width,
            'header_image_height' => $event->header_image_height,
        ])->save();
    }

    public function eventOptions(Request $request): JsonResponse
    {
        if (!RegistrationEvent::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);

        $only = filter_var($request->query('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $events = Event::query()
            ->with('location')
            ->whereIn('location_id', $this->staffLocationIds($request))
            ->whereHas('location', fn ($query) => $query->where('company_id', $user->company_id))
            ->when($only !== false, fn ($query) => $query->whereKey($only))
            ->orderByDesc('start_date')
            ->orderBy('name')
            ->limit(500)
            ->get();

        $grouped = RegistrationEventGroup::isAvailable();
        $linked = SchemaSupport::hasColumn('registration_events', 'event_id')
            ? RegistrationEvent::query()
                ->where('company_id', $user->company_id)
                ->whereIn('event_id', $events->pluck('id')->all())
                ->orderBy('id')
                ->get($grouped ? ['id', 'event_id', 'location_id', 'group_id'] : ['id', 'event_id', 'location_id'])
            : collect();
        $forms = $linked->groupBy(fn (RegistrationEvent $form) => (int) $form->event_id);
        $nearby = $grouped ? $this->unlinkedGroupsNear($user, $events) : collect();
        $groupIds = $grouped
            ? $linked->pluck('group_id')->filter()->concat($nearby->flatten())->map(fn ($id) => (int) $id)->unique()->values()->all()
            : [];
        $groupNames = $groupIds === [] ? collect() : RegistrationEventGroup::query()->whereKey($groupIds)->pluck('name', 'id');
        $managedGroups = $this->managedGroupIds($request, $groupIds);
        $groupForms = $groupIds === [] ? collect() : RegistrationEvent::query()
            ->with('event:id,location_id')
            ->whereIn('group_id', $groupIds)
            ->whereIn('location_id', $this->staffLocationIds($request))
            ->orderBy('id')
            ->get(['id', 'group_id', 'location_id', 'event_id', 'event_date'])
            ->groupBy(fn (RegistrationEvent $form) => (int) $form->group_id);

        return $this->noStore(response()->json([
            'success' => true,
            'data' => [
                'events' => $events->map(fn (Event $event) => [
                    'id' => (int) $event->id,
                    'name' => $event->name,
                    'location_id' => (int) $event->location_id,
                    'location_name' => $event->location?->name,
                    'description' => $event->description,
                    'image' => is_string($event->image) && !DataUriImage::isDataUri($event->image) ? $event->image : null,
                    'has_image' => is_string($event->image) && trim($event->image) !== '',
                    'date_type' => $event->date_type,
                    'start_date' => $event->start_date?->toDateString(),
                    'end_date' => $event->end_date?->toDateString(),
                    'is_active' => (bool) $event->is_active,
                    'registration_form_ids' => $forms->get((int) $event->id, collect())
                        ->filter(fn (RegistrationEvent $form) => (int) $form->location_id === (int) $event->location_id)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all(),
                    'registration_groups' => $forms->get((int) $event->id, collect())
                        ->toBase()
                        ->filter(fn (RegistrationEvent $form) => (int) $form->location_id === (int) $event->location_id && $form->group_id !== null)
                        ->map(fn (RegistrationEvent $form) => (int) $form->group_id)
                        ->concat($nearby->get((int) $event->id, []))
                        ->unique()
                        ->filter(fn (int $groupId) => $groupNames->has($groupId))
                        ->map(fn (int $groupId) => [
                            'id' => $groupId,
                            'name' => $groupNames->get($groupId),
                            'can_manage' => in_array($groupId, $managedGroups, true),
                            'forms' => $groupForms->get($groupId, collect())->map(fn (RegistrationEvent $form) => [
                                'id' => (int) $form->id,
                                'location_id' => (int) $form->location_id,
                                'event_id' => $form->event !== null && (int) $form->event->location_id === (int) $form->location_id ? (int) $form->event->id : null,
                                'event_date' => $form->event_date->toDateString(),
                            ])->values()->all(),
                        ])
                        ->values()
                        ->all(),
                ])->values()->all(),
            ],
        ]));
    }

    private function unlinkedGroupsNear(User $user, Collection $events): Collection
    {
        $dated = $events->filter(fn (Event $event) => $event->start_date !== null);
        if ($dated->isEmpty()) {
            return collect();
        }

        $from = $dated->map(fn (Event $event) => $event->start_date->toDateString())->min();
        $to = $dated->map(fn (Event $event) => ($event->end_date ?? $event->start_date)->toDateString())->max();

        $unlinked = RegistrationEvent::query()
            ->with('event:id,location_id')
            ->where('company_id', $user->company_id)
            ->whereIn('location_id', $dated->pluck('location_id')->unique()->all())
            ->whereNotNull('group_id')
            ->whereBetween('event_date', [$from, $to])
            ->orderBy('event_date')
            ->orderBy('id')
            ->get(['id', 'group_id', 'location_id', 'event_id', 'name', 'event_date'])
            ->toBase()
            ->filter(fn (RegistrationEvent $form) => $form->event === null || (int) $form->event->location_id !== (int) $form->location_id)
            ->map(fn (RegistrationEvent $form) => [
                'group_id' => (int) $form->group_id,
                'location_id' => (int) $form->location_id,
                'date' => $form->event_date->toDateString(),
                'key' => $this->yearlessKey($form->name),
            ])
            ->groupBy('location_id');

        if ($unlinked->isEmpty()) {
            return collect();
        }

        return $dated->mapWithKeys(function (Event $event) use ($unlinked) {
            $key = $this->yearlessKey($event->name);
            $start = $event->start_date->toDateString();
            $end = ($event->end_date ?? $event->start_date)->toDateString();

            return [(int) $event->id => collect($unlinked->get((int) $event->location_id, []))
                ->filter(fn (array $form) => $form['date'] >= $start && $form['date'] <= $end && $form['key'] === $key)
                ->pluck('group_id')
                ->unique()
                ->values()
                ->all()];
        });
    }

    private function yearlessKey(?string $name): string
    {
        return RegistrationFormGrouping::nameKey((string) preg_replace('/\b(19|20)\d{2}\b/u', ' ', (string) $name));
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
            'data' => $this->detail($event) + [
                'can_manage_event' => $event->group === null || $this->canManageGroup($request, $event->group),
                'group_form_count' => $event->group_id === null ? 1 : RegistrationEvent::query()->where('group_id', $event->group_id)->count(),
            ],
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

        if ($event->group_id !== null) {
            if (array_key_exists('name', $data) && $data['name'] !== $event->name) {
                return $this->fieldError('name', "This form uses its event's name. Rename the event instead.");
            }

            if (array_key_exists('location_id', $data) && (int) $data['location_id'] !== (int) $event->location_id) {
                return $this->fieldError('location_id', 'To change locations, add or remove them on the event.');
            }

            $newDate = $data['event_date'] ?? null;
            if ($newDate !== null && $event->group !== null && $newDate !== $event->event_date->toDateString()
                && !RegistrationEvent::query()->where('group_id', $event->group_id)->whereKeyNot($event->id)->exists()
                && ($problem = $this->groupOrderProblem($event->group, $newDate))) {
                return $problem;
            }
        }

        $locationId = array_key_exists('location_id', $data) ? (int) $data['location_id'] : (int) $event->location_id;
        if ($locationId !== (int) $event->location_id) {
            if ($this->locationLocked($event)) {
                return $this->fieldError('location_id', "The location can't be changed once people have registered, another form links to this one, or a photo frame uses it.");
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

        $linkSent = array_key_exists('event_id', $data);
        $linkedId = $linkSent && $data['event_id'] !== null ? (int) $data['event_id'] : null;
        if ($linkedId !== null && !Event::query()->whereKey($linkedId)->where('location_id', $locationId)->exists()) {
            return $this->fieldError('event_id', 'Choose an event at this location.');
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

        if ($linkSent) {
            $attributes['event_id'] = $linkedId;
        } elseif ($locationId !== (int) $event->location_id && $event->event_id !== null) {
            $attributes['event_id'] = null;
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
            DB::transaction(function () use ($event) {
                $group = $event->group_id === null
                    ? null
                    : RegistrationEventGroup::query()->whereKey($event->group_id)->lockForUpdate()->first();
                $current = RegistrationEvent::query()->whereKey($event->id)->lockForUpdate()->first(['id', 'group_id']);
                if ($current === null || (int) $current->group_id !== (int) $event->group_id) {
                    throw new HttpResponseException(response()->json([
                        'success' => false,
                        'message' => 'This form changed while you were saving it. Reload the page and try again.',
                    ], 409));
                }
                if ($group !== null
                    && $event->isDirty('event_date')
                    && !RegistrationEvent::query()->where('group_id', $group->id)->whereKeyNot($event->id)->exists()
                    && ($problem = $this->groupOrderProblem($group, $event->event_date->toDateString()))) {
                    throw new HttpResponseException($problem);
                }
                $event->save();
                $this->syncSoleFormGroup($event, $group);
            });
        } catch (QueryException $e) {
            RegistrationHeaderImage::delete($image['path'] ?? null);

            return $this->queryFailed($request, $event, $e);
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
            'data' => $this->detail($event->fresh(['location', 'previousEdition', 'event', 'group'])),
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

        $group = $event->group;
        if ($group !== null && !$this->canManageGroup($request, $group)) {
            return response()->json([
                'success' => false,
                'message' => 'This event runs at other locations too. Ask a company admin to remove this location from the event.',
            ], 403);
        }

        try {
            $outcome = DB::transaction(function () use ($request, $event) {
                $lockedGroup = $event->group_id === null
                    ? null
                    : RegistrationEventGroup::query()->whereKey($event->group_id)->lockForUpdate()->first();
                $form = RegistrationEvent::query()->whereKey($event->id)->lockForUpdate()->first();
                if ($form === null || (int) $form->group_id !== (int) $event->group_id) {
                    return ['response' => response()->json([
                        'success' => false,
                        'message' => 'This form changed while you were deleting it. Reload the page and try again.',
                    ], 409)];
                }

                $siblings = $lockedGroup === null
                    ? collect()
                    : RegistrationEvent::query()->where('group_id', $lockedGroup->id)->whereKeyNot($form->id)->lockForUpdate()->get(['id', 'location_id']);
                $allowed = $this->staffLocationIds($request);
                if ($siblings->contains(fn (RegistrationEvent $sibling) => !in_array((int) $sibling->location_id, $allowed, true))) {
                    return ['response' => response()->json([
                        'success' => false,
                        'message' => 'This event runs at other locations too. Ask a company admin to remove this location from the event.',
                    ], 403)];
                }

                $next = RegistrationEvent::query()
                    ->where('previous_edition_id', $form->id)
                    ->orderBy('event_date')
                    ->first();
                if ($next !== null) {
                    return ['response' => $this->linkedFormProblem($request, $next, '"%s" links to this form as last year\'s edition. Unlink it there first.')];
                }

                $lastForm = $lockedGroup !== null && $siblings->isEmpty();
                if ($lastForm && ($problem = $this->nextGroupProblem($request, $lockedGroup))) {
                    return ['response' => $problem];
                }

                $form->delete();
                if ($lastForm) {
                    $lockedGroup->delete();
                }

                return ['group' => $lockedGroup, 'group_deleted' => $lastForm];
            });
        } catch (QueryException $e) {
            return $this->queryFailed($request, $event, $e);
        }

        if (isset($outcome['response'])) {
            return $outcome['response'];
        }
        $group = $outcome['group'];
        $groupDeleted = $outcome['group_deleted'];

        ActivityLog::log(
            'registration_event_deleted',
            'event_registration',
            sprintf('Deleted the registration form "%s"', $event->name),
            $user->id,
            (int) $event->location_id,
            'registration_event',
            (int) $event->id,
            ['resource_name' => $event->name, 'group_id' => $group?->id]
        );

        if ($groupDeleted) {
            ActivityLog::log(
                'registration_event_group_deleted',
                'event_registration',
                sprintf('Deleted the registration event "%s" with its last location form', $group->name),
                $user->id,
                (int) $event->location_id,
                'registration_event_group',
                (int) $group->id,
                ['resource_name' => $group->name, 'form_ids' => [(int) $event->id]]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Registration form deleted.',
            'group_deleted' => $groupDeleted,
            'group_id' => $group !== null ? (int) $group->id : null,
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

        try {
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

            $counts = $this->registrations->counts($event);
        } catch (QueryException $e) {
            return $this->queryFailed($request, $event, $e);
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => ['counts' => $counts],
        ]));
    }
}
