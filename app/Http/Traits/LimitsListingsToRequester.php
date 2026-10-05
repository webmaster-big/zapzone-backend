<?php

namespace App\Http\Traits;

use App\Models\Customer;
use App\Services\AddOnRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait LimitsListingsToRequester
{
    protected function limitListingToRequester($query, Request $request): ?JsonResponse
    {
        $requester = $request->user('sanctum');

        if (app(AddOnRuleService::class)->isStaff($requester)) {
            return null;
        }

        if ($requester instanceof Customer) {
            $query->where(function ($own) use ($requester) {
                $own->where('customer_id', $requester->id);

                if (! empty($requester->email)) {
                    $own->orWhere('guest_email', $requester->email);
                }
            });

            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Please log in to see your bookings and purchases.',
        ], 401);
    }
}
