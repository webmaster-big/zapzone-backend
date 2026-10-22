<?php

namespace App\Http\Traits;

use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\Location;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Models\User;
use App\Support\RegistrationHeaderImage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait ManagesRegistrationForms
{
    protected const IMAGE_PROBLEM = "We couldn't use that image. Please upload a PNG, JPEG or WebP under 8 MB, ideally about 1600 × 800.";

    protected function staffUser(Request $request): User
    {
        $user = $this->resolveAuthUser($request);

        if (!$user instanceof User) {
            abort(403);
        }

        return $user;
    }

    protected function accessibleEvent(Request $request, int $id): RegistrationEvent|JsonResponse
    {
        $user = $this->staffUser($request);

        $event = RegistrationEvent::query()
            ->with(['location', 'previousEdition', 'event', 'group'])
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

    protected function validatedInput(Request $request, bool $creating, bool $locationsAllowed = false): array|JsonResponse
    {
        $input = [];

        foreach (['name', 'event_date', 'description', 'confirmation_message'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                $input[$field] = is_string($value) ? trim($value) : $value;
            }
        }

        foreach (['location_id', 'previous_edition_id', 'is_open', 'remove_header_image', 'event_id', 'use_event_image', 'copy_header_image_from', 'event_links', 'previous_group_id', 'copy_header_image_from_group', 'confirm_remove'] as $field) {
            if ($request->has($field)) {
                $input[$field] = $request->input($field);
            }
        }

        if (($creating || $locationsAllowed) && $request->has('location_ids')) {
            $input['location_ids'] = $request->input('location_ids');
            unset($input['location_id']);
        }

        if ($locationsAllowed && $request->has('move_form_ids')) {
            $input['move_form_ids'] = $request->input('move_form_ids');
        }

        if ($locationsAllowed && $request->has('expected_location_ids')) {
            $input['expected_location_ids'] = $request->input('expected_location_ids');
        }

        foreach (['description', 'confirmation_message', 'previous_edition_id', 'event_id', 'copy_header_image_from', 'previous_group_id', 'copy_header_image_from_group'] as $field) {
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
            'location_id' => $creating ? ['required_without:location_ids', 'integer'] : ['sometimes', 'required', 'integer'],
            'location_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'location_ids.*' => ['integer', 'distinct'],
            'event_id' => ['nullable', 'integer'],
            'event_links' => ['sometimes', 'array', 'max:50'],
            'event_links.*' => ['nullable', 'integer', 'min:1'],
            'use_event_image' => ['sometimes', 'boolean'],
            'copy_header_image_from' => ['nullable', 'integer'],
            'copy_header_image_from_group' => ['nullable', 'integer'],
            'previous_group_id' => ['nullable', 'integer'],
            'confirm_remove' => ['sometimes', 'boolean'],
            'move_form_ids' => ['sometimes', 'array', 'max:50'],
            'move_form_ids.*' => ['integer', 'distinct'],
            'expected_location_ids' => ['sometimes', 'array', 'max:50'],
            'expected_location_ids.*' => ['integer'],
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
            'location_id.required_without' => 'Choose a location for this form.',
            'location_id.integer' => 'Choose a location for this form.',
            'location_ids.array' => 'Choose at least one location.',
            'location_ids.min' => 'Choose at least one location.',
            'location_ids.max' => 'Choose up to 50 locations at a time.',
            'location_ids.*.integer' => 'Choose a location from the list.',
            'location_ids.*.distinct' => 'Each location can only be chosen once.',
            'event_id.integer' => 'Choose an event from the list.',
            'event_links.array' => 'Choose an event at each location.',
            'event_links.max' => 'Choose an event at each location.',
            'event_links.*.integer' => 'Choose an event at each location.',
            'event_links.*.min' => 'Choose an event at each location.',
            'event_date.required' => 'Choose the event date.',
            'event_date.date_format' => 'Choose the event date.',
            'description.max' => 'Please keep the description under 2,000 characters.',
            'confirmation_message.max' => 'Please keep the confirmation message under 500 characters.',
            'previous_edition_id.integer' => "Choose last year's edition from the list.",
            'previous_group_id.integer' => "Choose last year's event from the list.",
            'move_form_ids.array' => 'Choose the forms to move into this event.',
            'move_form_ids.max' => 'Move up to 50 forms at a time.',
            'move_form_ids.*.integer' => 'Choose the forms to move into this event.',
            'move_form_ids.*.distinct' => 'Each form can only be moved once.',
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

    protected function editionProblem(?RegistrationEvent $event, int $companyId, int $locationId, string $eventDate, ?int $previousId, bool $previousChanged): ?JsonResponse
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

    protected function eventLinks(mixed $raw, array $locationIds): array|JsonResponse
    {
        if (!is_array($raw)) {
            return [];
        }

        $wanted = [];
        foreach ($raw as $locationId => $eventId) {
            if ($eventId === null || $eventId === '') {
                continue;
            }
            if (!in_array((int) $locationId, $locationIds, true)) {
                return $this->fieldError('event_links', 'Link events only to the locations you picked.');
            }
            $wanted[(int) $locationId] = (int) $eventId;
        }

        if ($wanted === []) {
            return [];
        }

        $events = Event::query()->whereKey(array_values($wanted))->get()->keyBy(fn (Event $event) => (int) $event->id);
        $links = [];
        foreach ($wanted as $locationId => $eventId) {
            $linked = $events->get($eventId);
            if ($linked === null || (int) $linked->location_id !== $locationId) {
                return $this->fieldError('event_links', 'Choose an event at each location.');
            }
            $links[$locationId] = $linked;
        }

        return $links;
    }

    protected function lastDayOf(Event $event): ?string
    {
        return ($event->end_date ?? $event->start_date)?->toDateString();
    }

    protected function locationLocked(RegistrationEvent $event): bool
    {
        return RegistrationEntry::query()->where('registration_event_id', $event->id)->exists()
            || RegistrationEvent::query()->where('previous_edition_id', $event->id)->exists();
    }

    protected function nextEditionsFor(array $eventIds): Collection
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

    protected function summary(RegistrationEvent $event, array $counts, Collection $next): array
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
            'group' => $event->group === null ? null : [
                'id' => (int) $event->group->id,
                'name' => $event->group->name,
                'event_date' => $event->group->event_date->toDateString(),
            ],
            'event' => $event->event === null || (int) $event->event->location_id !== (int) $event->location_id ? null : [
                'id' => (int) $event->event->id,
                'name' => $event->event->name,
                'start_date' => $event->event->start_date?->toDateString(),
                'end_date' => $event->event->end_date?->toDateString(),
            ],
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

    protected function detail(RegistrationEvent $event): array
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

    protected function deleteImageIfUnused(string $path): void
    {
        $stillUsed = RegistrationEvent::withTrashed()->where('header_image_path', $path)->exists()
            || (RegistrationEventGroup::isAvailable() && RegistrationEventGroup::withTrashed()->where('header_image_path', $path)->exists());

        if (!$stillUsed) {
            RegistrationHeaderImage::delete($path);
        }
    }

    protected function chosenLocations(User $user, array $data): array|JsonResponse
    {
        $field = isset($data['location_ids']) ? 'location_ids' : 'location_id';
        $locationIds = isset($data['location_ids'])
            ? array_values(array_unique(array_map('intval', $data['location_ids'])))
            : [(int) $data['location_id']];

        $known = Location::query()->where('company_id', $user->company_id)->whereKey($locationIds)->count();
        if ($known !== count($locationIds)) {
            return $this->fieldError($field, 'Choose a location for this form.');
        }

        foreach ($locationIds as $locationId) {
            if ($denied = $this->denyStaffElsewhere($locationId, 'location')) {
                return $denied;
            }
        }

        return $locationIds;
    }

    protected function sourceEvent(Request $request, User $user, array $data): Event|JsonResponse|null
    {
        if (!isset($data['event_id'])) {
            return null;
        }

        $event = Event::query()
            ->whereKey((int) $data['event_id'])
            ->whereIn('location_id', $this->staffLocationIds($request))
            ->whereHas('location', fn ($query) => $query->where('company_id', $user->company_id))
            ->first();

        return $event ?? $this->fieldError('event_id', 'Choose an event from your locations.');
    }

    protected function linkPlan(?Event $source, array $links, array $locationIds, string $eventDate): array
    {
        if ($source !== null && in_array((int) $source->location_id, $locationIds, true) && !isset($links[(int) $source->location_id])) {
            $links[(int) $source->location_id] = $source;
        }

        $dates = [];
        foreach ($locationIds as $locationId) {
            $linked = $links[$locationId] ?? null;
            $dates[$locationId] = $linked !== null && (int) $linked->id !== (int) $source?->id
                ? ($this->lastDayOf($linked) ?? $eventDate)
                : $eventDate;
        }

        return [$links, $dates];
    }

    protected function previousGroupFor(Request $request, User $user, int $id, string $eventDate, ?RegistrationEventGroup $self): RegistrationEventGroup|JsonResponse
    {
        $group = RegistrationEventGroup::query()
            ->where('company_id', $user->company_id)
            ->whereKey($id)
            ->when($self !== null, fn ($query) => $query->whereKeyNot($self->id))
            ->whereHas('forms', fn ($query) => $query->whereIn('location_id', $this->staffLocationIds($request)))
            ->first();

        if ($group === null) {
            return $this->fieldError('previous_group_id', "Choose last year's event from the list.");
        }

        if ($group->event_date->toDateString() >= $eventDate) {
            return $this->fieldError('previous_group_id', "Last year's event must be before this one.");
        }

        return $group;
    }

    protected function previousFormsByLocation(?RegistrationEventGroup $previous, array $locationIds): array
    {
        if ($previous === null || $locationIds === []) {
            return [];
        }

        return RegistrationEvent::query()
            ->where('group_id', $previous->id)
            ->whereIn('location_id', $locationIds)
            ->orderBy('id')
            ->get(['id', 'location_id'])
            ->mapWithKeys(fn (RegistrationEvent $form) => [(int) $form->location_id => (int) $form->id])
            ->all();
    }

    protected function ownedImage(array $data, ?Event $source): array|JsonResponse|null
    {
        if (($data['header_image'] ?? null) instanceof UploadedFile) {
            $image = RegistrationHeaderImage::process($data['header_image']);
            if ($image === null) {
                return $this->fieldError('header_image', self::IMAGE_PROBLEM);
            }
            $image['path'] = RegistrationHeaderImage::store($image['binary']);

            return $image;
        }

        if (empty($data['copy_header_image_from']) && empty($data['copy_header_image_from_group']) && !empty($data['use_event_image']) && $source !== null) {
            $image = RegistrationHeaderImage::fromStoredImage($source->image);
            if ($image !== null) {
                $image['path'] = RegistrationHeaderImage::store($image['binary']);
            }

            return $image;
        }

        return null;
    }

    protected function sharedHeader(User $user, array $staffLocations, ?int $formId, ?int $groupId): ?array
    {
        $source = null;
        if ($formId !== null) {
            $source = RegistrationEvent::query()
                ->where('company_id', $user->company_id)
                ->whereIn('location_id', $staffLocations)
                ->whereKey($formId)
                ->sharedLock()
                ->first();
        } elseif ($groupId !== null) {
            $source = RegistrationEventGroup::query()
                ->where('company_id', $user->company_id)
                ->whereKey($groupId)
                ->whereHas('forms', fn ($query) => $query->whereIn('location_id', $staffLocations))
                ->sharedLock()
                ->first();
        }

        if ($source === null || $source->header_image_path === null) {
            return null;
        }

        return [
            'path' => $source->header_image_path,
            'width' => $source->header_image_width,
            'height' => $source->header_image_height,
        ];
    }

    protected function insertForms(RegistrationEventGroup $group, array $locationIds, array $links, array $dates, array $previousByLocation, ?array $header, bool $isOpen, User $user): array
    {
        $forms = [];
        foreach ($locationIds as $locationId) {
            $form = new RegistrationEvent([
                'location_id' => $locationId,
                'event_id' => isset($links[$locationId]) ? (int) $links[$locationId]->id : null,
                'name' => $group->name,
                'event_date' => $dates[$locationId] ?? $group->event_date->toDateString(),
                'description' => $group->description,
                'confirmation_message' => $group->confirmation_message,
                'previous_edition_id' => $previousByLocation[$locationId] ?? null,
                'is_open' => $isOpen,
                'header_image_path' => $header['path'] ?? null,
                'header_image_width' => $header['width'] ?? null,
                'header_image_height' => $header['height'] ?? null,
            ]);
            $form->company_id = $user->company_id;
            $form->group_id = $group->id;
            $form->created_by = $user->id;
            $form->save();
            $forms[] = $form;
        }

        return $forms;
    }

    protected function createEventWithForms(Request $request, User $user, array $data): array|JsonResponse
    {
        $locationIds = $this->chosenLocations($user, $data);
        if ($locationIds instanceof JsonResponse) {
            return $locationIds;
        }

        $previousGroup = null;
        $legacyPrevious = null;
        if (isset($data['previous_group_id'])) {
            $previousGroup = $this->previousGroupFor($request, $user, (int) $data['previous_group_id'], $data['event_date'], null);
            if ($previousGroup instanceof JsonResponse) {
                return $previousGroup;
            }
        } elseif (isset($data['previous_edition_id'])) {
            $legacyPrevious = (int) $data['previous_edition_id'];
            if (count($locationIds) > 1) {
                return $this->fieldError('previous_edition_id', "Link last year's edition one location at a time: create these forms first, then edit each one.");
            }
        }

        $source = $this->sourceEvent($request, $user, $data);
        if ($source instanceof JsonResponse) {
            return $source;
        }

        $links = $this->eventLinks($data['event_links'] ?? [], $locationIds);
        if ($links instanceof JsonResponse) {
            return $links;
        }

        [$links, $dates] = $this->linkPlan($source, $links, $locationIds, $data['event_date']);

        if ($legacyPrevious !== null) {
            if ($problem = $this->editionProblem(null, (int) $user->company_id, $locationIds[0], $dates[$locationIds[0]], $legacyPrevious, true)) {
                return $problem;
            }
            $previousByLocation = [$locationIds[0] => $legacyPrevious];
            $previousGroupId = RegistrationEvent::query()->whereKey($legacyPrevious)->value('group_id');
        } else {
            $previousByLocation = $this->previousFormsByLocation($previousGroup, $locationIds);
            $previousGroupId = $previousGroup?->id;
        }

        $ownedImage = $this->ownedImage($data, $source);
        if ($ownedImage instanceof JsonResponse) {
            return $ownedImage;
        }

        $copyFormId = $ownedImage === null && !empty($data['copy_header_image_from']) ? (int) $data['copy_header_image_from'] : null;
        $copyGroupId = $ownedImage === null && $copyFormId === null && !empty($data['copy_header_image_from_group']) ? (int) $data['copy_header_image_from_group'] : null;
        $staffLocations = $this->staffLocationIds($request);
        $isOpen = array_key_exists('is_open', $data) ? (bool) $data['is_open'] : true;

        try {
            $result = DB::transaction(function () use ($data, $locationIds, $links, $dates, $previousByLocation, $previousGroupId, $ownedImage, $copyFormId, $copyGroupId, $staffLocations, $isOpen, $user) {
                $header = $ownedImage ?? $this->sharedHeader($user, $staffLocations, $copyFormId, $copyGroupId);

                $group = new RegistrationEventGroup([
                    'name' => $data['name'],
                    'event_date' => $data['event_date'],
                    'description' => $data['description'] ?? null,
                    'confirmation_message' => $data['confirmation_message'] ?? null,
                    'header_image_path' => $header['path'] ?? null,
                    'header_image_width' => $header['width'] ?? null,
                    'header_image_height' => $header['height'] ?? null,
                    'previous_group_id' => $previousGroupId !== null ? (int) $previousGroupId : null,
                ]);
                $group->company_id = $user->company_id;
                $group->created_by = $user->id;
                $group->save();

                return [
                    'group' => $group,
                    'forms' => $this->insertForms($group, $locationIds, $links, $dates, $previousByLocation, $header, $isOpen, $user),
                ];
            });
        } catch (QueryException $e) {
            RegistrationHeaderImage::delete($ownedImage['path'] ?? null);

            return $this->queryFailed($request, null, $e);
        } catch (\Throwable $e) {
            RegistrationHeaderImage::delete($ownedImage['path'] ?? null);

            throw $e;
        }

        ActivityLog::log(
            'registration_event_group_created',
            'event_registration',
            sprintf('Created the registration event "%s" for %d location%s', $result['group']->name, count($result['forms']), count($result['forms']) === 1 ? '' : 's'),
            $user->id,
            count($result['forms']) === 1 ? (int) $result['forms'][0]->location_id : null,
            'registration_event_group',
            (int) $result['group']->id,
            ['resource_name' => $result['group']->name, 'location_ids' => $locationIds]
        );
        $this->logFormsCreated($result['forms'], $user);

        return $result;
    }

    protected function logFormsCreated(array $forms, User $user): void
    {
        foreach ($forms as $form) {
            ActivityLog::log(
                'registration_event_created',
                'event_registration',
                sprintf('Created the registration form "%s"', $form->name),
                $user->id,
                (int) $form->location_id,
                'registration_event',
                (int) $form->id,
                ['resource_name' => $form->name, 'event_date' => $form->event_date->toDateString(), 'event_id' => $form->event_id, 'group_id' => $form->group_id]
            );
        }
    }

    protected function canManageGroup(Request $request, RegistrationEventGroup $group): bool
    {
        $allowed = $this->staffLocationIds($request);

        return RegistrationEvent::query()
            ->where('group_id', $group->id)
            ->pluck('location_id')
            ->map(fn ($id) => (int) $id)
            ->every(fn (int $id) => in_array($id, $allowed, true));
    }

    protected function managedGroupIds(Request $request, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $allowed = $this->staffLocationIds($request);

        return RegistrationEvent::query()
            ->whereIn('group_id', $groupIds)
            ->get(['group_id', 'location_id'])
            ->groupBy(fn (RegistrationEvent $form) => (int) $form->group_id)
            ->filter(fn (Collection $forms) => $forms->every(fn (RegistrationEvent $form) => in_array((int) $form->location_id, $allowed, true)))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function groupVisible(Request $request, int $groupId): bool
    {
        return RegistrationEvent::query()
            ->where('group_id', $groupId)
            ->whereIn('location_id', $this->staffLocationIds($request))
            ->exists();
    }

    protected function nextGroupProblem(Request $request, RegistrationEventGroup $group): ?JsonResponse
    {
        $next = RegistrationEventGroup::query()
            ->where('previous_group_id', $group->id)
            ->whereHas('forms')
            ->orderBy('event_date')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $this->groupVisible($request, (int) $next->id)
                ? sprintf('"%s" links to this event as last year\'s event. Unlink it there first.', $next->name)
                : "Another event links to this one as last year's event. Ask a company admin to unlink it first.",
        ], 422);
    }

    protected function linkedFormProblem(Request $request, RegistrationEvent $linking, string $visibleMessage): JsonResponse
    {
        $visible = in_array((int) $linking->location_id, $this->staffLocationIds($request), true);

        return response()->json([
            'success' => false,
            'message' => $visible
                ? sprintf($visibleMessage, $linking->name)
                : "Another location's form links to this one as last year's edition. Ask a company admin to unlink it first.",
        ], 422);
    }

    protected function groupOrderProblem(RegistrationEventGroup $group, string $eventDate): ?JsonResponse
    {
        $previous = $group->previous_group_id === null
            ? null
            : RegistrationEventGroup::query()->whereKey($group->previous_group_id)->whereHas('forms')->first();

        if ($previous !== null && $previous->event_date->toDateString() >= $eventDate) {
            return $this->fieldError('event_date', "The date must stay after last year's event.");
        }

        $nextDate = RegistrationEventGroup::query()->where('previous_group_id', $group->id)->whereHas('forms')->min('event_date');
        if ($nextDate !== null && $eventDate >= substr((string) $nextDate, 0, 10)) {
            return $this->fieldError('event_date', "The date must stay before next year's event.");
        }

        return null;
    }

    protected function queryFailed(Request $request, ?RegistrationEvent $event, QueryException $e): JsonResponse
    {
        Log::error('Registration forms request failed', [
            'registration_event_id' => $event?->id,
            'sqlstate' => $e->errorInfo[0] ?? null,
            'driver_code' => $e->errorInfo[1] ?? null,
            'request_id' => $request->attributes->get('request_id'),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Something went wrong. Please try again.',
        ], 500);
    }

    protected function fieldError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    protected function unavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'MODULE_UNAVAILABLE',
            'message' => 'Registration forms are not set up yet.',
        ], 503);
    }

    protected function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store');
    }
}
