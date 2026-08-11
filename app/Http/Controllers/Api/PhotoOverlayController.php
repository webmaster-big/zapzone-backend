<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\PhotoOverlay;
use App\Services\PhotoProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            ->with('creator')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

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
        ]);

        if ($denied = $this->guardLocationAccess($request, $validated['location_id'])) {
            return $denied;
        }

        $location = Location::findOrFail($validated['location_id']);

        if ($denied = $this->guardCompanyAccess($request, $location->company_id)) {
            return $denied;
        }

        $path = $request->file('image')->store('photo-overlays/' . $location->id, 'public');

        $overlay = PhotoOverlay::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'image_path' => $path,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'priority' => $validated['priority'] ?? 0,
            'created_by' => $this->resolveAuthUser($request)?->id,
        ]);

        $this->flagConflicts($location);

        ActivityLog::log(
            'photo_overlay_created',
            'photos',
            sprintf('Uploaded the photo overlay "%s"', $overlay->name),
            $overlay->created_by,
            $location->id,
            'photo_overlay',
            $overlay->id
        );

        return response()->json([
            'success' => true,
            'data' => $this->present($overlay, $this->processor->resolveOverlay($location)?->id),
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
        ]);

        $changes = [];
        foreach (['name', 'starts_at', 'ends_at', 'is_enabled', 'priority'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $validated[$field] ?? null;
            }
        }

        if ($request->boolean('clear_schedule')) {
            $changes['starts_at'] = null;
            $changes['ends_at'] = null;
        }

        if ($request->hasFile('image')) {
            $photoOverlay->deleteMedia();
            $changes['image_path'] = $request->file('image')
                ->store('photo-overlays/' . $photoOverlay->location_id, 'public');
        }

        $starts = $changes['starts_at'] ?? $photoOverlay->starts_at;
        $ends = $changes['ends_at'] ?? $photoOverlay->ends_at;

        if ($starts && $ends && strtotime((string) $ends) <= strtotime((string) $starts)) {
            return response()->json([
                'success' => false,
                'message' => 'The end of the schedule must come after its start.',
            ], 422);
        }

        $photoOverlay->update($changes);
        $photoOverlay->loadMissing('location');
        $this->flagConflicts($photoOverlay->location);

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

        return response()->json([
            'success' => true,
            'data' => $this->present(
                $photoOverlay->fresh(),
                $this->processor->resolveOverlay($photoOverlay->location)?->id
            ),
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

    protected function present(PhotoOverlay $overlay, ?int $activeId): array
    {
        return [
            'id' => $overlay->id,
            'location_id' => $overlay->location_id,
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
