<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\CheckoutConcern;
use App\Models\Location;
use App\Services\CheckoutConcernService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CheckoutConcernController extends Controller
{
    use ScopesByAuthUser;

    private const SCHEDULE_HELP_DEDUPE_MINUTES = 10;
    private const ABANDONED_DEDUPE_MINUTES = 180;
    private const PURCHASE_LOOKBACK_MINUTES = 30;
    private const LOCATION_ALERT_CAP_PER_HOUR = 12;
    private const ABANDONED_ALERT_GRACE_MINUTES = 1;
    private const ENTITY_TYPES = ['package', 'attraction', 'event'];

    public function __construct(private readonly CheckoutConcernService $concerns)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'kind' => ['nullable', Rule::in([CheckoutConcern::KIND_SCHEDULE_HELP, CheckoutConcern::KIND_CALL_TO_BOOK])],
            'name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[^\r\n]+$/'],
            'phone' => ['required', 'string', 'min:7', 'max:40', 'regex:/^[^\r\n]+$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'message' => ['nullable', 'string', 'max:1000'],
            'entity_type' => ['nullable', Rule::in(self::ENTITY_TYPES)],
            'entity_id' => ['nullable', 'integer'],
            'entity_name' => ['nullable', 'string'],
            'preferred_date' => ['nullable', 'date'],
            'preferred_time' => ['nullable', 'string', 'max:20'],
            'context' => ['nullable', 'array'],
        ], [
            'name.required' => 'Please tell us your name.',
            'name.regex' => 'Please enter your name on a single line.',
            'phone.required' => 'Please give us a number we can reach you on.',
            'phone.regex' => 'Please enter your number on a single line.',
        ]);

        if (SmsService::toE164($validated['phone']) === null && !preg_match('/\d{7,}/', $validated['phone'])) {
            return response()->json([
                'success' => false,
                'message' => 'That phone number does not look complete. Please include the area code.',
                'errors' => ['phone' => ['That phone number does not look complete.']],
            ], 422);
        }

        $validated['kind'] = $validated['kind'] ?? CheckoutConcern::KIND_SCHEDULE_HELP;
        $validated['entity_name'] = $this->trimEntityName($validated['entity_name'] ?? null);
        $validated['fingerprint'] = $this->fingerprint($validated);

        if ($existing = $this->recentDuplicate($validated)) {
            Log::info('Guest concern ignored as a duplicate', [
                'kind' => $validated['kind'],
                'existing_checkout_concern_id' => $existing->id,
                'location_id' => $validated['location_id'],
                'guest' => $validated['name'],
                'phone' => $validated['phone'],
                'window_minutes' => self::SCHEDULE_HELP_DEDUPE_MINUTES,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'We already have this — the team will be in touch.',
            ]);
        }

        try {
            $concern = $this->concerns->record($validated);
        } catch (\Throwable $e) {
            Log::error('Could not store a guest concern', [
                'kind' => $validated['kind'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
                'guest' => $validated['name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not send that just now. Please call the venue directly.',
            ], 500);
        }

        $this->alertAfterResponse($concern);

        return response()->json([
            'success' => true,
            'message' => 'Thanks — the team at this venue has your details and will contact you about this.',
            'data' => ['id' => $concern->id],
        ], 201);
    }

    public function abandon(Request $request): JsonResponse
    {
        $silent = response()->json(['success' => true, 'data' => ['recorded' => false]]);

        $data = $this->sanitiseAbandonPayload($this->beaconPayload($request));

        if ($data === null) {
            Log::info('Abandoned-checkout beacon discarded - not enough to contact anyone', [
                'raw_keys' => array_keys($this->beaconPayload($request)),
                'ip' => $request->ip(),
            ]);

            return $silent;
        }

        if ($this->alreadyPaid($data)) {
            Log::info('Abandoned-checkout beacon ignored - this guest already paid', [
                'location_id' => $data['location_id'],
                'guest' => $data['name'],
                'email' => $data['email'],
                'lookback_minutes' => self::PURCHASE_LOOKBACK_MINUTES,
            ]);

            return $silent;
        }

        $existing = $this->recentDuplicate($data);

        if ($existing) {
            $this->refreshWithLaterProgress($existing, $data);

            Log::info('Abandoned-checkout beacon merged into an existing pending alert', [
                'checkout_concern_id' => $existing->id,
                'guest' => $data['name'],
                'step_reached' => data_get($data['context'] ?? [], 'step_label'),
                'window_minutes' => self::ABANDONED_DEDUPE_MINUTES,
            ]);

            return $silent;
        }

        try {
            $concern = $this->concerns->record($data);
        } catch (\Throwable $e) {
            Log::error('Could not store an abandoned checkout', [
                'location_id' => $data['location_id'] ?? null,
                'guest' => $data['name'] ?? null,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false], 500);
        }

        $concern->update(['alert_after' => now()->addMinutes(self::ABANDONED_ALERT_GRACE_MINUTES)]);

        Log::info('Abandoned checkout recorded - staff will be alerted after the grace period', [
            'checkout_concern_id' => $concern->id,
            'venue' => $concern->location?->name,
            'guest' => $concern->name,
            'phone' => $concern->phone,
            'grace_minutes' => self::ABANDONED_ALERT_GRACE_MINUTES,
            'alert_after' => $concern->fresh()->alert_after?->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'data' => ['recorded' => true]]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CheckoutConcern::with(['location', 'contact', 'handler']);

        $this->applyAuthScope($query, $request);

        if ($request->filled('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->filled('kind')) {
            $query->byKind($request->kind);
        }

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->boolean('open_only')) {
            $query->open();
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('entity_name', 'like', $search)
                    ->orWhere('message', 'like', $search);
            });
        }

        $concerns = $query->orderByDesc('created_at')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => [
                'concerns' => $concerns->items(),
                'pagination' => [
                    'current_page' => $concerns->currentPage(),
                    'last_page' => $concerns->lastPage(),
                    'per_page' => $concerns->perPage(),
                    'total' => $concerns->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, CheckoutConcern $checkoutConcern): JsonResponse
    {
        if (!$this->authorizeRecordScope($checkoutConcern)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $checkoutConcern->load(['location', 'contact', 'handler']);

        return response()->json(['success' => true, 'data' => $checkoutConcern]);
    }

    public function update(Request $request, CheckoutConcern $checkoutConcern): JsonResponse
    {
        if (!$this->authorizeRecordScope($checkoutConcern)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                CheckoutConcern::STATUS_NEW,
                CheckoutConcern::STATUS_CONTACTED,
                CheckoutConcern::STATUS_RESOLVED,
            ])],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $reopening = $validated['status'] === CheckoutConcern::STATUS_NEW;

        $checkoutConcern->update([
            'status' => $validated['status'],
            'resolution_note' => $validated['resolution_note'] ?? $checkoutConcern->resolution_note,
            'handled_by' => $reopening ? null : auth()->id(),
            'handled_at' => $reopening ? null : now(),
        ]);

        $checkoutConcern->load(['location', 'contact', 'handler']);

        return response()->json(['success' => true, 'data' => $checkoutConcern]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $scoped = function () use ($request) {
            $query = CheckoutConcern::query();
            $this->applyAuthScope($query, $request);

            if ($request->filled('location_id')) {
                $query->byLocation($request->location_id);
            }

            return $query;
        };

        return response()->json([
            'success' => true,
            'data' => [
                'open' => $scoped()->open()->count(),
                'schedule_help' => $scoped()->byKind(CheckoutConcern::KIND_SCHEDULE_HELP)->count(),
                'abandoned_checkout' => $scoped()->byKind(CheckoutConcern::KIND_ABANDONED_CHECKOUT)->count(),
                'call_to_book' => $scoped()->byKind(CheckoutConcern::KIND_CALL_TO_BOOK)->count(),
                'today' => $scoped()->whereDate('created_at', now()->toDateString())->count(),
            ],
        ]);
    }

    private function alertAfterResponse(CheckoutConcern $concern): void
    {
        $id = $concern->id;

        app()->terminating(function () use ($id) {
            $fresh = CheckoutConcern::with('location.company')->find($id);

            if (!$fresh || $fresh->alerted_at) {
                return;
            }

            try {
                $this->alertUnlessVenueIsFlooded($fresh);
            } catch (\Throwable $e) {
                Log::error('Deferred checkout-concern alert failed', [
                    'checkout_concern_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function alertUnlessVenueIsFlooded(CheckoutConcern $concern): void
    {
        $recentAtVenue = CheckoutConcern::where('location_id', $concern->location_id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentAtVenue > self::LOCATION_ALERT_CAP_PER_HOUR) {
            Log::warning('Checkout-concern alerts paused for a venue: hourly cap reached', [
                'location_id' => $concern->location_id,
                'checkout_concern_id' => $concern->id,
                'in_last_hour' => $recentAtVenue,
            ]);

            $concern->update([
                'alerted' => ['suppressed' => 'hourly cap reached for this venue'],
                'alerted_at' => now(),
            ]);

            return;
        }

        $this->concerns->alertStaff($concern);
        $concern->update(['alerted_at' => now()]);
    }

    private function beaconPayload(Request $request): array
    {
        $input = $request->all();

        if ($input) {
            return $input;
        }

        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function sanitiseAbandonPayload(array $input): ?array
    {
        $singleLine = static fn ($value) => trim(str_replace(["\r", "\n", "\0"], ' ', (string) $value));

        $locationId = filter_var($input['location_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$locationId || !Location::whereKey($locationId)->exists()) {
            return null;
        }

        $name = $singleLine($input['name'] ?? '');
        $phone = $singleLine($input['phone'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return null;
        }

        if (strlen(preg_replace('/\D+/', '', $phone)) < 7 || mb_strlen($phone) > 40) {
            return null;
        }

        $email = $singleLine($input['email'] ?? '');
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190
            ? strtolower($email)
            : null;

        $entityType = $input['entity_type'] ?? null;
        $entityType = in_array($entityType, self::ENTITY_TYPES, true) ? $entityType : null;

        $preferredDate = null;
        $rawDate = $singleLine($input['preferred_date'] ?? '');

        if ($rawDate !== '') {
            try {
                $preferredDate = \Carbon\Carbon::parse($rawDate)->toDateString();
            } catch (\Throwable $e) {
                $preferredDate = null;
            }
        }

        $data = [
            'kind' => CheckoutConcern::KIND_ABANDONED_CHECKOUT,
            'location_id' => $locationId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'entity_type' => $entityType,
            'entity_id' => filter_var($input['entity_id'] ?? null, FILTER_VALIDATE_INT) ?: null,
            'entity_name' => $this->trimEntityName($input['entity_name'] ?? null),
            'preferred_date' => $preferredDate,
            'preferred_time' => mb_substr($singleLine($input['preferred_time'] ?? ''), 0, 20) ?: null,
            'context' => is_array($input['context'] ?? null) ? $input['context'] : null,
        ];

        $data['fingerprint'] = $this->fingerprint($data);

        return $data;
    }

    private function trimEntityName(?string $name): ?string
    {
        $name = trim(str_replace(["\r", "\n", "\0"], ' ', (string) $name));

        return $name === '' ? null : Str::limit($name, 185);
    }

    private function fingerprint(array $data): string
    {
        $parts = [
            $data['kind'],
            $data['location_id'],
            strtolower(trim($data['email'] ?? '')),
            preg_replace('/\D+/', '', $data['phone']),
            $data['entity_type'] ?? '',
            $data['entity_id'] ?? '',
        ];

        if (in_array($data['kind'], [CheckoutConcern::KIND_SCHEDULE_HELP, CheckoutConcern::KIND_CALL_TO_BOOK], true)) {
            $parts[] = sha1(trim($data['message'] ?? ''));
            $parts[] = $data['preferred_date'] ?? '';
            $parts[] = $data['preferred_time'] ?? '';
        }

        return sha1(implode('|', $parts));
    }

    private function recentDuplicate(array $data): ?CheckoutConcern
    {
        $minutes = $data['kind'] === CheckoutConcern::KIND_ABANDONED_CHECKOUT
            ? self::ABANDONED_DEDUPE_MINUTES
            : self::SCHEDULE_HELP_DEDUPE_MINUTES;

        return CheckoutConcern::where('fingerprint', $data['fingerprint'])
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest('id')
            ->first();
    }

    private function refreshWithLaterProgress(CheckoutConcern $existing, array $data): void
    {
        $patch = array_filter([
            'entity_name' => $data['entity_name'],
            'preferred_date' => $data['preferred_date'],
            'preferred_time' => $data['preferred_time'],
            'context' => $data['context'],
        ], fn ($value) => $value !== null);

        if ($patch) {
            $existing->update($patch);
        }
    }

    private function alreadyPaid(array $data): bool
    {
        return $this->concerns->hasPaidSince(
            (int) $data['location_id'],
            $data['email'] ?? null,
            now()->subMinutes(self::PURCHASE_LOOKBACK_MINUTES)
        );
    }
}
