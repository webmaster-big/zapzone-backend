<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PartyInvitation;
use App\Models\Booking;
use App\Models\BookingInvitation;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BookingInvitationController extends Controller
{
    protected InvitationService $invitationService;

    public function __construct()
    {
        $this->invitationService = new InvitationService();
    }

    /**
     * Verify the authenticated customer owns the booking.
     * Matches by customer_id OR by guest_email (for guest-checkout bookings).
     */
    private function customerOwnsBooking($customer, Booking $booking): bool
    {
        if (!$customer) {
            return false;
        }

        // Direct customer_id match
        if ($booking->customer_id && $booking->customer_id === $customer->id) {
            return true;
        }

        // Guest-checkout bookings: match by email
        if ($booking->guest_email && $booking->guest_email === $customer->email) {
            return true;
        }

        return false;
    }

    /**
     * List all invitations for a booking with summary stats.
     */
    public function index(Request $request, Booking $booking): JsonResponse
    {
        try {
            // Verify ownership: the authenticated customer must own this booking
            $customer = $request->user();
            if (!$this->customerOwnsBooking($customer, $booking)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $invitations = BookingInvitation::where('booking_id', $booking->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $summary = BookingInvitation::getSummaryForBooking(
                $booking->id,
                $booking->participants ?? 0
            );

            return response()->json([
                'invitations' => $invitations,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching invitations', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to fetch invitations'], 500);
        }
    }

    /**
     * Send invitations for a booking.
     * Accepts an array of guests to invite.
     */
    public function store(Request $request, Booking $booking): JsonResponse
    {
        try {
            // Verify ownership
            $customer = $request->user();
            if (!$this->customerOwnsBooking($customer, $booking)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'guests' => 'required|array|min:1|max:50',
                'guests.*.name' => 'required|string|max:255',
                'guests.*.email' => 'nullable|email|max:255',
                'guests.*.phone' => 'nullable|string|max:20',
                'guests.*.send_via' => ['required', Rule::in(['email', 'text', 'both'])],
            ]);

            // Validate that each guest has at least one contact method based on send_via
            foreach ($validated['guests'] as $i => $guest) {
                if (in_array($guest['send_via'], ['email', 'both']) && empty($guest['email'])) {
                    return response()->json([
                        'message' => "Guest #{$i} requires an email address for email delivery.",
                        'errors' => ["guests.{$i}.email" => ['Email required for email delivery']],
                    ], 422);
                }
                if (in_array($guest['send_via'], ['text', 'both']) && empty($guest['phone'])) {
                    return response()->json([
                        'message' => "Guest #{$i} requires a phone number for text delivery.",
                        'errors' => ["guests.{$i}.phone" => ['Phone required for text delivery']],
                    ], 422);
                }
            }

            // Check participant capacity
            $existingCount = BookingInvitation::where('booking_id', $booking->id)->count();
            $maxParticipants = $booking->participants ?? 0;
            $newCount = count($validated['guests']);

            if ($maxParticipants > 0 && ($existingCount + $newCount) > $maxParticipants) {
                return response()->json([
                    'message' => "Cannot send {$newCount} invitation(s). Maximum {$maxParticipants} guests allowed, {$existingCount} already invited.",
                ], 422);
            }

            $results = [];
            $booking->load(['customer', 'package', 'location']);

            foreach ($validated['guests'] as $guest) {
                // Check for duplicate email
                if (!empty($guest['email'])) {
                    $existing = BookingInvitation::where('booking_id', $booking->id)
                        ->where('guest_email', $guest['email'])
                        ->first();

                    if ($existing) {
                        $results[] = [
                            'guest_name' => $guest['name'],
                            'status' => 'skipped',
                            'reason' => 'Already invited',
                        ];
                        continue;
                    }
                }

                // Create invitation record
                $invitation = BookingInvitation::create([
                    'booking_id' => $booking->id,
                    'guest_name' => $guest['name'],
                    'guest_email' => $guest['email'] ?? null,
                    'guest_phone' => $guest['phone'] ?? null,
                    'send_via' => $guest['send_via'],
                    'rsvp_status' => 'pending',
                ]);

                // Send the invitation
                $sendResult = $this->invitationService->sendInvitation($invitation);

                $results[] = [
                    'id' => $invitation->id,
                    'guest_name' => $guest['name'],
                    'status' => 'sent',
                    'delivery' => $sendResult,
                ];
            }

            // Refresh summary
            $summary = BookingInvitation::getSummaryForBooking(
                $booking->id,
                $booking->participants ?? 0
            );

            return response()->json([
                'message' => 'Invitations processed successfully',
                'results' => $results,
                'summary' => $summary,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error sending invitations', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Failed to send invitations'], 500);
        }
    }

    /**
     * Resend a specific invitation.
     */
    public function resend(Request $request, Booking $booking, BookingInvitation $invitation): JsonResponse
    {
        try {
            // Verify ownership
            $customer = $request->user();
            if (!$this->customerOwnsBooking($customer, $booking)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Verify invitation belongs to booking
            if ($invitation->booking_id !== $booking->id) {
                return response()->json(['message' => 'Invitation not found for this booking'], 404);
            }

            $result = $this->invitationService->sendInvitation($invitation);

            return response()->json([
                'message' => 'Invitation resent successfully',
                'delivery' => $result,
                'invitation' => $invitation->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error resending invitation', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to resend invitation'], 500);
        }
    }

    /**
     * Delete/cancel a pending invitation.
     */
    public function destroy(Request $request, Booking $booking, BookingInvitation $invitation): JsonResponse
    {
        try {
            // Verify ownership
            $customer = $request->user();
            if (!$this->customerOwnsBooking($customer, $booking)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Verify invitation belongs to booking
            if ($invitation->booking_id !== $booking->id) {
                return response()->json(['message' => 'Invitation not found for this booking'], 404);
            }

            $invitation->delete();

            // Refresh summary
            $summary = BookingInvitation::getSummaryForBooking(
                $booking->id,
                $booking->participants ?? 0
            );

            return response()->json([
                'message' => 'Invitation cancelled',
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting invitation', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to cancel invitation'], 500);
        }
    }

    /**
     * Preview the invitation email for a booking (returns HTML).
     */
    public function preview(Request $request, Booking $booking): JsonResponse
    {
        try {
            // Verify ownership
            $customer = $request->user();
            if (!$this->customerOwnsBooking($customer, $booking)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $booking->load(['customer', 'package', 'location']);

            // Create a dummy invitation for preview
            $dummyInvitation = new BookingInvitation([
                'guest_name' => 'Sample Guest',
                'guest_email' => 'guest@example.com',
                'rsvp_token' => 'preview-token',
            ]);
            $dummyInvitation->booking_id = $booking->id;
            $dummyInvitation->setRelation('booking', $booking);

            // Build variables via reflection (method is protected)
            $service = new InvitationService();
            $reflection = new \ReflectionClass($service);
            $buildVars = $reflection->getMethod('buildInvitationVariables');
            $buildVars->setAccessible(true);
            $variables = $buildVars->invoke($service, $dummyInvitation, $booking);

            // Use the same Mailable as actual emails for accurate preview
            $mailable = new PartyInvitation($booking, $dummyInvitation, $variables);
            $mailable->build();

            $html = $mailable->render();
            $subject = $mailable->subject;

            return response()->json([
                'subject' => $subject,
                'html' => $html,
                'has_invitation_file' => !empty($booking->package?->invitation_file),
                'has_invitation_download_link' => !empty($booking->package?->invitation_download_link),
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating invitation preview', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to generate preview'], 500);
        }
    }
}
