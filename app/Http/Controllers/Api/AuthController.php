<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as RoutingController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends RoutingController
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => 'required|min:8',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password) || $user->role === 'customer') {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.']
                ]
            ], 401);
        }

        $type = $user->role;
        $user->load('location');

        // Update last login timestamp
        $user->update(['last_login' => now()]);

        // Log login activity
        ActivityLog::log(
            action: 'User Login',
            category: 'login',
            description: "User {$user->first_name} {$user->last_name} ({$user->role}) logged in",
            userId: $user->id,
            locationId: $user->location_id,
            entityType: 'user',
            entityId: $user->id,
            metadata: ['role' => $user->role, 'email' => $user->email]
        );

        return $this->createTokenResponse($user, $type);
    }

    // login for customer
    public function customerLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => 'required|min:8',
        ]);

        // log request
        Log::info('Customer login attempt', ['email' => $request->email]);

        $user = Customer::where('email', $request->email)->first();


        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.']
                ]
            ], 401);
        }

        $type = $user->role;

        return $this->createTokenResponse($user, $type);
    }

    protected function createTokenResponse($user, $type)
    {
        return response()->json([
            'user'  => $user,
            'role'  => $type,
            'token' => $user->createToken($user->email)->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Log logout activity
        if ($user) {
            $isCustomer = $user instanceof Customer;
            ActivityLog::log(
                action: $isCustomer ? 'Customer Logout' : 'User Logout',
                category: 'logout',
                description: $isCustomer
                    ? "Customer {$user->first_name} {$user->last_name} logged out"
                    : "User {$user->first_name} {$user->last_name} logged out",
                userId: $isCustomer ? null : $user->id,
                locationId: $isCustomer ? null : ($user->location_id ?? null),
                entityType: $isCustomer ? 'customer' : 'user',
                entityId: $user->id
            );
        }

        $request->user()->tokens()->delete();
        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }
}
