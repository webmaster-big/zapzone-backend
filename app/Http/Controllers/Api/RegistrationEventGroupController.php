<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ManagesRegistrationForms;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\PhotoOverlay;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Models\User;
use App\Services\EventRegistrationService;
use App\Support\RegistrationFormGrouping;
use App\Support\RegistrationHeaderImage;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegistrationEventGroupController extends Controller
{
    use ScopesByAuthUser;
    use ManagesRegistrationForms;

    private const MERGE_WINDOW_DAYS = 31;

    public function __construct(private EventRegistrationService $registrations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (!RegistrationEventGroup::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $locationIds = $this->scopedLocations($request);
        $thisYear = Carbon::now('America/Detroit')->year;

        $forms = RegistrationEvent::query()
            ->with(['location', 'group'])
            ->where('company_id', $user->company_id)
            ->whereIn('location_id', $locationIds)
            ->whereNotNull('group_id')
            ->whereHas('group')
            ->get();

        $years = $forms->toBase()
            ->map(fn (RegistrationEvent $form) => (int) $form->group->event_date->format('Y'))
            ->push($thisYear)
            ->unique()
            ->sortDesc()
            ->values();

        $requested = filter_var($request->query('year'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        $year = $requested !== false ? $requested : $thisYear;

        $inYear = $forms->filter(fn (RegistrationEvent $form) => (int) $form->group->event_date->format('Y') === $year)->values();
        $formIds = $inYear->pluck('id')->map(fn ($id) => (int) $id)->all();
        $byGroup = $this->registrations->countsByGroup($formIds);
        $totals = $this->registrations->scopeCounts($formIds, false);
        $managed = $this->managedGroupIds($request, $inYear->pluck('group_id')->unique()->all());

        $groups = $inYear->groupBy(fn (RegistrationEvent $form) => (int) $form->group_id)
            ->map(function (Collection $groupForms, int $groupId) use ($byGroup, $managed) {
                $group = $groupForms->first()->group;

                return [
                    'id' => $groupId,
                    'name' => $group->name,
                    'event_date' => $group->event_date->toDateString(),
                    'status' => $this->groupStatus($groupForms),
                    'location_forms' => $groupForms->count(),
                    'location_names' => $groupForms->toBase()->map(fn (RegistrationEvent $form) => $form->location?->name)->filter()->sort()->values()->all(),
                    'total_entries' => (int) ($byGroup[$groupId]['total_entries'] ?? 0),
                    'unique_registrations' => (int) ($byGroup[$groupId]['unique_registrations'] ?? 0),
                    'can_manage' => in_array($groupId, $managed, true),
                ];
            })
            ->sortBy([['event_date', 'desc'], ['id', 'desc']])
            ->values();

        return $this->noStore(response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'years' => $years->all(),
                'totals' => [
                    'total_entries' => $totals['total_entries'],
                    'unique_registrations' => $totals['unique_registrations'],
                    'events' => $groups->count(),
                    'location_forms' => $inYear->count(),
                ],
                'event_groups' => $groups->all(),
            ],
        ]));
    }

    public function store(Request $request): JsonResponse
    {
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

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $this->groupDetail($request, $result['group']->fresh()),
        ], 201));
    }

    public function show(Request $request, int $registrationEventGroup): JsonResponse
    {
        if (!RegistrationEventGroup::isAvailable()) {
            return $this->unavailable();
        }

        $group = $this->accessibleGroup($request, $registrationEventGroup);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $this->groupDetail($request, $group),
        ]));
    }

    public function update(Request $request, int $registrationEventGroup): JsonResponse
    {
        if (!RegistrationEventGroup::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $group = $this->accessibleGroup($request, $registrationEventGroup);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        if (!$this->canManageGroup($request, $group)) {
            return $this->notManager();
        }

        $data = $this->validatedInput($request, false, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $currentForms = RegistrationEvent::query()->with('location')->where('group_id', $group->id)->get();
        $currentLocations = $currentForms->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (array_diff($currentLocations, $this->staffLocationIds($request)) !== []) {
            return $this->notManager();
        }
        if (array_key_exists('expected_location_ids', $data) && !$this->sameLocations($data['expected_location_ids'], $currentLocations)) {
            return $this->eventChanged();
        }
        $dateSent = array_key_exists('event_date', $data);
        $eventDate = $data['event_date'] ?? $group->event_date->toDateString();

        $previousSent = array_key_exists('previous_group_id', $data);
        $previousGroup = $group->previousGroup;
        if ($previousSent) {
            $previousGroup = null;
            if ($data['previous_group_id'] !== null) {
                $previousGroup = $this->previousGroupFor($request, $user, (int) $data['previous_group_id'], $eventDate, $group);
                if ($previousGroup instanceof JsonResponse) {
                    return $previousGroup;
                }
            }
        } elseif ($previousGroup !== null && $previousGroup->event_date->toDateString() >= $eventDate && $previousGroup->forms()->exists()) {
            return $this->fieldError('event_date', "The date must stay after last year's event.");
        }

        $nextDate = RegistrationEventGroup::query()->where('previous_group_id', $group->id)->whereHas('forms')->min('event_date');
        if ($nextDate !== null && $eventDate >= substr((string) $nextDate, 0, 10)) {
            return $this->fieldError('event_date', "The date must stay before next year's event.");
        }

        $toAdd = [];
        $toRemove = [];
        if (array_key_exists('location_ids', $data)) {
            $desired = $this->chosenLocations($user, ['location_ids' => $data['location_ids']]);
            if ($desired instanceof JsonResponse) {
                return $desired;
            }
            $toAdd = array_values(array_diff($desired, $currentLocations));
            $toRemove = array_values(array_diff($currentLocations, $desired));
        }

        $moves = $this->formsToMove($request, $user, $group, $data, $toAdd, $eventDate);
        if ($moves instanceof JsonResponse) {
            return $moves;
        }
        $toCreate = array_values(array_diff($toAdd, $moves->keys()->all()));

        $removeForms = $currentForms->filter(fn (RegistrationEvent $form) => in_array((int) $form->location_id, $toRemove, true))->values();
        if ($blocked = $this->removalProblem($request, $removeForms, !empty($data['confirm_remove']))) {
            return $blocked;
        }

        $source = $this->sourceEvent($request, $user, $data);
        if ($source instanceof JsonResponse) {
            return $source;
        }

        $links = $this->eventLinks($data['event_links'] ?? [], $toAdd);
        if ($links instanceof JsonResponse) {
            return $links;
        }

        $moveLinks = array_intersect_key($links, $moves->all());
        [$links, $dates] = $this->linkPlan($source, array_intersect_key($links, array_flip($toCreate)), $toCreate, $eventDate);
        $previousByLocation = $this->previousFormsByLocation($previousGroup, array_values(array_unique(array_merge($currentLocations, $toAdd))));

        if ($dateSent || $previousSent) {
            $staying = $currentForms
                ->reject(fn (RegistrationEvent $form) => in_array((int) $form->location_id, $toRemove, true))
                ->concat($moves->values());
            if ($problem = $this->orderProblem($user, $staying, $toCreate, $dates, $dateSent, $eventDate, $previousSent, $previousByLocation)) {
                return $problem;
            }
        }

        $ownedImage = $this->ownedImage($data, $source);
        if ($ownedImage instanceof JsonResponse) {
            return $ownedImage;
        }

        $removeImage = $ownedImage === null && !empty($data['remove_header_image']);
        $oldPaths = $currentForms->pluck('header_image_path')
            ->concat($moves->pluck('header_image_path'))
            ->push($group->header_image_path)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $isOpen = array_key_exists('is_open', $data) ? (bool) $data['is_open'] : null;
        $sourceIds = $moves->pluck('group_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $plannedFormIds = $currentForms->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        try {
            $result = DB::transaction(function () use ($group, $data, $eventDate, $previousSent, $previousGroup, $ownedImage, $removeImage, $toRemove, $toCreate, $moves, $moveLinks, $sourceIds, $plannedFormIds, $links, $dates, $previousByLocation, $isOpen, $user) {
                $locked = $this->lockEvents((int) $group->id, $sourceIds);
                $liveForms = RegistrationEvent::query()->with('location')->where('group_id', $locked->id)->lockForUpdate()->get();
                if ($liveForms->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() !== $plannedFormIds) {
                    throw new HttpResponseException($this->eventChanged());
                }
                $liveLocations = $liveForms->pluck('location_id')->map(fn ($id) => (int) $id)->all();
                $removed = $liveForms->filter(fn (RegistrationEvent $form) => in_array((int) $form->location_id, $toRemove, true))->values();
                $kept = $liveForms->reject(fn (RegistrationEvent $form) => in_array((int) $form->location_id, $toRemove, true))->values();
                $moving = $this->lockMovingForms($moves, $liveLocations);
                $creating = array_values(array_diff($toCreate, $liveLocations));
                if ($kept->isEmpty() && $moving->isEmpty() && $creating === []) {
                    abort(409, 'This event changed while you were saving. Reload the page and try again.');
                }
                $newFormsOpen = $isOpen ?? !($kept->isNotEmpty() && $kept->every(fn (RegistrationEvent $form) => !$form->is_open));

                $attributes = [];
                foreach (['name', 'description', 'confirmation_message'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $attributes[$field] = $data[$field];
                    }
                }
                if (array_key_exists('event_date', $data)) {
                    $attributes['event_date'] = $eventDate;
                }
                if ($previousSent) {
                    $attributes['previous_group_id'] = $previousGroup?->id;
                }
                if ($ownedImage !== null) {
                    $attributes['header_image_path'] = $ownedImage['path'];
                    $attributes['header_image_width'] = $ownedImage['width'];
                    $attributes['header_image_height'] = $ownedImage['height'];
                } elseif ($removeImage) {
                    $attributes['header_image_path'] = null;
                    $attributes['header_image_width'] = null;
                    $attributes['header_image_height'] = null;
                }

                $locked->fill($attributes);
                $changed = array_keys($locked->getDirty());
                $locked->save();

                $shared = array_intersect_key($attributes, array_flip([
                    'name', 'event_date', 'description', 'confirmation_message',
                    'header_image_path', 'header_image_width', 'header_image_height',
                ]));
                if ($isOpen !== null) {
                    $shared['is_open'] = $isOpen;
                }

                $formChanges = [];
                foreach ($kept as $form) {
                    $formAttributes = $shared;
                    if ($previousSent) {
                        $formAttributes['previous_edition_id'] = $previousByLocation[(int) $form->location_id] ?? null;
                    }
                    $form->fill($formAttributes);
                    $dirty = array_keys($form->getDirty());
                    if ($dirty !== []) {
                        $form->save();
                        $formChanges[] = ['form' => $form, 'changed' => $dirty];
                    }
                }

                $moved = [];
                foreach ($moving as $form) {
                    $from = $form->group_id !== null ? (int) $form->group_id : null;
                    $locationId = (int) $form->location_id;
                    $formAttributes = array_merge($shared, ['name' => $locked->name]);
                    if ($previousSent) {
                        $formAttributes['previous_edition_id'] = $previousByLocation[$locationId] ?? null;
                    } elseif (isset($previousByLocation[$locationId]) && $this->registrations->livePreviousEditionId($form) === null) {
                        $formDate = (string) ($formAttributes['event_date'] ?? $form->event_date->toDateString());
                        if ($this->editionProblem($form, (int) $user->company_id, $locationId, $formDate, $previousByLocation[$locationId], false) === null) {
                            $formAttributes['previous_edition_id'] = $previousByLocation[$locationId];
                        }
                    }
                    if (isset($moveLinks[$locationId]) && ($form->event === null || (int) $form->event->location_id !== $locationId)) {
                        $formAttributes['event_id'] = (int) $moveLinks[$locationId]->id;
                    }
                    $form->fill($formAttributes);
                    $form->group_id = $locked->id;
                    $form->save();
                    $moved[] = ['form' => $form, 'from' => $from];
                }

                $retired = $this->retireEmptiedGroups($locked, array_values(array_unique(array_filter(array_column($moved, 'from')))));

                foreach ($removed as $form) {
                    $form->delete();
                }

                $header = $locked->header_image_path === null ? null : [
                    'path' => $locked->header_image_path,
                    'width' => $locked->header_image_width,
                    'height' => $locked->header_image_height,
                ];
                $added = $this->insertForms($locked, $creating, $links, $dates, $previousByLocation, $header, $newFormsOpen, $user);

                return [
                    'group' => $locked,
                    'changed' => $changed,
                    'form_changes' => $formChanges,
                    'moved' => $moved,
                    'retired' => $retired,
                    'removed' => $removed,
                    'added' => $added,
                ];
            });
        } catch (QueryException $e) {
            RegistrationHeaderImage::delete($ownedImage['path'] ?? null);

            return $this->queryFailed($request, null, $e);
        } catch (\Throwable $e) {
            RegistrationHeaderImage::delete($ownedImage['path'] ?? null);

            throw $e;
        }

        foreach ($oldPaths as $path) {
            $this->deleteImageIfUnused($path);
        }

        $this->logUpdate($user, $result, $toAdd, $toRemove, $isOpen);

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $this->groupDetail($request, $result['group']->fresh()),
        ]));
    }

    public function destroy(Request $request, int $registrationEventGroup): JsonResponse
    {
        if (!RegistrationEventGroup::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $group = $this->accessibleGroup($request, $registrationEventGroup);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        if (!$this->canManageGroup($request, $group)) {
            return $this->notManager();
        }

        try {
            $outcome = DB::transaction(function () use ($request, $group) {
                $locked = RegistrationEventGroup::query()->whereKey($group->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return ['response' => response()->json(['success' => false, 'message' => 'That event could not be found.'], 404)];
                }

                $forms = RegistrationEvent::query()->where('group_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
                if ($request->has('expected_location_ids')
                    && !$this->sameLocations($request->input('expected_location_ids'), $forms->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values()->all())) {
                    return ['response' => $this->eventChanged()];
                }
                $allowed = $this->staffLocationIds($request);
                if ($forms->contains(fn (RegistrationEvent $form) => !in_array((int) $form->location_id, $allowed, true))) {
                    return ['response' => $this->notManager()];
                }

                if ($problem = $this->nextGroupProblem($request, $locked)) {
                    return ['response' => $problem];
                }

                $outside = RegistrationEvent::query()
                    ->whereIn('previous_edition_id', $forms->pluck('id')->all())
                    ->whereNotIn('id', $forms->pluck('id')->all())
                    ->orderBy('id')
                    ->first();
                if ($outside !== null) {
                    return ['response' => $this->linkedFormProblem($request, $outside, '"%s" links to one of these forms as last year\'s edition. Unlink it there first.')];
                }

                foreach ($forms as $form) {
                    $form->delete();
                }
                $locked->delete();

                return ['forms' => $forms];
            });
        } catch (QueryException $e) {
            return $this->queryFailed($request, null, $e);
        }

        if (isset($outcome['response'])) {
            return $outcome['response'];
        }
        $forms = $outcome['forms'];

        $locations = $forms->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values();
        ActivityLog::log(
            'registration_event_group_deleted',
            'event_registration',
            sprintf('Deleted the registration event "%s" and its %d location form%s', $group->name, $forms->count(), $forms->count() === 1 ? '' : 's'),
            $user->id,
            $locations->count() === 1 ? $locations->first() : null,
            'registration_event_group',
            (int) $group->id,
            ['resource_name' => $group->name, 'form_ids' => $forms->pluck('id')->all()]
        );

        foreach ($forms as $form) {
            ActivityLog::log(
                'registration_event_deleted',
                'event_registration',
                sprintf('Deleted the registration form "%s" with its event', $form->name),
                $user->id,
                (int) $form->location_id,
                'registration_event',
                (int) $form->id,
                ['resource_name' => $form->name, 'group_id' => (int) $group->id]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Event deleted.',
        ]);
    }

    public function export(Request $request, int $registrationEventGroup): JsonResponse
    {
        if (!RegistrationEventGroup::isAvailable()) {
            return $this->unavailable();
        }

        $user = $this->staffUser($request);
        $group = $this->accessibleGroup($request, $registrationEventGroup);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $forms = $this->visibleForms($request, $group);

        try {
            $export = $this->registrations->exportForms($forms);
        } catch (QueryException $e) {
            return $this->queryFailed($request, null, $e);
        }

        $perLocation = collect($export['rows'])->countBy('location_id');
        foreach ($forms as $form) {
            $rows = (int) ($perLocation[(int) $form->location_id] ?? 0);
            if ($rows === 0) {
                continue;
            }

            ActivityLog::log(
                'registration_entries_exported',
                'event_registration',
                sprintf('Exported %d registrations from "%s"', $rows, $form->name),
                $user->id,
                (int) $form->location_id,
                'registration_event',
                (int) $form->id,
                ['resource_name' => $form->name, 'rows' => $rows, 'capped' => $export['capped'], 'group_id' => (int) $group->id, 'source' => 'event_export']
            );
        }

        return $this->noStore(response()->json([
            'success' => true,
            'data' => $export + [
                'has_previous_group' => $forms->contains(fn (RegistrationEvent $form) => $this->registrations->livePreviousEditionId($form) !== null),
            ],
        ]));
    }

    private function scopedLocations(Request $request): array
    {
        $locationIds = $this->staffLocationIds($request);
        $requested = $request->input('location_id');

        if (is_scalar($requested) && $requested !== '') {
            $requested = filter_var($requested, FILTER_VALIDATE_INT);
            $locationIds = $requested !== false && in_array($requested, $locationIds, true) ? [$requested] : [];
        }

        return $locationIds;
    }

    private function accessibleGroup(Request $request, int $id): RegistrationEventGroup|JsonResponse
    {
        $user = $this->staffUser($request);

        $group = RegistrationEventGroup::query()
            ->with('previousGroup')
            ->where('company_id', $user->company_id)
            ->whereKey($id)
            ->first();

        $firstForm = $group === null ? null : RegistrationEvent::query()->where('group_id', $group->id)->orderBy('id')->first();

        if ($group === null || $firstForm === null) {
            return response()->json([
                'success' => false,
                'message' => 'That event could not be found.',
            ], 404);
        }

        $visible = RegistrationEvent::query()
            ->where('group_id', $group->id)
            ->whereIn('location_id', $this->staffLocationIds($request))
            ->exists();

        if (!$visible) {
            return $this->denyStaffElsewhere($firstForm->location_id, 'event') ?? response()->json([
                'success' => false,
                'message' => 'That event could not be found.',
            ], 404);
        }

        return $group;
    }

    private function visibleForms(Request $request, RegistrationEventGroup $group): Collection
    {
        return RegistrationEvent::query()
            ->with(['location', 'previousEdition', 'event', 'group'])
            ->where('group_id', $group->id)
            ->whereIn('location_id', $this->staffLocationIds($request))
            ->get()
            ->sortBy(fn (RegistrationEvent $form) => mb_strtolower((string) $form->location?->name))
            ->values();
    }

    private function groupDetail(Request $request, RegistrationEventGroup $group): array
    {
        $forms = $this->visibleForms($request, $group);
        $formIds = $forms->pluck('id')->map(fn ($id) => (int) $id)->all();
        $perForm = $this->registrations->listCounts($formIds);
        $nextEditions = $this->nextEditionsFor($formIds);
        $counts = $this->registrations->scopeCounts($formIds);
        $frames = $this->photoFramesFor($forms);
        $visibleLocations = $this->staffLocationIds($request);
        $next = RegistrationEventGroup::query()
            ->where('previous_group_id', $group->id)
            ->whereHas('forms', fn ($query) => $query->whereIn('location_id', $visibleLocations))
            ->orderBy('event_date')
            ->orderBy('id')
            ->first();
        $previous = $group->previousGroup !== null && $this->groupVisible($request, (int) $group->previousGroup->id)
            ? $group->previousGroup
            : null;
        $canManage = $this->canManageGroup($request, $group);

        return [
            'id' => (int) $group->id,
            'name' => $group->name,
            'event_date' => $group->event_date->toDateString(),
            'description' => $group->description,
            'confirmation_message' => $group->confirmation_message,
            'header_image_path' => $group->header_image_path,
            'header_image_width' => $group->header_image_width,
            'header_image_height' => $group->header_image_height,
            'status' => $this->groupStatus($forms),
            'can_manage' => $canManage,
            'location_forms_total' => RegistrationEvent::query()->where('group_id', $group->id)->count(),
            'previous_group' => $previous === null ? null : [
                'id' => (int) $previous->id,
                'name' => $previous->name,
                'event_date' => $previous->event_date->toDateString(),
            ],
            'next_group' => $next === null ? null : [
                'id' => (int) $next->id,
                'name' => $next->name,
                'event_date' => $next->event_date->toDateString(),
            ],
            'counts' => $counts + ['location_forms' => $forms->count()],
            'forms' => $forms->map(fn (RegistrationEvent $form) => array_merge(
                $this->summary($form, $perForm, $nextEditions),
                isset($frames[(int) $form->id]) ? ['photo_frames' => $frames[(int) $form->id]] : []
            ))->values()->all(),
            'merge_candidates' => $canManage ? $this->mergeCandidates($request, $group) : [],
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
        ];
    }

    private function mergeCandidates(Request $request, RegistrationEventGroup $group): array
    {
        $inEvent = RegistrationEvent::query()->where('group_id', $group->id)->pluck('location_id')->map(fn ($id) => (int) $id)->all();
        $locations = array_values(array_diff($this->staffLocationIds($request), $inEvent));
        if ($locations === []) {
            return [];
        }

        $key = RegistrationFormGrouping::nameKey($group->name);
        $date = $group->event_date;
        $forms = RegistrationEvent::query()
            ->with(['location', 'group'])
            ->where('company_id', $group->company_id)
            ->whereIn('location_id', $locations)
            ->where(fn ($query) => $query->whereNull('group_id')->orWhere('group_id', '<>', $group->id))
            ->whereBetween('event_date', [
                $date->copy()->subDays(self::MERGE_WINDOW_DAYS)->toDateString(),
                $date->copy()->addDays(self::MERGE_WINDOW_DAYS)->toDateString(),
            ])
            ->get()
            ->filter(fn (RegistrationEvent $form) => RegistrationFormGrouping::nameKey($form->name) === $key);

        if ($forms->isEmpty()) {
            return [];
        }

        $sourceIds = $forms->pluck('group_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $managed = $this->managedGroupIds($request, $sourceIds);
        $forms = $forms
            ->filter(fn (RegistrationEvent $form) => $form->group === null || in_array((int) $form->group_id, $managed, true))
            ->values();

        $counts = $this->registrations->listCounts($forms->pluck('id')->map(fn ($id) => (int) $id)->all());
        $sizes = $sourceIds === [] ? collect() : RegistrationEvent::query()
            ->whereIn('group_id', $sourceIds)
            ->groupBy('group_id')
            ->selectRaw('group_id, COUNT(*) AS total')
            ->pluck('total', 'group_id');

        return $forms
            ->sortBy(fn (RegistrationEvent $form) => [(int) abs($form->event_date->diffInDays($date)), (int) $form->id])
            ->map(fn (RegistrationEvent $form) => [
                'id' => (int) $form->id,
                'location_id' => (int) $form->location_id,
                'location_name' => $form->location?->name,
                'event_date' => $form->event_date->toDateString(),
                'status' => $form->status(),
                'total_entries' => (int) ($counts[(int) $form->id]['total_entries'] ?? 0),
                'group_id' => $form->group !== null ? (int) $form->group->id : null,
                'group_name' => $form->group?->name,
                'group_event_date' => $form->group?->event_date?->toDateString(),
                'group_location_forms' => $form->group !== null ? (int) ($sizes[$form->group_id] ?? 1) : 1,
            ])
            ->values()
            ->all();
    }

    private function formsToMove(Request $request, User $user, RegistrationEventGroup $group, array $data, array $toAdd, string $eventDate): Collection|JsonResponse
    {
        $ids = array_values(array_unique(array_map('intval', $data['move_form_ids'] ?? [])));
        if ($ids === []) {
            return collect();
        }

        $forms = RegistrationEvent::query()
            ->with(['location', 'group'])
            ->where('company_id', $user->company_id)
            ->whereIn('location_id', $this->staffLocationIds($request))
            ->whereKey($ids)
            ->get();

        if ($forms->count() !== count($ids)) {
            return $this->fieldError('move_form_ids', 'Choose forms from your locations to move into this event.');
        }

        $names = array_values(array_unique(array_filter([
            RegistrationFormGrouping::nameKey($group->name),
            array_key_exists('name', $data) ? RegistrationFormGrouping::nameKey($data['name']) : null,
        ])));
        $earliest = Carbon::parse($eventDate)->subDays(self::MERGE_WINDOW_DAYS)->toDateString();
        $latest = Carbon::parse($eventDate)->addDays(self::MERGE_WINDOW_DAYS)->toDateString();

        $byLocation = [];
        foreach ($forms as $form) {
            $locationId = (int) $form->location_id;
            $venue = $form->location?->name ?? 'That location';

            if ((int) $form->group_id === (int) $group->id) {
                return $this->fieldError('move_form_ids', sprintf("%s's form is already in this event.", $venue));
            }

            if (!in_array($locationId, $toAdd, true)) {
                return $this->fieldError('move_form_ids', sprintf('Tick %s under Locations to move its form into this event.', $venue));
            }

            if (isset($byLocation[$locationId])) {
                return $this->fieldError('move_form_ids', 'Move one form per location.');
            }

            if (!in_array(RegistrationFormGrouping::nameKey($form->name), $names, true)) {
                return $this->fieldError('move_form_ids', sprintf('%s\'s form is called "%s". Only a form with this event\'s name can be moved into it.', $venue, $form->name));
            }

            $formDate = $form->event_date->toDateString();
            if ($formDate < $earliest || $formDate > $latest) {
                return $this->fieldError('move_form_ids', sprintf("%s's form is dated too far from this event to be part of it.", $venue));
            }

            if ($form->group !== null && !$this->canManageGroup($request, $form->group)) {
                return $this->fieldError('move_form_ids', sprintf("%s's form belongs to an event that runs at other locations too. Only a company admin can move it.", $venue));
            }

            $byLocation[$locationId] = $form;
        }

        return collect($byLocation);
    }

    private function lockMovingForms(Collection $moves, array $liveLocations): Collection
    {
        return $moves->map(function (RegistrationEvent $planned) use ($liveLocations) {
            $form = RegistrationEvent::query()->with('location')->whereKey($planned->id)->lockForUpdate()->first();

            if ($form === null || (int) $form->group_id !== (int) $planned->group_id || in_array((int) $form->location_id, $liveLocations, true)) {
                abort(409, 'Something changed while you were saving. Reload the page and try again.');
            }

            return $form;
        })->values();
    }

    private function retireEmptiedGroups(RegistrationEventGroup $target, array $sourceIds): array
    {
        $retired = [];

        foreach ($sourceIds as $sourceId) {
            $source = RegistrationEventGroup::query()->whereKey($sourceId)->lockForUpdate()->first();
            if ($source === null || RegistrationEvent::query()->where('group_id', $source->id)->lockForUpdate()->pluck('id')->isNotEmpty()) {
                continue;
            }

            RegistrationEventGroup::query()
                ->where('previous_group_id', $source->id)
                ->whereKeyNot($target->id)
                ->where('event_date', '>', $target->event_date->toDateString())
                ->update(['previous_group_id' => $target->id]);

            if ((int) $target->previous_group_id === (int) $source->id) {
                $target->previous_group_id = null;
                $target->save();
            }

            $source->delete();
            $retired[] = $source;
        }

        return $retired;
    }

    private function lockEvents(int $targetId, array $sourceIds): RegistrationEventGroup
    {
        $ids = array_values(array_unique(array_merge([$targetId], $sourceIds)));
        sort($ids);

        $target = null;
        foreach ($ids as $id) {
            $row = ($id === $targetId ? RegistrationEventGroup::query() : RegistrationEventGroup::withTrashed())
                ->whereKey($id)
                ->lockForUpdate()
                ->first();
            if ($id === $targetId) {
                $target = $row;
            }
        }

        if ($target === null) {
            abort(409, 'Something changed while you were saving. Reload the page and try again.');
        }

        return $target;
    }

    private function sameLocations(mixed $expected, array $current): bool
    {
        $expected = array_values(array_unique(array_map('intval', is_array($expected) ? $expected : [])));
        sort($expected);
        sort($current);

        return $expected === $current;
    }

    private function eventChanged(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'EVENT_CHANGED',
            'message' => "This event's locations changed since you opened it. Reload the page and try again.",
        ], 409);
    }

    private function orderProblem(User $user, Collection $staying, array $newLocations, array $dates, bool $dateSent, string $eventDate, bool $previousSent, array $previousByLocation): ?JsonResponse
    {
        foreach ($staying as $form) {
            $locationId = (int) $form->location_id;
            $date = $dateSent ? $eventDate : $form->event_date->toDateString();
            $previousId = $previousSent
                ? ($previousByLocation[$locationId] ?? null)
                : $this->registrations->livePreviousEditionId($form);

            if ($this->editionProblem($form, (int) $user->company_id, $locationId, $date, $previousId, false) !== null) {
                return $this->orderError($form->location?->name, $dateSent);
            }
        }

        $names = $newLocations === [] ? collect() : Location::query()->whereKey($newLocations)->pluck('name', 'id');
        foreach ($newLocations as $locationId) {
            $previousId = $previousByLocation[$locationId] ?? null;
            if ($previousId === null) {
                continue;
            }

            if ($this->editionProblem(null, (int) $user->company_id, $locationId, $dates[$locationId] ?? $eventDate, $previousId, false) !== null) {
                return $this->orderError($names[$locationId] ?? null, $dateSent);
            }
        }

        return null;
    }

    private function orderError(?string $venue, bool $dateSent): JsonResponse
    {
        $venue = $venue ?? 'one location';

        return $dateSent
            ? $this->fieldError('event_date', sprintf("That date puts %s's form out of order with its last year's or next year's form.", $venue))
            : $this->fieldError('previous_group_id', sprintf("Last year's event has to come before this one at every location, and at %s it doesn't.", $venue));
    }

    private function logUpdate(User $user, array $result, array $toAdd, array $toRemove, ?bool $isOpen): void
    {
        $saved = $result['group'];

        if ($result['changed'] !== [] || $result['added'] !== [] || $result['removed']->isNotEmpty() || $result['moved'] !== [] || $isOpen !== null) {
            $locations = RegistrationEvent::query()->where('group_id', $saved->id)->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values();
            ActivityLog::log(
                'registration_event_group_updated',
                'event_registration',
                sprintf('Updated the registration event "%s"', $saved->name),
                $user->id,
                $locations->count() === 1 ? $locations->first() : null,
                'registration_event_group',
                (int) $saved->id,
                [
                    'resource_name' => $saved->name,
                    'changed' => array_values(array_diff($result['changed'], ['header_image_width', 'header_image_height'])),
                    'added_location_ids' => $toAdd,
                    'removed_location_ids' => $toRemove,
                    'moved_form_ids' => array_map(fn (array $move) => (int) $move['form']->id, $result['moved']),
                ]
            );
        }

        foreach ($result['form_changes'] as $change) {
            $form = $change['form'];
            ActivityLog::log(
                'registration_event_updated',
                'event_registration',
                sprintf('Updated the registration form "%s" from its event', $form->name),
                $user->id,
                (int) $form->location_id,
                'registration_event',
                (int) $form->id,
                [
                    'resource_name' => $form->name,
                    'changed' => array_values(array_diff($change['changed'], ['header_image_width', 'header_image_height'])),
                    'group_id' => (int) $saved->id,
                ]
            );
        }

        foreach ($result['moved'] as $move) {
            $form = $move['form'];
            ActivityLog::log(
                'registration_event_moved',
                'event_registration',
                sprintf('Moved the registration form "%s" into the event "%s"', $form->name, $saved->name),
                $user->id,
                (int) $form->location_id,
                'registration_event',
                (int) $form->id,
                ['resource_name' => $form->name, 'from_group_id' => $move['from'], 'group_id' => (int) $saved->id]
            );
        }

        foreach ($result['retired'] as $retired) {
            $retiredLocations = collect($result['moved'])
                ->filter(fn (array $move) => $move['from'] === (int) $retired->id)
                ->map(fn (array $move) => (int) $move['form']->location_id)
                ->unique()
                ->values();
            ActivityLog::log(
                'registration_event_group_merged',
                'event_registration',
                sprintf('Merged the registration event "%s" into "%s"', $retired->name, $saved->name),
                $user->id,
                $retiredLocations->count() === 1 ? $retiredLocations->first() : null,
                'registration_event_group',
                (int) $retired->id,
                ['resource_name' => $retired->name, 'group_id' => (int) $saved->id]
            );
        }

        foreach ($result['removed'] as $form) {
            ActivityLog::log(
                'registration_event_deleted',
                'event_registration',
                sprintf('Removed the registration form "%s" from its event', $form->name),
                $user->id,
                (int) $form->location_id,
                'registration_event',
                (int) $form->id,
                ['resource_name' => $form->name, 'group_id' => (int) $saved->id]
            );
        }

        $this->logFormsCreated($result['added'], $user);
    }

    private function groupStatus(Collection $forms): string
    {
        $statuses = $forms->map(fn (RegistrationEvent $form) => $form->status());

        if ($statuses->contains(RegistrationEvent::STATUS_OPEN)) {
            return RegistrationEvent::STATUS_OPEN;
        }

        if ($statuses->isNotEmpty() && $statuses->every(fn (string $status) => $status === RegistrationEvent::STATUS_PAST)) {
            return RegistrationEvent::STATUS_PAST;
        }

        return RegistrationEvent::STATUS_CLOSED;
    }

    private function removalProblem(Request $request, Collection $removeForms, bool $confirmed): ?JsonResponse
    {
        if ($removeForms->isEmpty()) {
            return null;
        }

        $outside = RegistrationEvent::query()
            ->whereIn('previous_edition_id', $removeForms->pluck('id')->all())
            ->orderBy('id')
            ->first();
        if ($outside !== null) {
            return $this->linkedFormProblem($request, $outside, '"%s" links to a form you are removing as last year\'s edition. Unlink it there first.');
        }

        if ($confirmed) {
            return null;
        }

        $entryCounts = RegistrationEntry::query()
            ->whereIn('registration_event_id', $removeForms->pluck('id')->all())
            ->groupBy('registration_event_id')
            ->selectRaw('registration_event_id, COUNT(*) AS total')
            ->pluck('total', 'registration_event_id');

        $framed = PhotoOverlay::supportsEvents()
            ? PhotoOverlay::query()
                ->whereIn('registration_event_id', $removeForms->pluck('id')->all())
                ->pluck('registration_event_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all()
            : [];

        $needsConfirmation = $removeForms->filter(fn (RegistrationEvent $form) => (int) ($entryCounts[$form->id] ?? 0) > 0
            || in_array((int) $form->id, $framed, true));
        if ($needsConfirmation->isEmpty()) {
            return null;
        }

        $names = $needsConfirmation
            ->map(function (RegistrationEvent $form) use ($entryCounts, $framed) {
                $count = (int) ($entryCounts[$form->id] ?? 0);
                $details = array_values(array_filter([
                    $count > 0 ? sprintf('%d registration%s', $count, $count === 1 ? '' : 's') : null,
                    in_array((int) $form->id, $framed, true) ? 'a photo frame' : null,
                ]));

                return sprintf('%s (%s)', $form->location?->name ?? 'A location', implode(', ', $details));
            })
            ->join(', ');

        $frameNote = $needsConfirmation->contains(fn (RegistrationEvent $form) => in_array((int) $form->id, $framed, true))
            ? ' Their photo frames stop being used.'
            : '';

        return response()->json([
            'success' => false,
            'code' => 'REMOVAL_NEEDS_CONFIRMATION',
            'message' => "Removing these locations deletes their forms, QR codes and registration lists: {$names}.{$frameNote}",
        ], 409);
    }

    private function notManager(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This event runs at other locations too. Only a company admin can change it.',
        ], 403);
    }
}
