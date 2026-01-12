<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    /**
     * Display a listing of contacts.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Contact::with(['location']);

            // Role-based filtering
            if ($request->has('user_id')) {
                $authUser = User::where('id', $request->user_id)->first();
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
                $query->where('status', $request->status);
            }

            // Filter by source
            if ($request->has('source')) {
                $query->bySource($request->source);
            }

            // Search by name, email, or phone
            if ($request->has('search')) {
                $query->search($request->search);
            }

            // Filter by date range
            if ($request->has('start_date')) {
                $query->where('created_at', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->where('created_at', '<=', $request->end_date);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            if (in_array($sortBy, ['name', 'email', 'created_at', 'last_activity_at', 'total_bookings', 'total_purchases', 'total_spent'])) {
                $query->orderBy($sortBy, $sortOrder);
            }

            $perPage = min($request->get('per_page', 15), 100);
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
        } catch (\Exception $e) {
            Log::error('Error fetching contacts', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contacts',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    /**
     * Store a newly created contact.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:contacts,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'source' => ['nullable', Rule::in(['booking', 'attraction_purchase', 'manual'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'notes' => 'nullable|string',
        ]);

        // Set defaults
        $validated['source'] = $validated['source'] ?? 'manual';
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['last_activity_at'] = now();

        $contact = Contact::create($validated);
        $contact->load('location');

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
        $contact->load('location');

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
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('contacts', 'email')->ignore($contact->id)],
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'source' => ['nullable', Rule::in(['booking', 'attraction_purchase', 'manual'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'notes' => 'nullable|string',
        ]);

        $contact->update($validated);
        $contact->load('location');

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
        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully',
        ]);
    }

    /**
     * Toggle contact status.
     */
    public function toggleStatus(Contact $contact): JsonResponse
    {
        $contact->status = $contact->status === 'active' ? 'inactive' : 'active';
        $contact->save();

        return response()->json([
            'success' => true,
            'message' => 'Contact status updated successfully',
            'data' => $contact,
        ]);
    }

    /**
     * Bulk delete contacts.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:contacts,id',
        ]);

        Contact::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contacts deleted successfully',
        ]);
    }

    /**
     * Find contact by email.
     */
    public function findByEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $contact = Contact::where('email', $validated['email'])->first();

        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => 'Contact not found',
            ], 404);
        }

        $contact->load('location');

        return response()->json([
            'success' => true,
            'data' => $contact,
        ]);
    }

    /**
     * Get contact statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $query = Contact::query();

            // Role-based filtering
            if ($request->has('user_id')) {
                $authUser = User::where('id', $request->user_id)->first();
                if ($authUser && $authUser->role === 'location_manager') {
                    $query->byLocation($authUser->location_id);
                }
            }

            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            $stats = [
                'total_contacts' => (clone $query)->count(),
                'active_contacts' => (clone $query)->where('status', 'active')->count(),
                'inactive_contacts' => (clone $query)->where('status', 'inactive')->count(),
                'from_bookings' => (clone $query)->where('source', 'booking')->count(),
                'from_purchases' => (clone $query)->where('source', 'attraction_purchase')->count(),
                'from_manual' => (clone $query)->where('source', 'manual')->count(),
                'total_bookings' => (clone $query)->sum('total_bookings'),
                'total_purchases' => (clone $query)->sum('total_purchases'),
                'total_revenue' => (clone $query)->sum('total_spent'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching contact statistics', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
            ], 500);
        }
    }

    /**
     * Export contacts.
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $query = Contact::with(['location']);

            // Apply same filters as index
            if ($request->has('user_id')) {
                $authUser = User::where('id', $request->user_id)->first();
                if ($authUser && $authUser->role === 'location_manager') {
                    $query->byLocation($authUser->location_id);
                }
            }

            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('source')) {
                $query->bySource($request->source);
            }

            if ($request->has('search')) {
                $query->search($request->search);
            }

            $contacts = $query->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'contacts' => $contacts,
                    'total' => $contacts->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting contacts', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export contacts',
            ], 500);
        }
    }
}
