<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SmsNotification;
use App\Services\SmsService;
use Database\Seeders\DefaultSmsNotificationSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SmsNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->company_id) {
            $company = Company::find($user->company_id);
            if ($company) {
                DefaultSmsNotificationSeeder::seedForCompany($company);
            }
        }

        $query = SmsNotification::with(['company', 'location'])
            ->where('company_id', $user->company_id);

        if (in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            $query->where(function ($q) use ($user) {
                $q->where('location_id', $user->location_id)->orWhereNull('location_id');
            });
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->trigger_type);
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $notifications = $query
            ->withCount('logs')
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        $notifications->getCollection()->transform(function ($n) {
            $body = $n->getEffectiveBody();
            $n->effective_body = $body;
            $n->is_body_customized = $n->is_default ? $n->isBodyCustomized() : false;
            $n->character_count = mb_strlen($body);
            $n->segment_count = SmsNotification::segmentCount($body);
            return $n;
        });

        return response()->json(['success' => true, 'data' => $notifications]);
    }

    public function show(SmsNotification $smsNotification): JsonResponse
    {
        $this->authorizeCompany($smsNotification);
        $smsNotification->load(['company', 'location']);
        $body = $smsNotification->getEffectiveBody();
        $smsNotification->effective_body = $body;
        $smsNotification->character_count = mb_strlen($body);
        $smsNotification->segment_count = SmsNotification::segmentCount($body);

        return response()->json(['success' => true, 'data' => $smsNotification]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => ['required', Rule::in(array_keys(SmsNotification::getAllTriggerTypes()))],
            'entity_type' => ['required', Rule::in(array_keys(SmsNotification::getEntityTypes()))],
            'entity_ids' => 'nullable|array',
            'entity_ids.*' => 'integer',
            'body' => 'required|string',
            'recipient_types' => 'required|array|min:1',
            'recipient_types.*' => [Rule::in(array_keys(SmsNotification::getRecipientTypes()))],
            'custom_phones' => 'nullable|array',
            'custom_phones.*' => 'string|max:32',
            'is_active' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
            'send_before_hours' => 'nullable|integer|min:1',
            'send_after_hours' => 'nullable|integer|min:1',
        ]);

        $validated['company_id'] = Auth::user()->company_id;
        $validated['is_default'] = false;

        $notification = SmsNotification::create($validated);

        return response()->json(['success' => true, 'data' => $notification], 201);
    }

    public function update(Request $request, SmsNotification $smsNotification): JsonResponse
    {
        $this->authorizeCompany($smsNotification);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => ['sometimes', Rule::in(array_keys(SmsNotification::getAllTriggerTypes()))],
            'entity_type' => ['sometimes', Rule::in(array_keys(SmsNotification::getEntityTypes()))],
            'entity_ids' => 'nullable|array',
            'entity_ids.*' => 'integer',
            'body' => 'sometimes|nullable|string',
            'recipient_types' => 'sometimes|array|min:1',
            'recipient_types.*' => [Rule::in(array_keys(SmsNotification::getRecipientTypes()))],
            'custom_phones' => 'nullable|array',
            'custom_phones.*' => 'string|max:32',
            'is_active' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
            'send_before_hours' => 'nullable|integer|min:1',
            'send_after_hours' => 'nullable|integer|min:1',
        ]);

        // Defaults: only the editable fields may change; identity stays locked.
        if ($smsNotification->is_default) {
            $validated = array_intersect_key($validated, array_flip([
                'body', 'recipient_types', 'custom_phones', 'is_active', 'send_before_hours', 'send_after_hours',
            ]));
        }

        $smsNotification->update($validated);

        return response()->json(['success' => true, 'data' => $smsNotification->fresh()]);
    }

    public function destroy(SmsNotification $smsNotification): JsonResponse
    {
        $this->authorizeCompany($smsNotification);

        if ($smsNotification->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Default SMS notifications cannot be deleted. Disable it instead.',
            ], 422);
        }

        $smsNotification->delete();

        return response()->json(['success' => true, 'message' => 'SMS notification deleted']);
    }

    public function toggleStatus(SmsNotification $smsNotification): JsonResponse
    {
        $this->authorizeCompany($smsNotification);
        $smsNotification->update(['is_active' => !$smsNotification->is_active]);

        return response()->json(['success' => true, 'data' => $smsNotification]);
    }

    public function resetDefault(SmsNotification $smsNotification): JsonResponse
    {
        $this->authorizeCompany($smsNotification);

        if (!$smsNotification->is_default) {
            return response()->json(['success' => false, 'message' => 'Only default notifications can be reset.'], 422);
        }

        $smsNotification->resetToDefault();

        return response()->json(['success' => true, 'data' => $smsNotification->fresh()]);
    }

    public function sendTest(Request $request, SmsNotification $smsNotification): JsonResponse
    {
        $this->authorizeCompany($smsNotification);

        $validated = $request->validate([
            'phone' => 'required|string|max:32',
        ]);

        if (!SmsService::isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'SMS is not configured. Set TWILIO_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM_NUMBER.',
            ], 422);
        }

        $sample = $this->sampleVariables();
        $message = $smsNotification->getEffectiveBody();
        foreach ($sample as $key => $value) {
            $message = preg_replace('/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/', $value, $message);
        }
        $message = '[TEST] ' . trim(strip_tags($message));

        try {
            $sid = app(SmsService::class)->sendSms($validated['phone'], $message);
            return response()->json([
                'success' => true,
                'message' => 'Test SMS sent',
                'data' => [
                    'provider_sid' => $sid,
                    'segments' => SmsNotification::segmentCount($message),
                    'characters' => mb_strlen($message),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to send: ' . $e->getMessage()], 500);
        }
    }

    public function logs(SmsNotification $smsNotification): JsonResponse
    {
        $this->authorizeCompany($smsNotification);

        return response()->json([
            'success' => true,
            'data' => $smsNotification->logs()->latest()->paginate(25),
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'trigger_types' => SmsNotification::getTriggerTypes(),
                'entity_types' => SmsNotification::getEntityTypes(),
                'recipient_types' => SmsNotification::getRecipientTypes(),
                'sms_configured' => SmsService::isConfigured(),
            ],
        ]);
    }

    public function seedDefaults(): JsonResponse
    {
        $company = Company::find(Auth::user()->company_id);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'No company found.'], 404);
        }

        DefaultSmsNotificationSeeder::seedForCompany($company);

        return response()->json(['success' => true, 'message' => 'Default SMS notifications seeded.']);
    }

    protected function authorizeCompany(SmsNotification $smsNotification): void
    {
        abort_if($smsNotification->company_id !== Auth::user()->company_id, 403, 'Unauthorized');
    }

    protected function sampleVariables(): array
    {
        return [
            'company_name' => 'Zap Zone',
            'customer_name' => 'Jane Doe',
            'customer_first_name' => 'Jane',
            'customer_phone' => '+1 555 010 2030',
            'location_name' => 'Zap Zone Brighton',
            'location_phone' => '(810) 555-1234',
            'package_name' => 'Ultimate Party Package',
            'attraction_name' => 'Laser Tag',
            'event_name' => 'Glow Night',
            'booking_reference' => 'BK-100245',
            'purchase_reference' => 'AP-100245',
            'event_reference' => 'EV-100245',
            'booking_date' => 'Saturday, July 4, 2026',
            'booking_time' => '2:00 PM',
            'purchase_date' => 'Saturday, July 4, 2026',
            'event_date' => 'Saturday, July 4, 2026',
            'event_time' => '7:00 PM',
            'booking_balance' => '$50.00',
            'event_balance' => '$0.00',
            'booking_participants' => '12',
            'purchase_quantity' => '4',
            'event_quantity' => '4',
            'purchase_total' => '$80.00',
            'payment_amount' => '$80.00',
            'payment_reference' => 'BK-100245',
        ];
    }
}
