<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GlobalNote;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GlobalNoteController extends Controller
{
    /**
     * Display a listing of global notes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = GlobalNote::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by package ID
        if ($request->has('package_id')) {
            $query->forPackage($request->package_id);
        }

        $notes = $query->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $notes,
        ]);
    }

    /**
     * Get notes for a specific package (including global notes)
     */
    public function getForPackage(int $packageId): JsonResponse
    {
        $notes = GlobalNote::active()
            ->forPackage($packageId)
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notes,
        ]);
    }

    /**
     * Store a newly created global note.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'required|string',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'exists:packages,id',
            'is_active' => 'boolean',
            'display_order' => 'nullable|integer',
        ]);

        $note = GlobalNote::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Global note created successfully',
            'data' => $note,
        ], 201);
    }

    /**
     * Display the specified global note.
     */
    public function show(GlobalNote $globalNote): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $globalNote,
        ]);
    }

    /**
     * Update the specified global note.
     */
    public function update(Request $request, GlobalNote $globalNote): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|nullable|string|max:255',
            'content' => 'sometimes|string',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'exists:packages,id',
            'is_active' => 'boolean',
            'display_order' => 'nullable|integer',
        ]);

        $globalNote->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Global note updated successfully',
            'data' => $globalNote,
        ]);
    }

    /**
     * Remove the specified global note.
     */
    public function destroy(GlobalNote $globalNote): JsonResponse
    {
        $globalNote->delete();

        return response()->json([
            'success' => true,
            'message' => 'Global note deleted successfully',
        ]);
    }

    /**
     * Toggle the active status of a global note.
     */
    public function toggleStatus(GlobalNote $globalNote): JsonResponse
    {
        $globalNote->is_active = !$globalNote->is_active;
        $globalNote->save();

        return response()->json([
            'success' => true,
            'message' => 'Global note status updated',
            'data' => $globalNote,
        ]);
    }
}
