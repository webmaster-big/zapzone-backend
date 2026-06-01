<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GlobalNote;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GlobalNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = GlobalNote::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('package_id')) {
            $query->forPackage($request->package_id);
        }

        $notes = $query->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $notes,
        ]);
    }

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

    public function show(GlobalNote $globalNote): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $globalNote,
        ]);
    }

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

    public function destroy(GlobalNote $globalNote): JsonResponse
    {
        $globalNote->delete();

        return response()->json([
            'success' => true,
            'message' => 'Global note deleted successfully',
        ]);
    }

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
