<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\DashboardSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSettingController extends Controller
{
    use ScopesByAuthUser;

    public function show(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (!$authUser instanceof User) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $setting = DashboardSetting::forCompany($authUser->company_id);

        return response()->json([
            'success' => true,
            'data' => [
                'hidden_quick_actions' => $setting?->hidden_quick_actions ?? [],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (!$authUser instanceof User || $authUser->role !== 'company_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only company admins can change the dashboard shortcuts.',
            ], 403);
        }

        $validated = $request->validate([
            'hidden_quick_actions' => ['present', 'array', 'max:50'],
            'hidden_quick_actions.*' => ['string', 'max:60'],
        ]);

        $setting = DashboardSetting::forCompany($authUser->company_id);
        if (!$setting) {
            return response()->json(['success' => false, 'message' => 'No company on this account.'], 422);
        }

        $setting->update([
            'hidden_quick_actions' => array_values(array_unique($validated['hidden_quick_actions'])),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard shortcuts updated',
            'data' => ['hidden_quick_actions' => $setting->hidden_quick_actions],
        ]);
    }
}
