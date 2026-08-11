<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\PhotoMessageTemplate;
use App\Models\PhotoSession;
use App\Services\PhotoDeliveryService;
use App\Support\OperatingDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PhotoSettingController extends Controller
{
    use ScopesByAuthUser;

    public function show(Request $request): JsonResponse
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

        return response()->json([
            'success' => true,
            'data' => [
                'setting' => $setting->toAdminArray(),
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'timezone' => OperatingDay::timezoneFor($location),
                    'timezone_stored' => $location->timezone,
                ],
                'locked' => [
                    'qr_valid_hours' => PhotoSession::QR_VALID_HOURS,
                    'access_valid_days' => PhotoSession::ACCESS_VALID_DAYS,
                    'staff_max_photos' => PhotoSession::STAFF_MAX_PHOTOS,
                    'kiosk_max_photos' => PhotoSession::KIOSK_MAX_PHOTOS,
                    'kiosk_idle_seconds' => PhotoSession::KIOSK_IDLE_SECONDS,
                    'operating_day_cutoff_hour' => OperatingDay::CUTOFF_HOUR,
                    'next_day_delivery_hour' => 9,
                ],
                'channels' => app(PhotoDeliveryService::class)->channelDiagnostics(),
                'options' => [
                    'date_formats' => $this->dateFormatOptions($location),
                    'date_positions' => LocationPhotoSetting::DATE_POSITIONS,
                    'date_backgrounds' => LocationPhotoSetting::DATE_BACKGROUNDS,
                    'slideshow_durations' => LocationPhotoSetting::SLIDESHOW_DURATIONS,
                    'countdown_options' => LocationPhotoSetting::COUNTDOWN_OPTIONS,
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'kiosk_enabled' => ['nullable', 'boolean'],
            'slideshow_enabled' => ['nullable', 'boolean'],
            'kiosk_countdown_seconds' => ['nullable', 'integer', Rule::in(LocationPhotoSetting::COUNTDOWN_OPTIONS)],
            'slideshow_duration_seconds' => ['nullable', 'integer', Rule::in(LocationPhotoSetting::SLIDESHOW_DURATIONS)],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'date_format' => ['nullable', Rule::in(LocationPhotoSetting::DATE_FORMATS)],
            'date_position' => ['nullable', Rule::in(LocationPhotoSetting::DATE_POSITIONS)],
            'date_font_size' => ['nullable', 'integer', 'min:16', 'max:80'],
            'date_margin' => ['nullable', 'integer', 'min:8', 'max:120'],
            'date_background' => ['nullable', Rule::in(LocationPhotoSetting::DATE_BACKGROUNDS)],
            'failure_notify_email' => ['nullable', 'email', 'max:190'],
        ]);

        if ($denied = $this->guardLocationAccess($request, $validated['location_id'])) {
            return $denied;
        }

        $location = Location::findOrFail($validated['location_id']);

        if ($denied = $this->guardCompanyAccess($request, $location->company_id)) {
            return $denied;
        }

        $setting = LocationPhotoSetting::forLocation($location);
        $changes = collect($validated)->except('location_id')->filter(fn ($value) => $value !== null)->all();

        if ($request->has('failure_notify_email') && $request->input('failure_notify_email') === null) {
            $changes['failure_notify_email'] = null;
        }
        foreach (['kiosk_enabled', 'slideshow_enabled'] as $flag) {
            if ($request->has($flag)) {
                $changes[$flag] = $request->boolean($flag);
            }
        }

        $setting->update($changes);

        ActivityLog::log(
            'photo_settings_updated',
            'photos',
            sprintf('Updated photo settings for %s', $location->name),
            $this->resolveAuthUser($request)?->id,
            $location->id,
            'location_photo_setting',
            $setting->id,
            array_keys($changes)
        );

        return response()->json([
            'success' => true,
            'data' => $setting->fresh()->toAdminArray(),
        ]);
    }

    public function rotatePasscode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'mode' => ['required', Rule::in(['kiosk', 'slideshow'])],
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);

        if (!$location instanceof Location) {
            return $location;
        }

        $setting = LocationPhotoSetting::forLocation($location);
        $field = $validated['mode'] === 'kiosk' ? 'kiosk_passcode' : 'slideshow_passcode';

        $setting->update([$field => LocationPhotoSetting::generatePasscode()]);

        ActivityLog::log(
            'photo_passcode_rotated',
            'photos',
            sprintf('Rotated the %s passcode for %s', $validated['mode'], $location->name),
            $this->resolveAuthUser($request)?->id,
            $location->id,
            'location_photo_setting',
            $setting->id
        );

        return response()->json([
            'success' => true,
            'message' => 'New passcode saved. Devices that are already unlocked keep working until their device session expires, so re-enter the new passcode on each one when you are ready.',
            'data' => $setting->fresh()->toAdminArray(),
        ]);
    }

    public function templates(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        $templates = PhotoMessageTemplate::allForCompany($authUser?->company_id);

        return response()->json([
            'success' => true,
            'data' => [
                'templates' => $templates,
                'variables' => PhotoMessageTemplate::VARIABLES,
                'kinds' => PhotoMessageTemplate::KINDS,
            ],
        ]);
    }

    public function updateTemplate(Request $request, PhotoMessageTemplate $photoMessageTemplate): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);

        if ($authUser && $photoMessageTemplate->company_id !== null
            && (int) $photoMessageTemplate->company_id !== (int) $authUser->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to that template.',
            ], 403);
        }

        $validated = $request->validate([
            'email_subject' => ['required', 'string', 'max:190'],
            'email_body' => ['required', 'string', 'max:20000'],
            'sms_body' => ['required', 'string', 'max:600'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $photoMessageTemplate->update($validated);

        ActivityLog::log(
            'photo_template_updated',
            'photos',
            sprintf('Updated the %s photo message template', $photoMessageTemplate->kind),
            $authUser?->id,
            $authUser?->location_id,
            'photo_message_template',
            $photoMessageTemplate->id
        );

        return response()->json(['success' => true, 'data' => $photoMessageTemplate->fresh()]);
    }

    public function resetTemplate(Request $request, PhotoMessageTemplate $photoMessageTemplate): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);

        if ($authUser && $photoMessageTemplate->company_id !== null
            && (int) $photoMessageTemplate->company_id !== (int) $authUser->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to that template.',
            ], 403);
        }

        $defaults = PhotoMessageTemplate::defaults()[$photoMessageTemplate->kind] ?? null;

        if (!$defaults) {
            return response()->json(['success' => false, 'message' => 'No default exists for that template.'], 422);
        }

        $photoMessageTemplate->update($defaults);

        return response()->json(['success' => true, 'data' => $photoMessageTemplate->fresh()]);
    }

    protected function dateFormatOptions(Location $location): array
    {
        $tz = OperatingDay::timezoneFor($location);
        $now = now()->setTimezone($tz);

        return array_map(fn ($format) => [
            'value' => $format,
            'preview' => $now->format($format),
        ], LocationPhotoSetting::DATE_FORMATS);
    }
}
