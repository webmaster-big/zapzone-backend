<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    /**
     * Display a listing of email templates.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailTemplate::with(['company', 'location', 'creator'])
            ->where('company_id', $user->company_id);

        // Filter by location if specified
        if ($request->has('location_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('location_id', $request->location_id)
                    ->orWhereNull('location_id');
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Search by name or subject
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        $templates = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Store a newly created email template.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'category' => 'nullable|string|max:100',
            'location_id' => 'nullable|exists:locations,id',
            'available_variables' => 'nullable|array',
        ]);

        $user = Auth::user();

        $template = EmailTemplate::create([
            'company_id' => $user->company_id,
            'location_id' => $validated['location_id'] ?? $user->location_id,
            'created_by' => $user->id,
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'status' => $validated['status'] ?? 'draft',
            'category' => $validated['category'] ?? null,
            'available_variables' => $validated['available_variables'] ?? EmailTemplate::DEFAULT_VARIABLES,
        ]);

        $template->load(['company', 'location', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Email template created successfully',
            'data' => $template,
        ], 201);
    }

    /**
     * Display the specified email template.
     */
    public function show(EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        // Ensure user can only access their company's templates
        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        $emailTemplate->load(['company', 'location', 'creator', 'campaigns']);

        return response()->json([
            'success' => true,
            'data' => $emailTemplate,
        ]);
    }

    /**
     * Update the specified email template.
     */
    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        // Ensure user can only update their company's templates
        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'subject' => 'sometimes|string|max:255',
            'body' => 'sometimes|string',
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'category' => 'nullable|string|max:100',
            'location_id' => 'nullable|exists:locations,id',
            'available_variables' => 'nullable|array',
        ]);

        $emailTemplate->update($validated);
        $emailTemplate->load(['company', 'location', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully',
            'data' => $emailTemplate,
        ]);
    }

    /**
     * Remove the specified email template.
     */
    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        // Ensure user can only delete their company's templates
        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        $emailTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email template deleted successfully',
        ]);
    }

    /**
     * Duplicate an email template.
     */
    public function duplicate(EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        // Ensure user can only duplicate their company's templates
        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        $newTemplate = $emailTemplate->replicate();
        $newTemplate->name = $emailTemplate->name . ' (Copy)';
        $newTemplate->status = 'draft';
        $newTemplate->created_by = $user->id;
        $newTemplate->save();

        $newTemplate->load(['company', 'location', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Email template duplicated successfully',
            'data' => $newTemplate,
        ], 201);
    }

    /**
     * Get all available variables for email templates.
     */
    public function getAvailableVariables(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'default' => EmailTemplate::DEFAULT_VARIABLES,
                'customer' => EmailTemplate::CUSTOMER_VARIABLES,
                'user' => EmailTemplate::USER_VARIABLES,
            ],
        ]);
    }

    /**
     * Preview email template with sample data.
     */
    public function preview(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        // Ensure user can only preview their company's templates
        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        // Get sample data for preview
        $sampleVariables = $this->getSampleVariables($user);

        // Replace variables in subject and body
        $processedSubject = $this->replaceVariables($emailTemplate->subject, $sampleVariables);
        $processedBody = $this->replaceVariables($emailTemplate->body, $sampleVariables);

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

    /**
     * Preview with custom body and subject (for unsaved templates).
     */
    public function previewCustom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string',
            'body' => 'required|string',
        ]);

        $user = Auth::user();
        $sampleVariables = $this->getSampleVariables($user);

        $processedSubject = $this->replaceVariables($validated['subject'], $sampleVariables);
        $processedBody = $this->replaceVariables($validated['body'], $sampleVariables);

        // Extract used variables
        preg_match_all('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', $validated['body'] . ' ' . $validated['subject'], $matches);
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

    /**
     * Update template status (draft/active/archived).
     */
    public function updateStatus(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $user = Auth::user();

        if ($emailTemplate->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
        ]);

        $emailTemplate->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Template status updated successfully',
            'data' => $emailTemplate,
        ]);
    }

    /**
     * Get sample variables for preview.
     */
    protected function getSampleVariables($user): array
    {
        $company = $user->company;
        $location = $user->location;

        return [
            // Default variables
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
            'current_year' => now()->year,

            // Customer variables
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Jane Smith',
            'customer_first_name' => 'Jane',
            'customer_last_name' => 'Smith',
            'customer_phone' => '(555) 555-5555',
            'customer_address' => '789 Customer Lane, Town, ST 54321',
            'customer_total_bookings' => '5',
            'customer_total_spent' => '$500.00',
            'customer_last_visit' => now()->subDays(7)->format('F j, Y'),

            // User variables
            'user_email' => 'staff@company.com',
            'user_name' => 'Staff Member',
            'user_first_name' => 'Staff',
            'user_last_name' => 'Member',
            'user_role' => 'Attendant',
            'user_department' => 'Operations',
            'user_position' => 'Floor Staff',
        ];
    }

    /**
     * Replace template variables with actual values.
     */
    protected function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = preg_replace(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                $value ?? '',
                $content
            );
        }

        return $content;
    }
}
