<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStaff;
use App\Http\Traits\ScopesByAuthUser;
use App\Mail\StaffAccountCredentialsMail;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\ShareableToken;
use App\Models\User;
use App\Services\GmailApiService;
use App\Support\DataUriImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->active();
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                      ->orWhere('last_name', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('employee_id', 'like', $like)
                      ->orWhere('phone', 'like', $like)
                      ->orWhere('department', 'like', $like)
                      ->orWhere('position', 'like', $like)
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                });
            }
        }

        $sortBy = $request->get('sort_by', 'first_name');
        $sortOrder = strtolower((string) $request->get('sort_order', 'asc'));

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

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
            'registration_token' => 'nullable|string|max:64',
        ]);

        $tokenValue = $validated['registration_token'] ?? null;
        unset($validated['registration_token']);

        $staff = $this->resolveStaffUser($request);

        if ($staff) {
            if ((bool) config('registration.require_token') && ($denied = $this->guardStaffCreate($staff, $validated))) {
                return $denied;
            }
        } elseif ($tokenValue === null) {
            if ($denied = $this->denyUninvitedRegistration($request)) {
                return $denied;
            }
        }

        try {
            $validated['profile_path'] = $this->normalizeProfilePath($validated['profile_path'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->profilePathError($e);
        }

        $validated['password'] = Hash::make($validated['password']);

        $invitationId = null;

        $user = DB::transaction(function () use (&$validated, &$invitationId, $tokenValue, $staff) {
            $invitation = null;

            if (!$staff && $tokenValue !== null) {
                $invitation = $this->resolveInvitation($tokenValue, $validated);
                if ($invitation) {
                    $validated = $this->applyInvitation($invitation, $validated);
                    $invitationId = $invitation->id;
                }
            }

            $user = User::create($validated);

            if ($invitation) {
                $invitation->forceFill(['used_at' => now()])->save();
            }

            return $user;
        });

        $user->load(['company', 'location']);

        ActivityLog::log(
            action: 'User Created',
            category: 'create',
            description: "New user {$user->first_name} {$user->last_name} ({$user->role}) created",
            userId: $staff?->id,
            locationId: $user->location_id,
            entityType: 'user',
            entityId: $user->id,
            metadata: [
                'created_by' => [
                    'user_id' => $staff?->id,
                    'name' => $staff ? $staff->first_name . ' ' . $staff->last_name : null,
                    'email' => $staff?->email,
                ],
                'invitation_token_id' => $invitationId,
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

    private function resolveStaffUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User && in_array((string) $user->role, EnsureStaff::ROLES, true) ? $user : null;
    }

    private function guardStaffCreate(User $staff, array &$validated): ?JsonResponse
    {
        if (!in_array($staff->role, ['company_admin', 'location_manager'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: only company admins or location managers may create staff accounts',
            ], 403);
        }

        if ($staff->role === 'location_manager') {
            if ($validated['role'] === 'company_admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: location managers may only create managers and attendants',
                ], 403);
            }
            $validated['location_id'] = $staff->location_id;
        }

        if ($staff->company_id) {
            $validated['company_id'] = $staff->company_id;
        }

        if (!empty($validated['location_id'])) {
            $location = Location::find($validated['location_id']);
            if (!$location || ($staff->company_id && (int) $location->company_id !== (int) $staff->company_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: location does not belong to your company',
                ], 403);
            }
        }

        return null;
    }

    private function denyUninvitedRegistration(Request $request): ?JsonResponse
    {
        $enforce = (bool) config('registration.require_token');

        Log::warning('Public user registration without invitation token', [
            'email' => $request->input('email'),
            'role' => $request->input('role'),
            'company_id' => $request->input('company_id'),
            'location_id' => $request->input('location_id'),
            'ip' => $request->ip(),
            'enforced' => $enforce,
        ]);

        if (!$enforce) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'A registration invitation is required. Please use the link from your invitation email.',
            'errors' => ['registration_token' => ['A registration invitation is required.']],
        ], 422);
    }

    private function resolveInvitation(string $tokenValue, array $validated): ?ShareableToken
    {
        $enforce = (bool) config('registration.require_token');
        $token = ShareableToken::where('token', $tokenValue)->lockForUpdate()->first();

        $problem = match (true) {
            !$token => 'Invalid registration link.',
            $token->isUsed() => 'This registration link has already been used.',
            !$token->is_active => 'This registration link is no longer active.',
            $token->isExpired() => 'This registration link has expired.',
            $token->email && strcasecmp($token->email, $validated['email']) !== 0 => 'The email address must match the invitation.',
            default => null,
        };

        if ($problem === null) {
            return $token;
        }

        Log::warning('Public user registration with unusable invitation token', [
            'email' => $validated['email'],
            'token_id' => $token?->id,
            'problem' => $problem,
            'enforced' => $enforce,
        ]);

        if ($enforce) {
            throw ValidationException::withMessages(['registration_token' => [$problem]]);
        }

        return null;
    }

    private function applyInvitation(ShareableToken $token, array $validated): array
    {
        $enforce = (bool) config('registration.require_token');

        $forced = [
            'role' => $token->role,
            'company_id' => $token->company_id ?? ($validated['company_id'] ?? null),
            'location_id' => $token->location_id ?? ($validated['location_id'] ?? null),
        ];

        $overridden = array_keys(array_filter($forced, fn ($value, $key) => ($validated[$key] ?? null) != $value, ARRAY_FILTER_USE_BOTH));
        if ($overridden !== []) {
            Log::warning('Invitation overrides submitted registration fields', [
                'token_id' => $token->id,
                'fields' => $overridden,
                'submitted' => array_intersect_key($validated, $forced),
                'forced' => $forced,
            ]);
        }

        if (!empty($forced['location_id']) && !empty($forced['company_id'])) {
            $location = Location::find($forced['location_id']);
            if (!$location || (int) $location->company_id !== (int) $forced['company_id']) {
                Log::warning('Invitation location does not belong to invited company', [
                    'token_id' => $token->id,
                    'location_id' => $forced['location_id'],
                    'company_id' => $forced['company_id'],
                    'enforced' => $enforce,
                ]);
                if ($enforce) {
                    throw ValidationException::withMessages(['location_id' => ['The selected location does not belong to the invited company.']]);
                }
            }
        }

        $validated = array_merge($validated, $forced);
        $validated['status'] = config('registration.self_registered_status', 'active');

        return $validated;
    }

    private function normalizeProfilePath(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (DataUriImage::isDataUri($value)) {
            return DataUriImage::store($value, 'images/profiles');
        }

        if (strlen($value) > 255) {
            throw new \InvalidArgumentException('Profile picture must be a base64 image data URI or a stored file path');
        }

        return $value;
    }

    private function profilePathError(\InvalidArgumentException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'errors' => ['profile_path' => [$e->getMessage()]],
        ], 422);
    }

    private function deleteStoredProfileImage(?string $path): void
    {
        if (!is_string($path) || $path === '' || DataUriImage::isDataUri($path)) {
            return;
        }

        $relative = Str::startsWith($path, '/storage/') ? substr($path, strlen('/storage/')) : $path;

        if (!Str::startsWith($relative, ['profiles/', 'images/profiles/'])) {
            return;
        }

        if (Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->delete($relative);
        }
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

        if (array_key_exists('profile_path', $validated)) {
            try {
                $validated['profile_path'] = $this->normalizeProfilePath($validated['profile_path']);
            } catch (\InvalidArgumentException $e) {
                return $this->profilePathError($e);
            }
            if ($validated['profile_path'] !== $user->profile_path) {
                $this->deleteStoredProfileImage($user->profile_path);
            }
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
            'profile_path' => 'required|string|max:27262976',
        ]);

        try {
            $newPath = $this->normalizeProfilePath($validated['profile_path']);
        } catch (\InvalidArgumentException $e) {
            return $this->profilePathError($e);
        }

        $this->deleteStoredProfileImage($user->profile_path);

        $user->update(['profile_path' => $newPath]);
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
