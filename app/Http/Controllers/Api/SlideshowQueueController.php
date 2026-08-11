<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\PresentsPhotos;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\SlideshowQueue;
use App\Support\OperatingDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SlideshowQueueController extends Controller
{
    use ScopesByAuthUser, PresentsPhotos;

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

        $setting = LocationPhotoSetting::forLocation($location);
        $active = SlideshowQueue::activeFor($location);
        $past = SlideshowQueue::where('location_id', $location->id)
            ->where('id', '!=', $active->id)
            ->orderByDesc('operating_day')
            ->limit(30)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'active' => $this->presentQueue($active, $location, true),
                'past' => $past->map(fn ($queue) => $this->presentQueue($queue, $location, false))->values(),
                'settings' => [
                    'slideshow_enabled' => $setting->slideshow_enabled,
                    'slideshow_duration_seconds' => $setting->slideshow_duration_seconds,
                    'slideshow_url' => $setting->slideshowUrl(),
                    'slideshow_passcode' => (string) $setting->slideshow_passcode,
                    'durations' => LocationPhotoSetting::SLIDESHOW_DURATIONS,
                    'last_seen_at' => $setting->slideshow_seen_at?->toIso8601String(),
                    'display_online' => $setting->slideshow_seen_at !== null
                        && $setting->slideshow_seen_at->greaterThan(now()->subMinutes(3)),
                ],
                'operating_day' => OperatingDay::forLocation($location),
                'local_time' => OperatingDay::localNow($location)->toIso8601String(),
                'cutoff_hour' => OperatingDay::CUTOFF_HOUR,
            ],
        ]);
    }

    public function show(Request $request, SlideshowQueue $slideshowQueue): JsonResponse
    {
        if (!$this->authorizeRecordScope($slideshowQueue)) {
            return $this->forbidden();
        }

        $slideshowQueue->loadMissing('location');

        return response()->json([
            'success' => true,
            'data' => $this->presentQueue($slideshowQueue, $slideshowQueue->location, true),
        ]);
    }

    public function updatePhoto(Request $request, Photo $photo): JsonResponse
    {
        if (!$this->authorizeRecordScope($photo)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'slideshow_state' => ['nullable', Rule::in([Photo::SLIDESHOW_VISIBLE, Photo::SLIDESHOW_HIDDEN, Photo::SLIDESHOW_REMOVED])],
            'slideshow_priority' => ['nullable', 'integer', 'min:-100', 'max:100'],
        ]);

        if (!array_key_exists('slideshow_state', $validated) && !array_key_exists('slideshow_priority', $validated)) {
            return response()->json(['success' => false, 'message' => 'There are no changes to save.'], 422);
        }

        $changes = [];
        if (!empty($validated['slideshow_state'])) {
            $changes['slideshow_state'] = $validated['slideshow_state'];
        }
        if (array_key_exists('slideshow_priority', $validated) && $validated['slideshow_priority'] !== null) {
            $changes['slideshow_priority'] = $validated['slideshow_priority'];
        }

        $photo->update($changes);

        ActivityLog::log(
            'slideshow_photo_updated',
            'photos',
            sprintf(
                'Set photo #%d to %s in the slideshow queue',
                $photo->id,
                $changes['slideshow_state'] ?? 'a new priority'
            ),
            $this->resolveAuthUser($request)?->id,
            $photo->location_id,
            'photo',
            $photo->id,
            $changes
        );

        return response()->json([
            'success' => true,
            'data' => $this->presentPhoto($photo->fresh()),
        ]);
    }

    /**
     * Put any photo on the venue screen, or take it off again.
     *
     * Kiosk photos opt themselves in, but a staff photo had no route onto the slideshow at
     * all. Including a photo places it in TODAY's active queue regardless of the day it was
     * taken, because the point of the action is "show this now".
     */
    public function setInclusion(Request $request, Photo $photo): JsonResponse
    {
        if (!$this->authorizeRecordScope($photo)) {
            return $this->forbidden();
        }

        $validated = $request->validate(['include' => ['required', 'boolean']]);
        $include = (bool) $validated['include'];

        if ($include && !$photo->isReady()) {
            return response()->json([
                'success' => false,
                'message' => 'This photo is not ready to show yet.',
            ], 422);
        }

        $photo->loadMissing('location');

        if ($include) {
            $queue = SlideshowQueue::activeFor($photo->location);

            $photo->update([
                'slideshow_eligible' => true,
                'slideshow_state' => Photo::SLIDESHOW_VISIBLE,
                'slideshow_queue_id' => $queue->id,
            ]);
        } else {
            $photo->update([
                'slideshow_eligible' => false,
                'slideshow_state' => Photo::SLIDESHOW_REMOVED,
                'slideshow_queue_id' => null,
            ]);
        }

        ActivityLog::log(
            $include ? 'slideshow_photo_added' : 'slideshow_photo_withdrawn',
            'photos',
            sprintf(
                '%s photo #%d %s the venue slideshow',
                $include ? 'Added' : 'Removed',
                $photo->id,
                $include ? 'to' : 'from'
            ),
            $this->resolveAuthUser($request)?->id,
            $photo->location_id,
            'photo',
            $photo->id
        );

        return response()->json([
            'success' => true,
            'message' => $include
                ? 'Added to the venue slideshow. It appears on the screen within a few seconds.'
                : 'Removed from the venue slideshow.',
            'data' => $this->presentPhoto($photo->fresh()),
        ]);
    }

    public function reorder(Request $request, SlideshowQueue $slideshowQueue): JsonResponse
    {
        if (!$this->authorizeRecordScope($slideshowQueue)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $ids = $slideshowQueue->photos()->pluck('id')->all();
        $total = count($validated['order']);

        foreach ($validated['order'] as $index => $photoId) {
            if (!in_array((int) $photoId, $ids, true)) {
                continue;
            }
            Photo::where('id', $photoId)->update(['slideshow_priority' => $total - $index]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentQueue($slideshowQueue->fresh(), $slideshowQueue->location, true),
        ]);
    }

    public function setPaused(Request $request, SlideshowQueue $slideshowQueue): JsonResponse
    {
        if (!$this->authorizeRecordScope($slideshowQueue)) {
            return $this->forbidden();
        }

        $validated = $request->validate(['is_paused' => ['required', 'boolean']]);
        $slideshowQueue->update(['is_paused' => $validated['is_paused']]);

        ActivityLog::log(
            $validated['is_paused'] ? 'slideshow_paused' : 'slideshow_resumed',
            'photos',
            sprintf('%s the slideshow for %s', $validated['is_paused'] ? 'Paused' : 'Resumed', $slideshowQueue->operating_day?->toDateString()),
            $this->resolveAuthUser($request)?->id,
            $slideshowQueue->location_id,
            'slideshow_queue',
            $slideshowQueue->id
        );

        return response()->json([
            'success' => true,
            'data' => $this->presentQueue($slideshowQueue->fresh(), $slideshowQueue->location, true),
        ]);
    }

    protected function presentQueue(SlideshowQueue $queue, ?Location $location, bool $withPhotos): array
    {
        $photos = $withPhotos
            ? $queue->photos()
                ->with('session')
                ->where('processing_status', Photo::PROCESSING_READY)
                ->whereNull('purged_at')
                ->orderByDesc('slideshow_priority')
                ->orderBy('captured_at')
                ->get()
            : collect();

        return [
            'id' => $queue->id,
            'operating_day' => $queue->operating_day?->toDateString(),
            'label' => $queue->operating_day ? OperatingDay::label($location, $queue->operating_day->toDateString()) : null,
            'status' => $queue->status,
            'is_paused' => $queue->is_paused,
            'opened_at' => $queue->opened_at?->toIso8601String(),
            'closed_at' => $queue->closed_at?->toIso8601String(),
            'closes_at' => $queue->operating_day
                ? OperatingDay::cutoffAfter($location, $queue->operating_day->toDateString())->toIso8601String()
                : null,
            'total_photos' => $queue->photos()->whereNull('purged_at')->count(),
            'visible_photos' => $queue->visiblePhotos()->count(),
            'photos' => $photos->map(fn (Photo $photo) => array_merge($this->presentPhoto($photo), [
                'session_source' => $photo->session?->source,
            ]))->values(),
        ];
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have access to that slideshow.',
        ], 403);
    }
}
