<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingInvitation;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class RsvpController extends Controller
{
    /**
     * Get party details for an RSVP token (public - no auth required).
     */
    public function show(string $token): JsonResponse
    {
        try {
            $invitation = BookingInvitation::where('rsvp_token', $token)
                ->with([
                    'booking:id,customer_id,package_id,location_id,booking_date,booking_time,participants,guest_of_honor_name,guest_of_honor_age,guest_name',
                    'booking.customer:id,first_name,last_name',
                    'booking.package:id,name,description,invitation_download_link',
                    'booking.location:id,name,address,city,state,zip_code,phone,email',
                    'booking.location.company:id,company_name,logo_path',
                ])
                ->first();

            if (!$invitation) {
                return response()->json(['message' => 'Invitation not found'], 404);
            }

            $booking = $invitation->booking;

            // Build public-safe response (no sensitive data)
            return response()->json([
                'invitation' => [
                    'guest_name' => $invitation->guest_name,
                    'rsvp_status' => $invitation->rsvp_status,
                    'has_responded' => $invitation->hasResponded(),
                    'responded_at' => $invitation->responded_at,
                    'rsvp_full_name' => $invitation->rsvp_full_name,
                    'rsvp_guest_count' => $invitation->rsvp_guest_count,
                ],
                'party' => [
                    'host_name' => $booking->customer
                        ? trim($booking->customer->first_name . ' ' . $booking->customer->last_name)
                        : ($booking->guest_name ?? 'Your Host'),
                    'package_name' => $booking->package?->name ?? 'Party',
                    'package_description' => $booking->package?->description ?? '',
                    'date' => $booking->booking_date?->format('F j, Y') ?? '',
                    'time' => $booking->booking_time ?? '',
                    'guest_of_honor_name' => $booking->guest_of_honor_name ?? '',
                    'guest_of_honor_age' => $booking->guest_of_honor_age ?? '',
                    'invitation_download_link' => $booking->package?->invitation_download_link ?? null,
                ],
                'location' => [
                    'name' => $booking->location?->name ?? '',
                    'address' => $booking->location ? trim(implode(', ', array_filter([
                        $booking->location->address,
                        $booking->location->city,
                        $booking->location->state,
                        $booking->location->zip_code,
                    ]))) : '',
                    'phone' => $booking->location?->phone ?? '',
                    'email' => $booking->location?->email ?? '',
                ],
                'company' => [
                    'name' => $booking->location?->company?->company_name ?? '',
                    'logo_url' => $booking->location?->company?->logo_path
                        ? (str_starts_with($booking->location->company->logo_path, 'http')
                            ? $booking->location->company->logo_path
                            : url('storage/' . $booking->location->company->logo_path))
                        : null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching RSVP details', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Submit an RSVP response (public - no auth required).
     */
    public function store(Request $request, string $token): JsonResponse
    {
        try {
            $invitation = BookingInvitation::where('rsvp_token', $token)->first();

            if (!$invitation) {
                return response()->json(['message' => 'Invitation not found'], 404);
            }

            $validated = $request->validate([
                'rsvp_status' => ['required', Rule::in(['attending', 'declined'])],
                'full_name' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'guest_count' => 'nullable|integer|min:1|max:20',
                'notes' => 'nullable|string|max:1000',
                'marketing_opt_in' => 'boolean',
            ]);

            // Submit the RSVP
            $invitation->submitRsvp($validated);

            // If marketing opt-in is checked, create/update marketing contact
            if (!empty($validated['marketing_opt_in'])) {
                try {
                    $service = new InvitationService();
                    $service->createContactFromRsvp($invitation->fresh());
                } catch (\Exception $e) {
                    Log::error('Failed to create contact from RSVP opt-in', [
                        'invitation_id' => $invitation->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the RSVP response for this
                }
            }

            return response()->json([
                'message' => $validated['rsvp_status'] === 'attending'
                    ? 'Thanks for RSVPing! We look forward to seeing you!'
                    : 'Thanks for letting us know. We\'re sorry you can\'t make it!',
                'rsvp_status' => $invitation->rsvp_status,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error submitting RSVP', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to submit RSVP'], 500);
        }
    }
}
