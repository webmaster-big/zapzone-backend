<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    /**
     * Display a listing of contacts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contact::with(['company', 'location', 'creator']);

        // Get company_id from authenticated user or request
        if ($request->has('company_id')) {
            $query->byCompany($request->company_id);
        }

        // Role-based filtering for location managers
        if ($request->has('user_id')) {
            $authUser = User::find($request->user_id);
            if ($authUser && $authUser->role === 'location_manager') {
                $query->byLocation($authUser->location_id);
            }
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        // Filter by tag(s)
        if ($request->has('tag')) {
            $query->byTag($request->tag);
        }

        if ($request->has('tags')) {
            $tags = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
            $query->byTags($tags);
        }

        // Filter by source
        if ($request->has('source')) {
            $query->bySource($request->source);
        }

        // Filter active only
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSorts = ['email', 'first_name', 'last_name', 'company_name', 'created_at', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $contacts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'contacts' => $contacts->items(),
                'pagination' => [
                    'current_page' => $contacts->currentPage(),
                    'last_page' => $contacts->lastPage(),
                    'per_page' => $contacts->perPage(),
                    'total' => $contacts->total(),
                    'from' => $contacts->firstItem(),
                    'to' => $contacts->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created contact.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
            'email' => [
                'required',
                'email',
                Rule::unique('contacts')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id);
                }),
            ],
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'company_name' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'source' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,inactive',
        ]);

        $validated['created_by'] = auth()->id();

        $contact = Contact::create($validated);
        $contact->load(['company', 'location', 'creator']);

        // Log activity
        ActivityLog::log(
            action: 'Contact Created',
            category: 'create',
            description: "Contact '{$contact->email}' created",
            userId: auth()->id(),
            locationId: $contact->location_id,
            entityType: 'contact',
            entityId: $contact->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Contact created successfully',
            'data' => $contact,
        ], 201);
    }

    /**
     * Display the specified contact.
     */
    public function show(Contact $contact): JsonResponse
    {
        $contact->load(['company', 'location', 'creator']);

        return response()->json([
            'success' => true,
            'data' => $contact,
        ]);
    }

    /**
     * Update the specified contact.
     */
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('contacts')->where(function ($query) use ($contact) {
                    return $query->where('company_id', $contact->company_id);
                })->ignore($contact->id),
            ],
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'company_name' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'source' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,inactive',
        ]);

        $contact->update($validated);
        $contact->load(['company', 'location', 'creator']);

        // Log activity
        ActivityLog::log(
            action: 'Contact Updated',
            category: 'update',
            description: "Contact '{$contact->email}' updated",
            userId: auth()->id(),
            locationId: $contact->location_id,
            entityType: 'contact',
            entityId: $contact->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Contact updated successfully',
            'data' => $contact,
        ]);
    }

    /**
     * Remove the specified contact.
     */
    public function destroy(Contact $contact): JsonResponse
    {
        $email = $contact->email;
        $contactId = $contact->id;
        $locationId = $contact->location_id;

        $contact->delete();

        // Log activity
        ActivityLog::log(
            action: 'Contact Deleted',
            category: 'delete',
            description: "Contact '{$email}' deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'contact',
            entityId: $contactId
        );

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully',
        ]);
    }

    /**
     * Bulk import contacts.
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
            'contacts' => 'required|array|min:1|max:1000',
            'contacts.*.email' => 'required|email',
            'contacts.*.first_name' => 'nullable|string|max:255',
            'contacts.*.last_name' => 'nullable|string|max:255',
            'contacts.*.phone' => 'nullable|string|max:50',
            'contacts.*.company_name' => 'nullable|string|max:255',
            'contacts.*.tags' => 'nullable|array',
            'tags' => 'nullable|array', // Global tags to apply to all
            'source' => 'nullable|string|max:100',
            'skip_duplicates' => 'nullable|boolean',
        ]);

        $companyId = $validated['company_id'];
        $locationId = $validated['location_id'] ?? null;
        $globalTags = $validated['tags'] ?? [];
        $source = $validated['source'] ?? 'import';
        $skipDuplicates = $validated['skip_duplicates'] ?? true;

        $imported = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($validated['contacts'] as $index => $contactData) {
                // Check for duplicate
                $exists = Contact::where('company_id', $companyId)
                    ->where('email', $contactData['email'])
                    ->exists();

                if ($exists) {
                    if ($skipDuplicates) {
                        $skipped++;
                        continue;
                    } else {
                        $errors[] = [
                            'row' => $index + 1,
                            'email' => $contactData['email'],
                            'error' => 'Email already exists',
                        ];
                        continue;
                    }
                }

                // Merge tags
                $tags = array_unique(array_merge(
                    $globalTags,
                    $contactData['tags'] ?? []
                ));

                Contact::create([
                    'company_id' => $companyId,
                    'location_id' => $locationId,
                    'email' => $contactData['email'],
                    'first_name' => $contactData['first_name'] ?? null,
                    'last_name' => $contactData['last_name'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'company_name' => $contactData['company_name'] ?? null,
                    'tags' => !empty($tags) ? $tags : null,
                    'source' => $source,
                    'status' => 'active',
                    'email_opt_in' => true,
                    'subscribed_at' => now(),
                    'created_by' => auth()->id(),
                ]);

                $imported++;
            }

            DB::commit();

            // Log activity
            ActivityLog::log(
                action: 'Contacts Bulk Import',
                category: 'create',
                description: "Imported {$imported} contacts, skipped {$skipped}",
                userId: auth()->id(),
                locationId: $locationId,
                metadata: [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors_count' => count($errors),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => "Imported {$imported} contacts successfully",
                'data' => [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk delete contacts.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:contacts,id',
        ]);

        $deletedCount = Contact::whereIn('id', $validated['ids'])->delete();

        // Log activity
        ActivityLog::log(
            action: 'Contacts Bulk Delete',
            category: 'delete',
            description: "Bulk deleted {$deletedCount} contacts",
            userId: auth()->id(),
            metadata: ['count' => $deletedCount, 'ids' => $validated['ids']]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} contact(s) deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    /**
     * Bulk update contacts (e.g., add/remove tags, change status).
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:contacts,id',
            'action' => 'required|in:add_tags,remove_tags,set_status,set_location',
            'tags' => 'required_if:action,add_tags,remove_tags|array',
            'status' => 'required_if:action,set_status|in:active,inactive',
            'location_id' => 'required_if:action,set_location|exists:locations,id',
        ]);

        $contacts = Contact::whereIn('id', $validated['ids'])->get();
        $updatedCount = 0;

        foreach ($contacts as $contact) {
            switch ($validated['action']) {
                case 'add_tags':
                    $currentTags = $contact->tags ?? [];
                    $newTags = array_unique(array_merge($currentTags, $validated['tags']));
                    $contact->update(['tags' => $newTags]);
                    break;

                case 'remove_tags':
                    $currentTags = $contact->tags ?? [];
                    $newTags = array_values(array_diff($currentTags, $validated['tags']));
                    $contact->update(['tags' => !empty($newTags) ? $newTags : null]);
                    break;

                case 'set_status':
                    $contact->update(['status' => $validated['status']]);
                    break;

                case 'set_location':
                    $contact->update(['location_id' => $validated['location_id']]);
                    break;
            }
            $updatedCount++;
        }

        // Log activity
        ActivityLog::log(
            action: 'Contacts Bulk Update',
            category: 'update',
            description: "Bulk updated {$updatedCount} contacts ({$validated['action']})",
            userId: auth()->id(),
            metadata: [
                'count' => $updatedCount,
                'action' => $validated['action'],
                'ids' => $validated['ids'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$updatedCount} contact(s) updated successfully",
            'data' => ['updated_count' => $updatedCount],
        ]);
    }

    /**
     * Get all unique tags used in contacts.
     */
    public function getTags(Request $request): JsonResponse
    {
        $query = Contact::query();

        if ($request->has('company_id')) {
            $query->byCompany($request->company_id);
        }

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        $contacts = $query->whereNotNull('tags')->pluck('tags');
        
        $allTags = [];
        foreach ($contacts as $tags) {
            if (is_array($tags)) {
                $allTags = array_merge($allTags, $tags);
            }
        }

        $uniqueTags = array_unique($allTags);
        sort($uniqueTags);

        return response()->json([
            'success' => true,
            'data' => array_values($uniqueTags),
        ]);
    }

    /**
     * Get contact statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $query = Contact::query();

        if ($request->has('company_id')) {
            $query->byCompany($request->company_id);
        }

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        $total = (clone $query)->count();
        $active = (clone $query)->where('status', 'active')->count();
        $inactive = (clone $query)->where('status', 'inactive')->count();

        $bySource = (clone $query)
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->pluck('count', 'source');

        $recentlyAdded = (clone $query)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'by_source' => $bySource,
                'recently_added' => $recentlyAdded,
            ],
        ]);
    }

    /**
     * Deactivate a contact (public endpoint for email unsubscribe links).
     */
    public function deactivate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'company_id' => 'required|exists:companies,id',
        ]);

        $contact = Contact::where('email', $validated['email'])
            ->where('company_id', $validated['company_id'])
            ->first();

        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => 'Contact not found',
            ], 404);
        }

        $contact->deactivate();

        return response()->json([
            'success' => true,
            'message' => 'Contact deactivated successfully',
        ]);
    }

    /**
     * Export contacts for email campaign integration.
     */
    public function exportForCampaign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
            'tags' => 'nullable|array',
            'status' => 'nullable|in:active,inactive',
            'active_only' => 'nullable|boolean',
        ]);

        $query = Contact::byCompany($validated['company_id']);

        if (!empty($validated['location_id'])) {
            $query->byLocation($validated['location_id']);
        }

        if (!empty($validated['tags'])) {
            $query->byTags($validated['tags']);
        }

        if (!empty($validated['status'])) {
            $query->byStatus($validated['status']);
        }

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        $contacts = $query->get()->map(function ($contact) {
            return [
                'id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->full_name,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'variables' => $contact->getEmailVariables(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $contacts->count(),
                'contacts' => $contacts,
            ],
        ]);
    }

    /**
     * Add a tag to a contact.
     */
    public function addTag(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'tag' => 'required|string|max:100',
        ]);

        $tag = strtolower(trim($validated['tag']));

        if ($contact->hasTag($tag)) {
            return response()->json([
                'success' => false,
                'message' => 'Contact already has this tag',
                'data' => $contact->fresh(),
            ], 422);
        }

        $contact->addTag($tag);

        return response()->json([
            'success' => true,
            'message' => 'Tag added successfully',
            'data' => $contact->fresh(),
        ]);
    }

    /**
     * Remove a tag from a contact.
     */
    public function removeTag(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'tag' => 'required|string|max:100',
        ]);

        $tag = strtolower(trim($validated['tag']));

        if (!$contact->hasTag($tag)) {
            return response()->json([
                'success' => false,
                'message' => 'Contact does not have this tag',
                'data' => $contact->fresh(),
            ], 422);
        }

        $contact->removeTag($tag);

        return response()->json([
            'success' => true,
            'message' => 'Tag removed successfully',
            'data' => $contact->fresh(),
        ]);
    }
}
