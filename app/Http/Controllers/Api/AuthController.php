<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
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

    // register for customer
    public function customerRegister(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:customers,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8|confirmed',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date',
        ]);

        Log::info('Customer registration attempt', ['email' => $request->email]);

        try {
            $customer = Customer::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'zip' => $request->zip,
                'country' => $request->country,
                'date_of_birth' => $request->date_of_birth,
                'status' => 'active',
            ]);

            Log::info('Customer registered successfully', [
                'customer_id' => $customer->id,
                'email' => $customer->email,
            ]);

            // Return the customer with auth token
            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'user' => $customer,
                'token' => $customer->createToken($customer->email)->plainTextToken,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Customer registration failed', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
