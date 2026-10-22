<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStaff;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\PhotoOverlay;
use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Services\PhotoProcessingService;
use App\Support\OperatingDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PhotoOverlayController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(protected PhotoProcessingService $processor)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        if ($denied = $this->guardLocationAccess($request, $validated['location_id'])) {
            return $denied;
        }

        $location = Location::findOrFail($validated['location_id']);

        if ($denied = $this->guardCompanyAccess($request, $location->company_id)) {
            return $denied;
        }

        $overlays = PhotoOverlay::where('location_id', $location->id)
            ->with($this->overlayRelations())
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get()
            ->each(fn (PhotoOverlay $overlay) => $overlay->setRelation('location', $location));

        $active = $this->processor->resolveOverlay($location);

        return response()->json([
            'success' => true,
            'data' => [
                'overlays' => $overlays->map(fn ($overlay) => $this->present($overlay, $active?->id))->values(),
                'active_overlay_id' => $active?->id,
                'conflicts' => $this->processor->overlayConflicts($location->id),
                'date_layer_note' => 'The system-generated capture date is a separate layer and is always drawn above the uploaded overlay.',
            ],
        ]);
    }

    public function eventDays(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);

        if (!$location instanceof Location) {
            return $location;
        }

        if (!in_array((int) $location->id, $this->staffLocationIds($request), true)) {
            return $this->forbidden();
        }

        if (!PhotoOverlay::supportsEvents()) {
            return response()->json([
                'success' => true,
                'data' => ['available' => false, 'forms' => []],
            ]);
        }

        $forms = RegistrationEvent::query()
            ->with('event')
            ->where('company_id', $location->company_id)
            ->where('location_id', $location->id)
            ->where('event_date', '>=', OperatingDay::forLocation($location))
            ->orderBy('event_date')
            ->orderBy('id')
            ->get()
            ->each(fn (RegistrationEvent $form) => $form->setRelation('location', $location));

        $siblings = $this->isEffectiveAdmin($request) ? $this->siblingsOf($forms) : collect();
        $siblingIds = $siblings->flatMap(fn (Collection $list) => $list->pluck('id'))->map(fn ($id) => (int) $id)->all();
        $framed = $this->framedFormIds(array_values(array_unique(array_merge($forms->modelKeys(), $siblingIds))));
        $managed = $this->staffLocationIds($request);

        return response()->json([
            'success' => true,
            'data' => [
                'available' => true,
                'forms' => $forms->map(function (RegistrationEvent $form) use ($siblings, $framed, $managed, $location) {
                    [$start, $end] = $form->photoWindow($location);

                    return [
                        'id' => (int) $form->id,
                        'name' => $form->name,
                        'event_date' => $form->event_date->toDateString(),
                        'first_day' => $form->firstDay(),
                        'day_count' => $form->dayCount(),
                        'group_id' => $form->group_id !== null ? (int) $form->group_id : null,
                        'window_start' => $this->localIso($start, $location),
                        'window_end' => $this->localIso($end, $location),
                        'has_frame' => in_array((int) $form->id, $framed, true),
                        'siblings' => ($siblings->get($form->id) ?? collect())
                            ->map(function (RegistrationEvent $sibling) use ($managed, $framed) {
                                $reason = $this->siblingProblem($sibling, $managed, $framed);

                                return [
                                    'form_id' => (int) $sibling->id,
                                    'location_id' => (int) $sibling->location_id,
                                    'location_name' => $sibling->location?->name,
                                    'first_day' => $sibling->firstDay(),
                                    'event_date' => $sibling->event_date->toDateString(),
                                    'usable' => $reason === null,
                                    'reason' => $reason,
                                ];
                            })
                            ->values()
                            ->all(),
                    ];
                })->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:120'],
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'package_id' => ['nullable', 'integer'],
            'registration_event_id' => ['nullable', 'integer'],
            'also_other_locations' => ['nullable', 'boolean'],
        ]);

        if ($denied = $this->guardLocationAccess($request, $validated['location_id'])) {
            return $denied;
        }

        $location = Location::findOrFail($validated['location_id']);

        if ($denied = $this->guardCompanyAccess($request, $location->company_id)) {
            return $denied;
        }

        $roomId = $this->roomFor($validated['package_id'] ?? null, $location->id);

        if ($roomId === false) {
            return $this->invalidRoom();
        }

        $form = $this->eventFormFor($request->input('registration_event_id'), $location);

        if ($form === false) {
            return $this->invalidEventDay();
        }

        if ($roomId !== null && $form !== null) {
            return $this->roomAndEvent();
        }

        $attributes = [
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'priority' => $validated['priority'] ?? 0,
            'created_by' => $this->resolveAuthUser($request)?->id,
        ];

        if ($form !== null && $this->hoursMissEvent($attributes, $form, $location)) {
            return $this->hoursOutside($form, $location);
        }

        $plan = ['copies' => collect(), 'skipped' => []];

        if ($request->boolean('also_other_locations')) {
            if (!$this->isEffectiveAdmin($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only company admins can add a frame to other locations.',
                ], 403);
            }

            if ($form === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose an event day before adding the frame to the event\'s other locations.',
                    'errors' => ['also_other_locations' => ['Choose an event day first.']],
                ], 422);
            }

            $plan = $this->copyPlan($request, $form, $attributes);
        }

        $written = [];

        try {
            $path = $request->file('image')->store('photo-overlays/' . $location->id, 'public');
            $written[] = $path;
            $copyPaths = [];

            foreach ($plan['copies'] as $sibling) {
                $copyPath = 'photo-overlays/' . (int) $sibling->location_id . '/' . Str::random(40) . '.' . pathinfo($path, PATHINFO_EXTENSION);

                if (!Storage::disk('public')->copy($path, $copyPath)) {
                    throw new \RuntimeException('The frame could not be copied to ' . ($sibling->location?->name ?? 'another location') . '.');
                }

                $written[] = $copyPath;
                $copyPaths[(int) $sibling->id] = $copyPath;
            }

            [$overlay, $copies] = DB::transaction(function () use ($attributes, $path, $roomId, $form, $plan, $copyPaths) {
                $overlay = PhotoOverlay::create(array_merge(
                    $attributes,
                    ['image_path' => $path],
                    $roomId ? ['package_id' => $roomId] : [],
                    $form ? ['registration_event_id' => $form->id] : []
                ));

                $copies = $plan['copies']->map(fn (RegistrationEvent $sibling) => PhotoOverlay::create(array_merge($attributes, [
                    'company_id' => $sibling->company_id,
                    'location_id' => $sibling->location_id,
                    'registration_event_id' => $sibling->id,
                    'image_path' => $copyPaths[(int) $sibling->id],
                ])))->values();

                return [$overlay, $copies];
            });
        } catch (\Throwable $e) {
            foreach ($written as $file) {
                Storage::disk('public')->delete($file);
            }

            throw $e;
        }

        $this->flagConflicts($location);

        foreach ($plan['copies']->unique('location_id') as $sibling) {
            $this->flagConflicts($sibling->location);
        }

        foreach (collect([$overlay])->merge($copies) as $row) {
            ActivityLog::log(
                'photo_overlay_created',
                'photos',
                $row->eventFormId() !== null
                    ? sprintf('Uploaded the photo overlay "%s" for an event day', $row->name)
                    : sprintf('Uploaded the photo overlay "%s"', $row->name),
                $row->created_by,
                $row->location_id,
                'photo_overlay',
                $row->id
            );
        }

        $overlay->setRelation('location', $location);
        $overlay->load($this->overlayRelations());

        return response()->json([
            'success' => true,
            'data' => $this->present($overlay, $this->processor->resolveOverlay($location)?->id),
            'copies' => [
                'created' => $copies->map(fn (PhotoOverlay $copy) => [
                    'overlay_id' => $copy->id,
                    'location_name' => $plan['copies']->firstWhere('location_id', $copy->location_id)?->location?->name,
                ])->values(),
                'skipped' => $plan['skipped'],
            ],
        ], 201);
    }

    public function update(Request $request, PhotoOverlay $photoOverlay): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoOverlay)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'clear_schedule' => ['nullable', 'boolean'],
            'package_id' => ['nullable', 'integer'],
            'registration_event_id' => ['nullable', 'integer'],
        ]);

        $photoOverlay->loadMissing('location');
        $location = $photoOverlay->location;

        $changes = [];
        foreach (['name', 'starts_at', 'ends_at', 'is_enabled', 'priority'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $validated[$field] ?? null;
            }
        }

        $roomId = $photoOverlay->roomId();

        if ($request->has('package_id') && PhotoOverlay::supportsRooms()) {
            $requestedRoom = $this->roomFor($validated['package_id'] ?? null, (int) $photoOverlay->location_id);

            if ($requestedRoom === false) {
                return $this->invalidRoom();
            }

            $changes['package_id'] = $requestedRoom;
            $roomId = $requestedRoom;
        }

        $eventId = $photoOverlay->eventFormId();
        $form = null;

        if ($request->has('registration_event_id')) {
            $requested = $request->input('registration_event_id');
            $requestedId = $requested === null || $requested === '' || (string) $requested === '0' ? null : (int) $requested;

            if ($requestedId !== $eventId) {
                $form = $location ? $this->eventFormFor($requested, $location) : false;

                if ($form === false) {
                    return $this->invalidEventDay();
                }

                $changes['registration_event_id'] = $form?->id;
                $eventId = $form?->id;
            }
        }

        if ($roomId !== null && $eventId !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This overlay is set for an event day. Reload the page to change it.',
                'errors' => ['package_id' => ['This overlay is set for an event day.']],
            ], 422);
        }

        if ($request->boolean('clear_schedule')) {
            $changes['starts_at'] = null;
            $changes['ends_at'] = null;
        }

        $starts = $changes['starts_at'] ?? $photoOverlay->starts_at;
        $ends = $changes['ends_at'] ?? $photoOverlay->ends_at;

        if ($starts && $ends && strtotime((string) $ends) <= strtotime((string) $starts)) {
            return response()->json([
                'success' => false,
                'message' => 'The end of the schedule must come after its start.',
            ], 422);
        }

        if ($eventId !== null && $location
            && (array_key_exists('starts_at', $changes) || array_key_exists('ends_at', $changes) || array_key_exists('registration_event_id', $changes))) {
            $eventForm = $form ?: $photoOverlay->registrationEvent()->with('event')->first();

            if ($eventForm && !$eventForm->trashed() && (int) $eventForm->location_id === (int) $location->id) {
                $merged = [
                    'starts_at' => array_key_exists('starts_at', $changes) ? $changes['starts_at'] : $photoOverlay->starts_at,
                    'ends_at' => array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $photoOverlay->ends_at,
                ];

                if ($this->hoursMissEvent($merged, $eventForm, $location)) {
                    return $this->hoursOutside($eventForm, $location);
                }
            }
        }

        $previousImage = $photoOverlay->image_path;
        $newImage = null;

        if ($request->hasFile('image')) {
            $newImage = $request->file('image')->store('photo-overlays/' . $photoOverlay->location_id, 'public');
            $changes['image_path'] = $newImage;
        }

        try {
            $photoOverlay->update($changes);
        } catch (\Throwable $e) {
            if ($newImage) {
                Storage::disk('public')->delete($newImage);
            }

            throw $e;
        }

        if ($newImage && $previousImage && $previousImage !== $newImage && Storage::disk('public')->exists($previousImage)) {
            Storage::disk('public')->delete($previousImage);
        }

        $this->flagConflicts($location);

        ActivityLog::log(
            'photo_overlay_updated',
            'photos',
            sprintf('Updated the photo overlay "%s"', $photoOverlay->name),
            $this->resolveAuthUser($request)?->id,
            $photoOverlay->location_id,
            'photo_overlay',
            $photoOverlay->id,
            $changes
        );

        $fresh = $photoOverlay->fresh();
        $fresh->setRelation('location', $location);
        $fresh->load($this->overlayRelations());

        return response()->json([
            'success' => true,
            'data' => $this->present($fresh, $location ? $this->processor->resolveOverlay($location)?->id : null),
        ]);
    }

    public function destroy(Request $request, PhotoOverlay $photoOverlay): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoOverlay)) {
            return $this->forbidden();
        }

        $name = $photoOverlay->name;
        $locationId = $photoOverlay->location_id;
        $id = $photoOverlay->id;

        $photoOverlay->deleteMedia();
        $photoOverlay->delete();

        ActivityLog::log(
            'photo_overlay_deleted',
            'photos',
            sprintf('Deleted the photo overlay "%s"', $name),
            $this->resolveAuthUser($request)?->id,
            $locationId,
            'photo_overlay',
            $id
        );

        return response()->json([
            'success' => true,
            'message' => 'Overlay deleted. New photos will use the date layer only unless another overlay is active.',
        ]);
    }

    protected function flagConflicts(?Location $location): void
    {
        if (!$location) {
            return;
        }

        $conflicts = $this->processor->overlayConflicts($location->id);

        if ($conflicts === []) {
            return;
        }

        app(\App\Services\PhotoDeliveryService::class)->notifyBackend(
            $location,
            'Overlay schedule conflict',
            sprintf(
                '%d overlay schedule(s) overlap at %s. The highest priority overlay is used for new photos.',
                count($conflicts),
                $location->name
            ),
            ['conflicts' => $conflicts]
        );
    }

    protected function roomFor(mixed $packageId, int $locationId): int|false|null
    {
        if ($packageId === null || $packageId === '' || (int) $packageId === 0) {
            return null;
        }

        if (!PhotoOverlay::supportsRooms()) {
            return false;
        }

        $room = \App\Models\Package::escapeRooms()
            ->where('location_id', $locationId)
            ->find((int) $packageId, ['id']);

        return $room ? (int) $room->id : false;
    }

    protected function eventFormFor(mixed $value, Location $location): RegistrationEvent|false|null
    {
        if ($value === null || $value === '' || (string) $value === '0') {
            return null;
        }

        if (!PhotoOverlay::supportsEvents() || !ctype_digit((string) $value)) {
            return false;
        }

        $form = RegistrationEvent::query()
            ->with('event')
            ->where('company_id', $location->company_id)
            ->where('location_id', $location->id)
            ->whereKey((int) $value)
            ->first();

        if ($form === null || $form->event_date->toDateString() < OperatingDay::forLocation($location)) {
            return false;
        }

        $form->setRelation('location', $location);

        return $form;
    }

    protected function hoursMissEvent(array $hours, RegistrationEvent $form, Location $location): bool
    {
        $probe = new PhotoOverlay([
            'location_id' => $location->id,
            'registration_event_id' => $form->id,
            'starts_at' => $hours['starts_at'] ?? null,
            'ends_at' => $hours['ends_at'] ?? null,
            'is_enabled' => true,
        ]);
        $probe->setRelation('location', $location);
        $probe->setRelation('registrationEvent', $form);

        return $probe->effectiveWindow() === null;
    }

    protected function copyPlan(Request $request, RegistrationEvent $form, array $attributes): array
    {
        if ($form->group_id === null || !RegistrationEventGroup::isAvailable()) {
            return ['copies' => collect(), 'skipped' => []];
        }

        $siblings = RegistrationEvent::query()
            ->with(['location', 'event'])
            ->where('company_id', $form->company_id)
            ->where('group_id', $form->group_id)
            ->whereKeyNot($form->id)
            ->orderBy('id')
            ->get();

        $managed = $this->staffLocationIds($request);
        $framed = $this->framedFormIds($siblings->modelKeys());
        $copies = collect();
        $skipped = [];

        foreach ($siblings as $sibling) {
            $reason = $this->siblingProblem($sibling, $managed, $framed);

            if ($reason === null && $sibling->location && $this->hoursMissEvent($attributes, $sibling, $sibling->location)) {
                $reason = 'Those hours fall outside its event day(s).';
            }

            if ($reason !== null) {
                $skipped[] = ['location_name' => $sibling->location?->name, 'reason' => $reason];
                continue;
            }

            $copies->push($sibling);
        }

        return ['copies' => $copies, 'skipped' => $skipped];
    }

    protected function siblingsOf(Collection $forms): Collection
    {
        $groupIds = $forms->pluck('group_id')->filter()->unique()->values()->all();

        if ($groupIds === [] || !RegistrationEventGroup::isAvailable()) {
            return collect();
        }

        $all = RegistrationEvent::query()
            ->with(['location', 'event'])
            ->whereIn('group_id', $groupIds)
            ->orderBy('id')
            ->get();

        return $forms->mapWithKeys(fn (RegistrationEvent $form) => [
            $form->id => $form->group_id === null
                ? collect()
                : $all->filter(fn (RegistrationEvent $other) => (int) $other->group_id === (int) $form->group_id
                    && (int) $other->company_id === (int) $form->company_id
                    && (int) $other->id !== (int) $form->id)->values(),
        ]);
    }

    protected function siblingProblem(RegistrationEvent $sibling, array $managedLocationIds, array $framedFormIds): ?string
    {
        if (!in_array((int) $sibling->location_id, $managedLocationIds, true)) {
            return 'You don\'t manage that location.';
        }

        if ($sibling->event_date->toDateString() < OperatingDay::forLocation($sibling->location)) {
            return 'Its event day has passed.';
        }

        if (in_array((int) $sibling->id, $framedFormIds, true)) {
            return 'It already has a photo frame.';
        }

        return null;
    }

    protected function framedFormIds(array $formIds): array
    {
        if ($formIds === [] || !PhotoOverlay::supportsEvents()) {
            return [];
        }

        return PhotoOverlay::query()
            ->whereIn('registration_event_id', $formIds)
            ->pluck('registration_event_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function isEffectiveAdmin(Request $request): bool
    {
        $user = $this->resolveAuthUser($request);

        return $user !== null && in_array(EnsureStaff::effectiveRole($user), ['company_admin', 'admin'], true);
    }

    protected function overlayRelations(): array
    {
        return array_merge(
            ['creator'],
            PhotoOverlay::supportsRooms() ? ['package:id,name'] : [],
            PhotoOverlay::supportsEvents() ? ['registrationEvent.event'] : []
        );
    }

    protected function invalidRoom(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Choose one of this location\'s escape rooms, or leave the overlay for all photos.',
            'errors' => ['package_id' => ['Choose one of this location\'s escape rooms.']],
        ], 422);
    }

    protected function invalidEventDay(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Choose one of this location\'s upcoming event days, or leave the overlay for all photos.',
            'errors' => ['registration_event_id' => ['Choose one of this location\'s upcoming event days.']],
        ], 422);
    }

    protected function roomAndEvent(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'An overlay can be for an escape room or for an event day, not both.',
            'errors' => ['registration_event_id' => ['Choose an escape room or an event day, not both.']],
        ], 422);
    }

    protected function hoursOutside(RegistrationEvent $form, Location $location): JsonResponse
    {
        [$start, $end] = $form->photoWindow($location);

        return response()->json([
            'success' => false,
            'message' => sprintf('Those hours fall outside the event\'s day(s): %s.', $this->describeWindow($start, $end, $location)),
            'errors' => ['starts_at' => ['Those hours fall outside the event\'s day(s).']],
        ], 422);
    }

    protected function describeWindow(Carbon $start, Carbon $end, Location $location): string
    {
        $tz = OperatingDay::timezoneFor($location);

        return $start->copy()->setTimezone($tz)->format('D, M j, g:i A') . ' to ' . $end->copy()->setTimezone($tz)->format('D, M j, g:i A');
    }

    protected function localIso(?Carbon $at, ?Location $location): ?string
    {
        return $at?->copy()->setTimezone(OperatingDay::timezoneFor($location))->toIso8601String();
    }

    protected function presentEvent(PhotoOverlay $overlay): ?array
    {
        if ($overlay->eventFormId() === null) {
            return null;
        }

        $form = $overlay->registrationEvent;
        $removed = $overlay->eventFormRemoved();
        $window = $removed ? null : $overlay->eventWindow();
        $effective = $removed ? null : $overlay->effectiveWindow();

        return [
            'id' => (int) $overlay->registration_event_id,
            'name' => $form?->name,
            'first_day' => $form && !$removed ? $form->firstDay() : $form?->event_date?->toDateString(),
            'event_date' => $form?->event_date?->toDateString(),
            'day_count' => $form && !$removed ? $form->dayCount() : null,
            'window_start' => $window ? $this->localIso($window[0], $overlay->location) : null,
            'window_end' => $window ? $this->localIso($window[1], $overlay->location) : null,
            'effective_start' => $effective ? $this->localIso($effective[0], $overlay->location) : null,
            'effective_end' => $effective ? $this->localIso($effective[1], $overlay->location) : null,
            'location_form_removed' => $removed,
            'never_runs' => !$removed && $effective === null,
        ];
    }

    protected function present(PhotoOverlay $overlay, ?int $activeId): array
    {
        if ($overlay->roomId() && $overlay->location) {
            $activeId = $this->processor->resolveOverlay($overlay->location, now(), $overlay->roomId())?->id;
        }

        return [
            'id' => $overlay->id,
            'location_id' => $overlay->location_id,
            'package_id' => $overlay->roomId(),
            'room_name' => $overlay->roomId() ? $overlay->loadMissing('package:id,name')->package?->name : null,
            'registration_event_id' => $overlay->eventFormId(),
            'event' => $this->presentEvent($overlay),
            'name' => $overlay->name,
            'image_url' => $overlay->image_path ? Storage::disk('public')->url($overlay->image_path) : null,
            'starts_at' => $overlay->starts_at?->toIso8601String(),
            'ends_at' => $overlay->ends_at?->toIso8601String(),
            'is_enabled' => $overlay->is_enabled,
            'priority' => $overlay->priority,
            'status' => $overlay->status(),
            'is_active' => $activeId !== null && $activeId === $overlay->id,
            'created_by_name' => $overlay->relationLoaded('creator') && $overlay->creator
                ? trim(($overlay->creator->first_name ?? '') . ' ' . ($overlay->creator->last_name ?? ''))
                : null,
            'created_at' => $overlay->created_at?->toIso8601String(),
        ];
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have access to that overlay.',
        ], 403);
    }
}
