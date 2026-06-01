<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Mail\StaffAccountCredentialsMail;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\User;
use App\Services\GmailApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = User::with(['company', 'location']);
        $authUser = $request->user();

        if ($authUser) {
            if ($authUser->role === 'company_admin') {
                $query->byCompany($authUser->company_id);
            } elseif ($authUser->role === 'location_manager') {
                $query->byCompany($authUser->company_id)
                      ->byLocation($authUser->location_id);
            }
        }

        if ($request->has('company_id') && (!$authUser || in_array($authUser->role, ['super_admin']))) {
            $query->byCompany($request->company_id);
        }

        if ($request->has('location_id')) {
            if (!$authUser || $authUser->role !== 'location_manager') {
                $query->byLocation($request->location_id);
            }
        }

        if ($request->has('role')) {
            $query->byRole($request->role);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->active();
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'first_name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['first_name', 'last_name', 'email', 'role', 'created_at', 'last_login'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $users = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'users' => $users->items(),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'nullable|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
            'profile_path' => 'nullable|string|max:27262976',
            'role' => ['required', Rule::in(['company_admin', 'location_manager', 'attendant'])],
            'employee_id' => 'nullable|string|unique:users',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'shift' => 'nullable|string|max:255',
            'assigned_areas' => 'nullable|array',
            'hire_date' => 'nullable|date',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);
        $user->load(['company', 'location']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'User Created',
            category: 'create',
            description: "New user {$user->first_name} {$user->last_name} ({$user->role}) created",
            userId: auth()->id(),
            locationId: $user->location_id,
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'created_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'created_at' => now()->toIso8601String(),
                'user_details' => [
                    'user_id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'location_id' => $user->location_id,
                    'company_id' => $user->company_id,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['company', 'location']);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'sometimes|nullable|exists:companies,id',
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'password' => 'sometimes|string|min:8|confirmed',
            'profile_path' => 'sometimes|nullable|string|max:27262976',
            'role' => ['sometimes', Rule::in(['company_admin', 'location_manager', 'attendant'])],
            'employee_id' => 'sometimes|nullable|string|unique:users,employee_id,' . $user->id,
            'department' => 'sometimes|nullable|string|max:255',
            'position' => 'sometimes|nullable|string|max:255',
            'shift' => 'sometimes|nullable|string|max:255',
            'assigned_areas' => 'sometimes|nullable|array',
            'hire_date' => 'sometimes|nullable|date',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        $user->load(['company', 'location']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'User Updated',
            category: 'update',
            description: "User {$user->first_name} {$user->last_name} information updated",
            userId: auth()->id(),
            locationId: $user->location_id,
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'updated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'updated_at' => now()->toIso8601String(),
                'updated_fields' => array_keys($validated),
                'user_details' => [
                    'user_id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user,
        ]);
    }

    public function updateProfilePath(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'profile_path' => 'required|string|max:27262976', // 20MB in base64 is ~27MB
        ]);

        if ($user->profile_path && Str::startsWith($user->profile_path, '/storage/profiles/')) {
            $oldImagePath = str_replace('/storage/', '', $user->profile_path);
            if (Storage::disk('public')->exists($oldImagePath)) {
                Storage::disk('public')->delete($oldImagePath);
            }
        }

        if (Str::startsWith($validated['profile_path'], 'data:image/')) {
            $imageData = $validated['profile_path'];
            $imageName = 'profiles/' . Str::uuid() . '.png';
            Storage::disk('public')->put($imageName, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData)));
            $validated['profile_path'] = '/storage/' . $imageName;
        }

        $user->update(['profile_path' => $validated['profile_path']]);
        $user->load(['company', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Profile picture updated successfully',
            'data' => $user,
        ]);
    }


    public function updateEmail(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'new_email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'required|string',
        ]);

        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Incorrect password',
            ], 403);
        }

        $user->update(['email' => $validated['new_email']]);
        $user->load(['company', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Email updated successfully',
            'data' => $user,
        ]);
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Incorrect current password',
            ], 403);
        }

        $user->update(['password' => Hash::make($validated['new_password'])]);
        $user->load(['company', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully',
            'data' => $user,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $user = User::findOrFail($id);
        $deletedBy = User::findOrFail(auth()->id());

        $userName = $user->first_name . ' ' . $user->last_name;
        $userId = $user->id;
        $locationId = $user->location_id;

        $user->delete();

        ActivityLog::log(
            action: 'User Deleted',
            category: 'delete',
            description: "User {$userName} was deleted by {$deletedBy->first_name} {$deletedBy->last_name}",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'user',
            entityId: $userId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $deletedBy->first_name . ' ' . $deletedBy->last_name,
                    'email' => $deletedBy->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'user_details' => [
                    'user_id' => $userId,
                    'name' => $userName,
                    'location_id' => $locationId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    public function getByCompany(int $companyId): JsonResponse
    {
        if ($scopeError = $this->guardCompanyAccess(null, $companyId)) {
            return $scopeError;
        }

        $query = User::with(['location'])
            ->byCompany($companyId)
            ->active();

        $authUser = auth()->user();
        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $query->where('location_id', $authUser->location_id);
        }

        $users = $query->orderBy('first_name')->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function getByLocation(int $locationId): JsonResponse
    {
        if ($scopeError = $this->guardLocationAccess(null, $locationId)) {
            return $scopeError;
        }

        $query = User::with(['company'])
            ->byLocation($locationId)
            ->active();

        $authUser = auth()->user();
        if ($authUser && $authUser->company_id) {
            $query->where('company_id', $authUser->company_id);
        }

        $users = $query->orderBy('first_name')->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function getByRole(string $role): JsonResponse
    {
        $query = User::with(['company', 'location'])
            ->byRole($role)
            ->active();

        $authUser = auth()->user();
        if ($authUser) {
            if ($authUser->company_id) {
                $query->where('company_id', $authUser->company_id);
            }
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->where('location_id', $authUser->location_id);
            }
        }

        $users = $query->orderBy('first_name')->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'data' => $user,
        ]);
    }

    public function updateLastLogin(User $user): JsonResponse
    {
        $user->update(['last_login' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Last login updated successfully',
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:users,id',
        ]);

        $currentUserId = auth()->id();
        $idsToDelete = array_diff($validated['ids'], [$currentUserId]);

        if (empty($idsToDelete)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete yourself or no valid users to delete',
            ], 400);
        }

        $users = User::whereIn('id', $idsToDelete)->get();
        $deletedCount = 0;
        $locationIds = [];

        foreach ($users as $user) {
            $locationIds[] = $user->location_id;
            $user->delete();
            $deletedCount++;
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Users Deleted',
            category: 'delete',
            description: "{$deletedCount} users deleted in bulk operation",
            userId: auth()->id(),
            locationId: $locationIds[0] ?? null,
            entityType: 'user',
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'deleted_count' => $deletedCount,
                'user_ids' => $idsToDelete,
                'affected_locations' => array_unique($locationIds),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} users deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    public function createWithCredentials(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser || !in_array($authUser->role, ['company_admin', 'location_manager'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: only company admins or location managers may create staff accounts',
            ], 403);
        }

        $allowedRoles = $authUser->role === 'company_admin'
            ? ['location_manager', 'attendant', 'company_admin']
            : ['location_manager', 'attendant']; // location_manager can create managers and attendants within their location

        $validated = $request->validate([
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'email'         => 'required|email|max:255|unique:users,email',
            'phone'         => 'nullable|string|max:20',
            'role'          => ['required', Rule::in($allowedRoles)],
            'location_id'   => 'nullable|exists:locations,id',
            'employee_id'   => 'nullable|string|unique:users,employee_id',
            'department'    => 'nullable|string|max:255',
            'position'      => 'nullable|string|max:255',
            'shift'         => 'nullable|string|max:255',
            'assigned_areas' => 'nullable|array',
            'hire_date'     => 'nullable|date',
            'status'        => ['sometimes', Rule::in(['active', 'inactive'])],
            'password_mode' => ['sometimes', Rule::in(['custom', 'generate'])],
            'password'      => 'required_if:password_mode,custom|nullable|string|min:8',
            'send_email'    => 'sometimes|boolean',
            'return_password' => 'sometimes|boolean',
            'login_url'     => 'sometimes|url|max:500',
        ]);

        if ($authUser->role === 'location_manager') {
            $validated['location_id'] = $authUser->location_id;
        }

        if (in_array($validated['role'], ['location_manager', 'attendant'], true)
            && empty($validated['location_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'location_id is required for location_manager and attendant roles',
                'errors'  => ['location_id' => ['Required for the selected role.']],
            ], 422);
        }

        if (!empty($validated['location_id'])) {
            $location = Location::find($validated['location_id']);
            if (!$location || ($authUser->company_id && (int) $location->company_id !== (int) $authUser->company_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: location does not belong to your company',
                ], 403);
            }
        }

        $passwordMode = $validated['password_mode'] ?? (empty($validated['password']) ? 'generate' : 'custom');
        $plainPassword = $passwordMode === 'generate'
            ? $this->generateStrongPassword(12)
            : $validated['password'];

        $payload = [
            'company_id'     => $authUser->company_id,
            'location_id'    => $validated['location_id'] ?? null,
            'first_name'     => $validated['first_name'],
            'last_name'      => $validated['last_name'],
            'email'          => $validated['email'],
            'phone'          => $validated['phone'] ?? null,
            'password'       => $plainPassword, // User model casts to hashed
            'role'           => $validated['role'],
            'employee_id'    => $validated['employee_id'] ?? null,
            'department'     => $validated['department'] ?? null,
            'position'       => $validated['position'] ?? null,
            'shift'          => $validated['shift'] ?? null,
            'assigned_areas' => $validated['assigned_areas'] ?? null,
            'hire_date'      => $validated['hire_date'] ?? null,
            'status'         => $validated['status'] ?? 'active',
        ];

        $user = User::create($payload);
        $user->load(['company', 'location']);

        ActivityLog::log(
            action: 'Staff Account Created',
            category: 'create',
            description: "Staff account {$user->first_name} {$user->last_name} ({$user->role}) created with credentials email",
            userId: $authUser->id,
            locationId: $user->location_id,
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'created_by' => [
                    'user_id' => $authUser->id,
                    'name'    => $authUser->first_name . ' ' . $authUser->last_name,
                    'email'   => $authUser->email,
                ],
                'password_mode' => $passwordMode,
                'email_sent'    => false, // patched below
            ]
        );

        $emailSent = false;
        $emailError = null;
        $sendEmail = $validated['send_email'] ?? true;

        if ($sendEmail) {
            try {
                $this->sendStaffCredentialsEmail(
                    $user,
                    $plainPassword,
                    $validated['login_url'] ?? null,
                    $authUser->first_name . ' ' . $authUser->last_name
                );
                $emailSent = true;
            } catch (\Throwable $e) {
                $emailError = $e->getMessage();
                Log::error('Failed to send staff credentials email', [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                    'error'   => $emailError,
                ]);
            }
        }

        $response = [
            'success' => true,
            'message' => $sendEmail
                ? ($emailSent ? 'Staff account created and credentials emailed' : 'Staff account created but email failed')
                : 'Staff account created (email not sent)',
            'data' => [
                'user'       => $user,
                'email_sent' => $emailSent,
                'email_error' => $emailError,
            ],
        ];

        if (!empty($validated['return_password']) && $validated['return_password']) {
            $response['data']['generated_password'] = $plainPassword;
        }

        return response()->json($response, 201);
    }

    public function resendCredentials(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser || $authUser->role !== 'company_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: only company admins may resend credentials',
            ], 403);
        }

        if ($authUser->company_id && (int) $user->company_id !== (int) $authUser->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: user belongs to another company',
            ], 403);
        }

        $validated = $request->validate([
            'password_mode' => ['sometimes', Rule::in(['custom', 'generate'])],
            'password'      => 'required_if:password_mode,custom|nullable|string|min:8',
            'login_url'     => 'sometimes|url|max:500',
            'return_password' => 'sometimes|boolean',
        ]);

        $passwordMode = $validated['password_mode'] ?? (empty($validated['password']) ? 'generate' : 'custom');
        $plainPassword = $passwordMode === 'generate'
            ? $this->generateStrongPassword(12)
            : $validated['password'];

        $user->update(['password' => $plainPassword]); // hashed cast applies
        $user->load(['company', 'location']);

        $emailSent = false;
        $emailError = null;
        try {
            $this->sendStaffCredentialsEmail(
                $user,
                $plainPassword,
                $validated['login_url'] ?? null,
                $authUser->first_name . ' ' . $authUser->last_name
            );
            $emailSent = true;
        } catch (\Throwable $e) {
            $emailError = $e->getMessage();
            Log::error('Failed to resend staff credentials email', [
                'user_id' => $user->id,
                'error'   => $emailError,
            ]);
        }

        ActivityLog::log(
            action: 'Staff Credentials Resent',
            category: 'update',
            description: "Credentials resent for {$user->first_name} {$user->last_name}",
            userId: $authUser->id,
            locationId: $user->location_id,
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'reset_by'      => $authUser->id,
                'password_mode' => $passwordMode,
                'email_sent'    => $emailSent,
            ]
        );

        $response = [
            'success' => $emailSent,
            'message' => $emailSent ? 'Credentials regenerated and emailed' : 'Credentials regenerated but email failed',
            'data' => [
                'user'        => $user,
                'email_sent'  => $emailSent,
                'email_error' => $emailError,
            ],
        ];

        if (!empty($validated['return_password']) && $validated['return_password']) {
            $response['data']['generated_password'] = $plainPassword;
        }

        return response()->json($response, 200);
    }

    protected function generateStrongPassword(int $length = 12): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower  = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%^&*';
        $all    = $upper . $lower . $digits . $symbols;

        $password = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($password); $i < $length; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }

    protected function sendStaffCredentialsEmail(User $user, string $plainPassword, ?string $loginUrl, ?string $createdByName): void
    {
        $mailable = new StaffAccountCredentialsMail($user, $plainPassword, $loginUrl, $createdByName);

        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        if ($useGmailApi && class_exists(GmailApiService::class)) {
            $gmail = new GmailApiService();
            $gmail->sendEmail(
                $user->email,
                'Your Zap Zone Staff Account',
                $mailable->render(),
                'Zap Zone'
            );
            return;
        }

        Mail::send([], [], function ($message) use ($user, $mailable) {
            $message->to($user->email)
                ->subject('Your Zap Zone Staff Account')
                ->html($mailable->render());
        });
    }
}
