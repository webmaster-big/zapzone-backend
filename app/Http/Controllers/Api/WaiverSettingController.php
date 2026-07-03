<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\WaiverSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiverSettingController extends Controller
{
    use ScopesByAuthUser;

    public function show(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if (!$authUser?->company_id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => WaiverSetting::forCompany($authUser->company_id),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if (!$authUser || !in_array($authUser->role, ['company_admin', 'admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can manage waiver settings.',
            ], 403);
        }

        $validated = $request->validate([
            'default_validity_days' => 'nullable|integer|min:1',
            'waivers_expire' => 'sometimes|boolean',
            'default_expiration_days' => 'nullable|integer|min:1',
            'require_new_on_text_change' => 'sometimes|boolean',
            'default_duplicate_rule' => 'sometimes|in:none,allow,manager_only',
            'reminder_window_hours' => 'sometimes|integer|min:1|max:168',
            'always_include_link_in_confirmation' => 'sometimes|boolean',
            'search_auto_refresh_seconds' => 'sometimes|integer|min:0|max:600',
            'kiosk_inactivity_timeout_seconds' => 'sometimes|integer|min:10|max:600',
            'kiosk_disable_autofill' => 'sometimes|boolean',
            'admin_delete_enabled' => 'sometimes|boolean',
            'manager_print_export_enabled' => 'sometimes|boolean',
            'manager_can_build_templates' => 'sometimes|boolean',
            'manager_can_view_deletion_log' => 'sometimes|boolean',
            'marketing_consent_enabled' => 'sometimes|boolean',
            'crm_sync_only_when_consented' => 'sometimes|boolean',
            'minor_marketing_disabled' => 'sometimes|boolean',
        ]);

        $settings = WaiverSetting::forCompany($authUser->company_id);
        $settings->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Waiver settings updated',
            'data' => $settings->fresh(),
        ]);
    }
}
