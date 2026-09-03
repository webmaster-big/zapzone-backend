<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Waiver;
use App\Models\WaiverTemplate;
use App\Models\WaiverTemplateAd;
use App\Services\WaiverAdService;
use App\Support\DataUriImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Traits\GuardsWrites;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WaiverAdController extends Controller
{
    use GuardsWrites;
    use ScopesByAuthUser;

    public function index(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found.'], 404);
        }
        if ($denied = $this->guardCompanyAccess($request, $template->company_id)) {
            return $denied;
        }

        $ads = WaiverTemplateAd::with('location:id,name')
            ->where('waiver_template_id', $template->id)
            ->orderBy('is_fallback')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => [
                    'ads_enabled' => (bool) $template->ads_enabled,
                    'ads_rotation_mode' => $template->ads_rotation_mode,
                    'ads_display_seconds' => (int) $template->ads_display_seconds,
                ],
                'ads' => $ads->map(fn (WaiverTemplateAd $ad) => $this->present($ad))->values(),
            ],
        ]);
    }

    public function store(Request $request, int $templateId): JsonResponse
    {
        return $this->guardWrite('waiver ad upload', ['template_id' => $templateId], fn () => $this->storeAd($request, $templateId));
    }

    private function storeAd(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found.'], 404);
        }
        if ($denied = $this->guardCompanyAccess($request, $template->company_id)) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'destination_url' => ['nullable', 'url', 'max:2048'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_fallback' => ['nullable', 'boolean'],
        ]);

        $authUser = $this->resolveAuthUser($request);
        if ($this->isLocationBound($authUser)) {
            $validated['location_id'] = $authUser->location_id;
        }

        if (!empty($validated['location_id'])) {
            if ($denied = $this->guardLocationAccess($request, $validated['location_id'])) {
                return $denied;
            }
            $location = \App\Models\Location::find($validated['location_id']);
            if (!$location || (int) $location->company_id !== (int) $template->company_id) {
                return response()->json(['success' => false, 'message' => 'That location does not belong to this company.'], 422);
            }
        }

        $isFallback = (bool) ($validated['is_fallback'] ?? false);
        if ($isFallback && $this->fallbackExists($template->id)) {
            return response()->json(['success' => false, 'message' => 'This template already has a fallback ad. Remove it first or edit the existing one.'], 422);
        }

        $ad = WaiverTemplateAd::create([
            'company_id' => $template->company_id,
            'waiver_template_id' => $template->id,
            'location_id' => $validated['location_id'] ?? null,
            'name' => $validated['name'] ?? null,
            'image_path' => $this->storeImage($request, $template->id),
            'destination_url' => $validated['destination_url'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'is_fallback' => $isFallback,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'position' => (int) WaiverTemplateAd::where('waiver_template_id', $template->id)->max('position') + 1,
            'created_by' => $this->resolveAuthUser($request)?->id,
        ]);

        ActivityLog::log(
            'waiver_ad_created',
            'waivers',
            sprintf('Added the post-waiver ad "%s"', $ad->name ?: ('#' . $ad->id)),
            $ad->created_by,
            $ad->location_id,
            'waiver_template_ad',
            $ad->id
        );

        $ad->load('location:id,name');

        return response()->json(['success' => true, 'data' => $this->present($ad)], 201);
    }

    public function update(Request $request, WaiverTemplateAd $waiverAd): JsonResponse
    {
        return $this->guardWrite('waiver ad update', ['ad_id' => $waiverAd->id], fn () => $this->updateAd($request, $waiverAd));
    }

    private function updateAd(Request $request, WaiverTemplateAd $waiverAd): JsonResponse
    {
        if (!$this->authorizeRecordScope($waiverAd) || ($denied = $this->guardCompanyWideAd($request, $waiverAd))) {
            return $denied ?? response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'destination_url' => ['nullable', 'url', 'max:2048'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_fallback' => ['nullable', 'boolean'],
            'clear_schedule' => ['nullable', 'boolean'],
            'clear_link' => ['nullable', 'boolean'],
        ]);

        $changes = [];
        foreach (['name', 'destination_url', 'starts_at', 'ends_at'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $validated[$field] ?? null;
            }
        }
        foreach (['is_enabled', 'is_fallback'] as $field) {
            if ($request->has($field) && $validated[$field] !== null) {
                $changes[$field] = $validated[$field];
            }
        }

        if ($request->has('location_id')) {
            $locationId = $validated['location_id'] ?? null;
            if ($locationId === null && $this->isLocationBound($this->resolveAuthUser($request))) {
                return response()->json(['success' => false, 'message' => 'Only a company admin can make an ad apply to every location.'], 403);
            }
            if ($locationId) {
                if ($denied = $this->guardLocationAccess($request, $locationId)) {
                    return $denied;
                }
                $location = \App\Models\Location::find($locationId);
                if (!$location || (int) $location->company_id !== (int) $waiverAd->company_id) {
                    return response()->json(['success' => false, 'message' => 'That location does not belong to this company.'], 422);
                }
            }
            $changes['location_id'] = $locationId;
        }

        if ($request->boolean('clear_schedule')) {
            $changes['starts_at'] = null;
            $changes['ends_at'] = null;
        }

        if ($request->boolean('clear_link')) {
            $changes['destination_url'] = null;
        }

        if (($changes['is_fallback'] ?? false) && !$waiverAd->is_fallback && $this->fallbackExists($waiverAd->waiver_template_id, $waiverAd->id)) {
            return response()->json(['success' => false, 'message' => 'This template already has a fallback ad.'], 422);
        }

        $starts = array_key_exists('starts_at', $changes) ? $changes['starts_at'] : $waiverAd->starts_at;
        $ends = array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $waiverAd->ends_at;
        if ($starts && $ends && strtotime((string) $ends) <= strtotime((string) $starts)) {
            return response()->json(['success' => false, 'message' => 'The end of the schedule must come after its start.'], 422);
        }

        $oldImagePath = null;
        if ($request->hasFile('image')) {
            $oldImagePath = $waiverAd->image_path;
            $changes['image_path'] = $this->storeImage($request, $waiverAd->waiver_template_id);
        }

        $waiverAd->update($changes);

        if ($oldImagePath && $oldImagePath !== $waiverAd->image_path && Storage::disk('public')->exists($oldImagePath)) {
            Storage::disk('public')->delete($oldImagePath);
        }

        ActivityLog::log(
            'waiver_ad_updated',
            'waivers',
            sprintf('Updated the post-waiver ad "%s"', $waiverAd->name ?: ('#' . $waiverAd->id)),
            $this->resolveAuthUser($request)?->id,
            $waiverAd->location_id,
            'waiver_template_ad',
            $waiverAd->id,
            $changes
        );

        return response()->json(['success' => true, 'data' => $this->present($waiverAd->fresh(['location:id,name']))]);
    }

    public function destroy(Request $request, WaiverTemplateAd $waiverAd): JsonResponse
    {
        return $this->guardWrite('waiver ad delete', ['ad_id' => $waiverAd->id], fn () => $this->destroyAd($request, $waiverAd));
    }

    private function destroyAd(Request $request, WaiverTemplateAd $waiverAd): JsonResponse
    {
        if (!$this->authorizeRecordScope($waiverAd) || ($denied = $this->guardCompanyWideAd($request, $waiverAd))) {
            return $denied ?? response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $name = $waiverAd->name ?: ('#' . $waiverAd->id);
        $locationId = $waiverAd->location_id;
        $id = $waiverAd->id;

        $waiverAd->deleteMedia();
        $waiverAd->delete();

        ActivityLog::log(
            'waiver_ad_deleted',
            'waivers',
            sprintf('Deleted the post-waiver ad "%s"', $name),
            $this->resolveAuthUser($request)?->id,
            $locationId,
            'waiver_template_ad',
            $id
        );

        return response()->json(['success' => true, 'message' => 'Ad deleted.']);
    }

    public function reorder(Request $request, int $templateId): JsonResponse
    {
        return $this->guardWrite('waiver ad reorder', ['template_id' => $templateId], fn () => $this->reorderAds($request, $templateId));
    }

    private function reorderAds(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found.'], 404);
        }
        if ($denied = $this->guardCompanyAccess($request, $template->company_id)) {
            return $denied;
        }
        if ($this->isLocationBound($this->resolveAuthUser($request))) {
            return response()->json(['success' => false, 'message' => 'Only a company admin can reorder the rotation across locations.'], 403);
        }

        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ]);

        $ads = WaiverTemplateAd::where('waiver_template_id', $template->id)
            ->whereIn('id', $validated['ordered_ids'])
            ->get()
            ->keyBy('id');

        foreach (array_values($validated['ordered_ids']) as $index => $adId) {
            $ads->get($adId)?->update(['position' => $index]);
        }

        return response()->json(['success' => true, 'message' => 'Order saved.']);
    }

    public function updateSettings(Request $request, int $templateId): JsonResponse
    {
        return $this->guardWrite('waiver ad settings update', ['template_id' => $templateId], fn () => $this->updateAdSettings($request, $templateId));
    }

    private function updateAdSettings(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Template not found.'], 404);
        }
        if ($denied = $this->guardCompanyAccess($request, $template->company_id)) {
            return $denied;
        }

        $validated = $request->validate([
            'ads_enabled' => ['nullable', 'boolean'],
            'ads_rotation_mode' => ['nullable', 'in:random,ordered'],
            'ads_display_seconds' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $changes = [];
        foreach (['ads_enabled', 'ads_rotation_mode', 'ads_display_seconds'] as $field) {
            if ($request->has($field) && $validated[$field] !== null) {
                $changes[$field] = $validated[$field];
            }
        }

        if ($changes) {
            $before = $template->only(array_keys($changes));
            $template->update($changes);

            ActivityLog::log(
                'waiver_ad_settings_updated',
                'waivers',
                sprintf('Changed the post-waiver ad settings on "%s"', $template->title),
                $this->resolveAuthUser($request)?->id,
                $template->location_id,
                'waiver_template',
                $template->id,
                ['before' => $before, 'after' => $changes]
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'ads_enabled' => (bool) $template->ads_enabled,
                'ads_rotation_mode' => $template->ads_rotation_mode,
                'ads_display_seconds' => (int) $template->ads_display_seconds,
            ],
        ]);
    }

    public function learnMore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'waiver_id' => ['required', 'integer'],
            'ad_id' => ['required', 'integer'],
            'channel' => ['required', 'in:email,sms'],
        ]);

        $waiver = Waiver::where('id', $validated['waiver_id'])
            ->where('status', Waiver::STATUS_COMPLETED)
            ->first();
        $ad = WaiverTemplateAd::find($validated['ad_id']);

        if (!$waiver || !$ad
            || (int) $ad->waiver_template_id !== (int) $waiver->waiver_template_id
            || (int) $ad->company_id !== (int) $waiver->company_id) {
            return response()->json(['success' => false, 'message' => 'This request could not be matched to a completed waiver.'], 404);
        }

        if (empty($ad->destination_url)) {
            return response()->json(['success' => false, 'message' => 'This ad has no additional information to send.'], 422);
        }

        if ($waiver->submitted_at && $waiver->submitted_at->lt(now()->subHours(2))) {
            return response()->json(['success' => false, 'message' => 'This request has expired.'], 410);
        }

        $wasDisplayed = \App\Models\WaiverAdEvent::where('waiver_id', $waiver->id)
            ->where('waiver_template_ad_id', $ad->id)
            ->where('event', 'displayed')
            ->exists();
        if (!$wasDisplayed) {
            return response()->json(['success' => false, 'message' => 'This request could not be matched to a completed waiver.'], 404);
        }

        try {
            $result = app(WaiverAdService::class)->learnMore($waiver, $ad, $validated['channel']);
        } catch (\Throwable $e) {
            Log::error('Waiver ad Learn More could not be processed', [
                'waiver_id' => $waiver->id,
                'ad_id' => $ad->id,
                'channel' => $validated['channel'],
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not send that just now. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
        ], $result['status']);
    }

    protected function isLocationBound(?\App\Models\User $user): bool
    {
        return $user !== null
            && in_array($user->role, ['location_manager', 'attendant'], true)
            && (bool) $user->location_id;
    }

    protected function guardCompanyWideAd(Request $request, WaiverTemplateAd $ad): ?JsonResponse
    {
        if ($ad->location_id === null && $this->isLocationBound($this->resolveAuthUser($request))) {
            return response()->json(['success' => false, 'message' => 'This ad applies to every location, so only a company admin can change it.'], 403);
        }

        return null;
    }

    protected function fallbackExists(int $templateId, ?int $exceptId = null): bool
    {
        return WaiverTemplateAd::where('waiver_template_id', $templateId)
            ->where('is_fallback', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    protected function storeImage(Request $request, int $templateId): string
    {
        $file = $request->file('image');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (!in_array($extension, ['png', 'jpg', 'webp'], true)) {
            $extension = 'png';
        }

        $binary = DataUriImage::downscale($file->get(), $extension);
        $path = 'waiver-ads/' . $templateId . '/' . uniqid() . '.' . $extension;
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    protected function present(WaiverTemplateAd $ad): array
    {
        return [
            'id' => $ad->id,
            'waiver_template_id' => $ad->waiver_template_id,
            'location_id' => $ad->location_id,
            'location_name' => $ad->relationLoaded('location') ? $ad->location?->name : null,
            'name' => $ad->name,
            'image_path' => $ad->image_path,
            'destination_url' => $ad->destination_url,
            'is_enabled' => $ad->is_enabled,
            'is_fallback' => $ad->is_fallback,
            'starts_at' => $ad->starts_at?->toIso8601String(),
            'ends_at' => $ad->ends_at?->toIso8601String(),
            'position' => $ad->position,
            'status' => $ad->status(),
            'created_at' => $ad->created_at?->toIso8601String(),
        ];
    }
}
