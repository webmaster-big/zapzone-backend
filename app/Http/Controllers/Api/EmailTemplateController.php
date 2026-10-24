<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\EmailNotification;
use App\Models\EmailTemplate;
use App\Models\Location;
use App\Models\MessageLink;
use App\Services\PhotoDeliveryService;
use App\Support\EmailTemplateAccess;
use App\Support\EmailHtml;
use App\Support\TemplatePlaceholders;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailTemplate::with(['company', 'location', 'creator'])
            ->where('company_id', $user->company_id);

        if (!$this->isCompanyLevel($user)) {
            $own = $this->ownLocationId($request);
            $own !== null ? $query->coveringLocation($own) : $query->everyLocation();
        }

        if ($request->filled('location_id')) {
            $query->coveringLocation((int) $request->location_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('subject', 'like', $like)
                        ->orWhere('body', 'like', $like);
                });
            }
        }

        $sortBy = $request->get('sort_by');
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        if (in_array($sortBy, ['name', 'subject', 'status', 'created_at', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $templates = $query->paginate($request->per_page ?? 15);
        $directory = $this->locationDirectory($user->company_id);
        $templates->getCollection()->transform(fn (EmailTemplate $template) => $this->present($template, $directory, $user));

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => ['required', 'string', EmailHtml::rule()],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'category' => 'nullable|string|max:100',
            'location_id' => 'nullable|integer',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|distinct',
            'available_variables' => 'nullable|array',
            'attach_photos' => 'sometimes|boolean',
            'sms_body' => 'nullable|string|max:600',
        ]);

        $category = $validated['category'] ?? null;
        $status = $validated['status'] ?? 'draft';
        $isEventPhotos = $category === EmailTemplate::CATEGORY_EVENT_PHOTOS;

        if ($isEventPhotos && ($refused = $this->eventPhotoRefusal($user))) {
            return $refused;
        }

        $locationIds = $this->requestedLocationIds($request, $validated, null);

        if ($locationIds instanceof JsonResponse) {
            return $locationIds;
        }

        $save = function () use ($user, $validated, $category, $status, $isEventPhotos, $locationIds) {
            if ($isEventPhotos && $status === 'active' && ($clash = $this->eventPhotoClash($user->company_id, $locationIds, null))) {
                return $clash;
            }

            $template = EmailTemplate::create([
                'company_id' => $user->company_id,
                'location_id' => $this->mirrorLocationId($locationIds),
                'created_by' => $user->id,
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'status' => $status,
                'category' => $category,
                'available_variables' => $validated['available_variables'] ?? EmailTemplate::DEFAULT_VARIABLES,
            ] + (EmailTemplate::supportsTargeting() ? [
                'location_ids' => $locationIds,
                'attach_photos' => $isEventPhotos && (bool) ($validated['attach_photos'] ?? true),
                'sms_body' => $isEventPhotos ? $this->cleanSms($validated['sms_body'] ?? null) : null,
            ] : []));

            $template->load(['company', 'location', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Email template created successfully',
                'data' => $this->present($template, $this->locationDirectory($user->company_id), $user),
            ], 201);
        };

        return $isEventPhotos && $status === 'active' ? $this->withEventPhotoLock($user->company_id, $save) : $save();
    }

    public function show(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        if ($denied = $this->otherLocationRefusal($request, $emailTemplate, $user)) {
            return $denied;
        }

        $emailTemplate->load($this->isCompanyLevel($user) ? ['company', 'location', 'creator', 'campaigns'] : ['company', 'location', 'creator']);

        return response()->json([
            'success' => true,
            'data' => $this->present($emailTemplate, $this->locationDirectory($user->company_id), $user),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        if ($refused = $this->writeRefusal($emailTemplate, $user)) {
            return $refused;
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'subject' => 'sometimes|string|max:255',
            'body' => ['sometimes', 'string', EmailHtml::rule()],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'category' => 'nullable|string|max:100',
            'location_id' => 'nullable|integer',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|distinct',
            'available_variables' => 'nullable|array',
            'attach_photos' => 'sometimes|boolean',
            'sms_body' => 'nullable|string|max:600',
        ]);

        $category = array_key_exists('category', $validated) ? $validated['category'] : $emailTemplate->category;
        $isEventPhotos = $category === EmailTemplate::CATEGORY_EVENT_PHOTOS;

        if (($isEventPhotos || $emailTemplate->isEventPhotos()) && ($refused = $this->eventPhotoRefusal($user))) {
            return $refused;
        }

        if ($isEventPhotos && !$emailTemplate->isEventPhotos() && EmailNotification::where('email_template_id', $emailTemplate->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Email notifications use this template, so it cannot become an event photo email. Duplicate it and change the copy instead.',
                'errors' => ['category' => ['Email notifications use this template, so it cannot become an event photo email.']],
            ], 422);
        }

        $locationIds = $this->requestedLocationIds($request, $validated, $emailTemplate);

        if ($locationIds instanceof JsonResponse) {
            return $locationIds;
        }

        $save = function () use ($user, $validated, $emailTemplate, $locationIds) {
            $emailTemplate->refresh();
            $category = array_key_exists('category', $validated) ? $validated['category'] : $emailTemplate->category;
            $status = $validated['status'] ?? $emailTemplate->status;
            $isEventPhotos = $category === EmailTemplate::CATEGORY_EVENT_PHOTOS;
            $ids = array_key_exists('location_ids', $validated) || array_key_exists('location_id', $validated)
                ? $locationIds
                : $this->templateLocationIds($emailTemplate);

            if ($isEventPhotos && $status === 'active' && ($clash = $this->eventPhotoClash($user->company_id, $ids, $emailTemplate->id))) {
                return $clash;
            }

            $changes = array_intersect_key($validated, array_flip(['name', 'subject', 'body', 'status', 'category', 'available_variables']));
            $changes['location_id'] = $this->mirrorLocationId($ids);

            if (EmailTemplate::supportsTargeting()) {
                $changes['location_ids'] = $ids;

                if ($isEventPhotos) {
                    if (array_key_exists('attach_photos', $validated)) {
                        $changes['attach_photos'] = (bool) $validated['attach_photos'];
                    } elseif (!$emailTemplate->isEventPhotos()) {
                        $changes['attach_photos'] = true;
                    }

                    if (array_key_exists('sms_body', $validated)) {
                        $changes['sms_body'] = $this->cleanSms($validated['sms_body']);
                    }
                }
            }

            $emailTemplate->update($changes);
            $emailTemplate->load(['company', 'location', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Email template updated successfully',
                'data' => $this->present($emailTemplate, $this->locationDirectory($user->company_id), $user),
            ]);
        };

        return $isEventPhotos || $emailTemplate->isEventPhotos() ? $this->withEventPhotoLock($user->company_id, $save) : $save();
    }

    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        if ($refused = $this->writeRefusal($emailTemplate, $user)) {
            return $refused;
        }

        if ($emailTemplate->isEventPhotos() && ($refused = $this->eventPhotoRefusal($user))) {
            return $refused;
        }

        $emailTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email template deleted successfully',
        ]);
    }

    public function duplicate(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        if ($denied = $this->otherLocationRefusal($request, $emailTemplate, $user)) {
            return $denied;
        }

        if ($emailTemplate->isEventPhotos() && ($refused = $this->eventPhotoRefusal($user))) {
            return $refused;
        }

        if (!$this->isCompanyLevel($user) && $this->ownLocationId($request) === null) {
            return $this->noLocationRefusal();
        }

        if (!$this->isCompanyLevel($user) && $emailTemplate->isEventPhotos() && count($this->templateLocationIds($emailTemplate) ?? []) > 1) {
            return response()->json([
                'success' => false,
                'message' => 'This event photo email is shared by several locations, so a copy for your location could never be switched on. Ask a company admin to change it, or to take your location off it.',
            ], 422);
        }

        $newTemplate = $emailTemplate->replicate();
        $newTemplate->name = $emailTemplate->name . ' (Copy)';
        $newTemplate->status = 'draft';
        $newTemplate->created_by = $user->id;

        if (!$this->isCompanyLevel($user)) {
            $own = $this->ownLocationId($request);
            $newTemplate->location_id = $own;

            if (EmailTemplate::supportsTargeting()) {
                $newTemplate->location_ids = [$own];
            }
        }

        $newTemplate->save();
        $newTemplate->load(['company', 'location', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Email template duplicated successfully',
            'data' => $this->present($newTemplate, $this->locationDirectory($user->company_id), $user),
        ], 201);
    }

    public function getAvailableVariables(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'success' => true,
            'data' => [
                'default' => EmailTemplate::DEFAULT_VARIABLES,
                'customer' => EmailTemplate::CUSTOMER_VARIABLES,
                'user' => EmailTemplate::USER_VARIABLES,
                'event' => EmailTemplate::EVENT_PHOTO_VARIABLES + $this->linkVariableNames($user?->company_id),
            ],
        ]);
    }

    public function preview(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        if ($denied = $this->otherLocationRefusal($request, $emailTemplate, $user)) {
            return $denied;
        }

        $sampleVariables = $this->getSampleVariables($user, $request);

        $processedSubject = $this->replaceVariables($emailTemplate->subject, $sampleVariables);
        $processedBody = $this->replaceVariables($emailTemplate->body, $sampleVariables, true);

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $processedSubject,
                'body' => $processedBody,
                'variables_used' => $emailTemplate->extractUsedVariables(),
                'sample_variables' => $sampleVariables,
            ],
        ]);
    }

    public function previewCustom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string',
            'body' => 'required|string',
        ]);

        $user = Auth::user();
        $sampleVariables = $this->getSampleVariables($user, $request);

        $processedSubject = $this->replaceVariables($validated['subject'], $sampleVariables);
        $processedBody = $this->replaceVariables($validated['body'], $sampleVariables, true);

        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $validated['body'] . ' ' . $validated['subject'], $matches);
        $usedVariables = array_unique($matches[1] ?? []);

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $processedSubject,
                'body' => $processedBody,
                'variables_used' => $usedVariables,
                'sample_variables' => $sampleVariables,
            ],
        ]);
    }

    public function updateStatus(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        if ($refused = $this->writeRefusal($emailTemplate, $user)) {
            return $refused;
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
        ]);

        if ($emailTemplate->isEventPhotos() && ($refused = $this->eventPhotoRefusal($user))) {
            return $refused;
        }

        $save = function () use ($user, $validated, $emailTemplate) {
            $emailTemplate->refresh();

            if ($emailTemplate->isEventPhotos() && $validated['status'] === 'active' && ($clash = $this->eventPhotoClash($user->company_id, $this->templateLocationIds($emailTemplate), $emailTemplate->id))) {
                return $clash;
            }

            $emailTemplate->update(['status' => $validated['status']]);

            return response()->json([
                'success' => true,
                'message' => 'Template status updated successfully',
                'data' => $this->present($emailTemplate, $this->locationDirectory($user->company_id), $user),
            ]);
        };

        return $validated['status'] === 'active' ? $this->withEventPhotoLock($user->company_id, $save) : $save();
    }

    protected function isCompanyLevel($user): bool
    {
        return EmailTemplateAccess::isCompanyLevel($user);
    }

    protected function ownLocationId(?Request $request = null): ?int
    {
        $own = $this->staffLocationIds($request ?? request());

        return $own[0] ?? Auth::user()?->location_id;
    }

    protected function templateLocationIds(EmailTemplate $template): ?array
    {
        return $template->targetLocationIds();
    }

    protected function coversLocation(EmailTemplate $template, ?int $locationId): bool
    {
        $ids = $this->templateLocationIds($template);

        return $ids === null || ($locationId !== null && in_array($locationId, $ids, true));
    }

    protected function otherLocationRefusal(Request $request, EmailTemplate $template, $user): ?JsonResponse
    {
        if ($this->isCompanyLevel($user) || $this->coversLocation($template, $this->ownLocationId($request))) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'This template belongs to another location.',
        ], 403);
    }

    protected function noLocationRefusal(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Your account is not linked to a location, so you cannot save templates. Ask a company admin to set your location.',
        ], 403);
    }

    protected function withEventPhotoLock(int $companyId, Closure $save): JsonResponse
    {
        try {
            return Cache::lock('email-templates:event-photos:' . $companyId, 15)->block(5, $save);
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'Another event photo email is being saved right now. Try again in a moment.',
            ], 409);
        }
    }

    protected function mirrorLocationId(?array $locationIds): ?int
    {
        return $locationIds !== null && count($locationIds) === 1 ? (int) $locationIds[0] : null;
    }

    protected function requestedLocationIds(Request $request, array $validated, ?EmailTemplate $existing)
    {
        $user = Auth::user();

        if (array_key_exists('location_ids', $validated) && $validated['location_ids'] !== null && !is_array($validated['location_ids'])) {
            return response()->json([
                'success' => false,
                'message' => 'Choose the locations as a list.',
                'errors' => ['location_ids' => ['Choose the locations as a list.']],
            ], 422);
        }

        if (array_key_exists('location_ids', $validated)) {
            $ids = EmailTemplate::normalizeIds(array_unique($validated['location_ids'] ?? []));
        } elseif (array_key_exists('location_id', $validated)) {
            $ids = $validated['location_id'] ? [(int) $validated['location_id']] : null;
        } elseif ($existing) {
            return $this->templateLocationIds($existing);
        } else {
            $ids = null;
        }

        if (!$this->isCompanyLevel($user)) {
            $own = $this->ownLocationId($request);

            if ($own === null) {
                return $this->noLocationRefusal();
            }

            if ($ids !== null && array_values(array_diff($ids, [$own])) !== []) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only make templates for your own location.',
                    'errors' => ['location_ids' => ['You can only make templates for your own location.']],
                ], 403);
            }

            return [$own];
        }

        if ($ids !== null && Location::where('company_id', $user->company_id)->whereIn('id', $ids)->count() !== count($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Choose locations from your company.',
                'errors' => ['location_ids' => ['Choose locations from your company.']],
            ], 422);
        }

        return $ids;
    }

    protected function writeRefusal(EmailTemplate $template, $user): ?JsonResponse
    {
        if ($this->isCompanyLevel($user)) {
            return null;
        }

        $ids = $this->templateLocationIds($template);
        $own = $this->ownLocationId();

        if ($ids === null) {
            return response()->json([
                'success' => false,
                'message' => 'This template is used at every location, so only a company admin can change it. Duplicate it to make a version for your location.',
            ], 403);
        }

        if ($ids !== [$own]) {
            return response()->json([
                'success' => false,
                'message' => 'This template is used at other locations, so only a company admin can change it.',
            ], 403);
        }

        return null;
    }

    protected function eventPhotoRefusal($user): ?JsonResponse
    {
        if (EmailTemplateAccess::canManageEventPhotos($user)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Only managers and admins can change event photo emails.',
        ], 403);
    }

    protected function eventPhotoClash(int $companyId, ?array $locationIds, ?int $ignoreId): ?JsonResponse
    {
        if (!EmailTemplate::supportsTargeting()) {
            return null;
        }

        $others = EmailTemplate::query()
            ->eventPhotos()
            ->active()
            ->where('company_id', $companyId)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->filter(fn (EmailTemplate $other) => $other->isEventPhotos());

        foreach ($others as $other) {
            $otherIds = $this->templateLocationIds($other);

            if ($locationIds === null && $otherIds === null) {
                return $this->clash("\"{$other->name}\" is already the active event photo email for every location. Switch that one to Draft first, or save this one as a draft.");
            }

            if ($locationIds !== null && $otherIds !== null && ($shared = array_values(array_intersect($locationIds, $otherIds))) !== []) {
                $names = Location::whereIn('id', $shared)->orderBy('name')->pluck('name')->implode(', ');

                return $this->clash("{$names} already uses the event photo email \"{$other->name}\". Take that location off it first, or save this one as a draft.");
            }
        }

        return null;
    }

    protected function clash(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['location_ids' => [$message]],
        ], 422);
    }

    protected function cleanSms(?string $sms): ?string
    {
        $sms = trim((string) $sms);

        return $sms === '' ? null : $sms;
    }

    protected function locationDirectory(?int $companyId): array
    {
        return Location::where('company_id', $companyId)
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn (Location $location) => [(int) $location->id => ['name' => $location->name, 'active' => (bool) $location->is_active]])
            ->all();
    }

    protected function present(EmailTemplate $template, array $directory, $user): array
    {
        $ids = $this->templateLocationIds($template);

        return array_merge($template->toArray(), [
            'location_ids' => $ids ?? [],
            'all_locations' => $ids === null,
            'location_names' => $ids === null ? [] : array_values(array_filter(array_map(fn (int $id) => $directory[$id]['name'] ?? null, $ids))),
            'target_locations' => array_map(fn (int $id) => [
                'id' => $id,
                'name' => $directory[$id]['name'] ?? null,
                'active' => (bool) ($directory[$id]['active'] ?? false),
            ], $ids ?? []),
            'attach_photos' => (bool) $template->attach_photos,
            'sms_body' => $template->sms_body,
            'can_manage' => EmailTemplateAccess::canManage($template, $user, $this->isCompanyLevel($user) ? null : $this->ownLocationId()),
        ]);
    }

    protected function linkVariableNames(?int $companyId): array
    {
        if ($companyId === null || !MessageLink::isAvailable()) {
            return [];
        }

        return MessageLink::query()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (MessageLink $link) => ['link_' . $link->key => $link->label . ' address for the photo\'s location'])
            ->all();
    }

    protected function getSampleVariables($user, ?Request $request = null): array
    {
        $company = $user->company;
        $requested = $request && $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $location = $requested !== null && ($this->isCompanyLevel($user) || in_array($requested, $this->staffLocationIds($request), true))
            ? Location::where('company_id', $user->company_id)->find($requested)
            : null;
        $location ??= $user->location ?? $company?->locations()->first();
        $photos = app(PhotoDeliveryService::class);
        $linkValues = $photos->linkVariables($user->company_id, $location);

        return array_merge([
            'recipient_email' => 'customer@example.com',
            'recipient_name' => 'John Doe',
            'recipient_first_name' => 'John',
            'recipient_last_name' => 'Doe',
            'company_name' => $company?->company_name ?? 'Sample Company',
            'company_email' => $company?->email ?? 'info@company.com',
            'company_phone' => $company?->phone ?? '(555) 123-4567',
            'company_address' => $company?->address ?? '123 Main St, City, ST 12345',
            'location_name' => $location?->name ?? 'Main Location',
            'location_email' => $location?->email ?? 'location@company.com',
            'location_phone' => $location?->phone ?? '(555) 987-6543',
            'location_address' => $location ? "{$location->address}, {$location->city}, {$location->state} {$location->zip_code}" : '456 Location Ave, City, ST 12345',
            'current_date' => now()->format('F j, Y'),
            'current_year' => (string) now()->year,

            'customer_email' => 'customer@example.com',
            'customer_name' => 'Jane Smith',
            'customer_first_name' => 'Jane',
            'customer_last_name' => 'Smith',
            'customer_phone' => '(555) 555-5555',
            'customer_address' => '789 Customer Lane, Town, ST 54321',
            'customer_total_bookings' => '5',
            'customer_total_spent' => '$500.00',
            'customer_last_visit' => now()->subDays(7)->format('F j, Y'),

            'user_email' => 'staff@company.com',
            'user_name' => 'Staff Member',
            'user_first_name' => 'Staff',
            'user_last_name' => 'Member',
            'user_role' => 'Attendant',
            'user_department' => 'Operations',
            'user_position' => 'Floor Staff',

            'first_name' => 'there',
            'event_name' => 'Trunk or Treat',
            'event_date' => now()->addDays(7)->format('M j, Y'),
            'photo_link' => rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/photos/sample',
            'photo_count' => '3',
            'photo_date' => now()->format('M j, Y'),
            'expires_on' => now()->addDays(30)->format('M j, Y'),
            'business_name' => $company?->company_name ?? 'Sample Company',
            'support_contact' => (string) config('photos.support_contact', ''),
        ], array_diff_key($linkValues, ['links' => true]), [
            'links' => $photos->linksHtml($user->company_id, $location),
        ]);
    }

    protected function replaceVariables(string $content, array $variables, bool $escape = false): string
    {
        return TemplatePlaceholders::fill($content, $variables, $escape, ['links']);
    }
}
