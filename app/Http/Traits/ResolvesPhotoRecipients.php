<?php

namespace App\Http\Traits;

use App\Models\PhotoSession;
use App\Models\RegistrationEntry;
use App\Models\Waiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait ResolvesPhotoRecipients
{
    protected function recipientRules(): array
    {
        return [
            'waiver_ids' => ['nullable', 'array', 'max:50'],
            'waiver_ids.*' => ['integer', 'exists:waivers,id'],
            'registration_entry_ids' => ['nullable', 'array', 'max:50'],
            'registration_entry_ids.*' => ['integer'],
            'text_registrants' => ['nullable', 'boolean'],
        ];
    }

    protected function resolveRecipients(Request $request, PhotoSession $session, array $validated, bool $offerQr): array|JsonResponse
    {
        $qr = $offerQr ? ' Use the direct staff QR code instead.' : '';
        $waiverIds = array_values(array_unique(array_map('intval', $validated['waiver_ids'] ?? [])));
        $entryIds = array_values(array_unique(array_map('intval', $validated['registration_entry_ids'] ?? [])));
        $textRegistrants = (bool) ($validated['text_registrants'] ?? false);

        if ($waiverIds === [] && $entryIds === []) {
            return $this->recipientProblem(
                $offerQr
                    ? 'Select at least one guest (a waiver or an event registration), or use the direct staff QR code instead.'
                    : 'Select at least one guest (a waiver or an event registration).'
            );
        }

        if ($entryIds !== [] && !$this->registrants->available($session)) {
            return $this->recipientProblem('Event registrations are not set up yet.');
        }

        $waivers = collect();

        if ($waiverIds !== []) {
            $waiverQuery = Waiver::whereIn('id', $waiverIds)->where('status', Waiver::STATUS_COMPLETED);
            $this->applyAuthScope($waiverQuery, $request);
            $waivers = $waiverQuery->get();
        }

        $entries = $this->registrants->findForSend($session, $entryIds, $this->staffLocationIds($request));

        if ($waivers->count() !== count($waiverIds) || $entries->count() !== count($entryIds)) {
            return $this->recipientProblem('One or more of the selected guests is no longer available. Please search again.');
        }

        $reachable = $waivers->filter(fn (Waiver $waiver) => $this->deliveries->contactableChannels($waiver) !== [])->count()
            + $entries->filter(fn (RegistrationEntry $entry) => $this->deliveries->contactableChannels($entry, $textRegistrants) !== [])->count();

        if ($reachable === 0) {
            $reason = $waivers->toBase()->map(fn (Waiver $waiver) => $this->deliveries->unavailableReason($waiver))
                ->concat($entries->toBase()->map(fn (RegistrationEntry $entry) => $this->deliveries->unavailableReason($entry, $textRegistrants)))
                ->filter()
                ->first();

            return $this->recipientProblem(($reason ?? 'None of the selected guests can be contacted.') . $qr);
        }

        return [$waivers, $entries, $textRegistrants];
    }

    protected function recipientSummary(Collection $waivers, Collection $entries): string
    {
        $parts = [sprintf('%d waiver record(s)', $waivers->count())];

        if ($entries->isNotEmpty()) {
            $parts[] = sprintf('%d event registration(s)', $entries->count());
        }

        return implode(' and ', $parts);
    }

    protected function recipientProblem(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }
}
