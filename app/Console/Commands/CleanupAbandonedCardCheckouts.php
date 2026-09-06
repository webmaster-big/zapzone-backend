<?php

namespace App\Console\Commands;

use App\Http\Traits\ReversesGiftCards;
use App\Models\ActivityLog;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\EventPurchase;
use App\Models\Payment;
use App\Services\GoogleCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupAbandonedCardCheckouts extends Command
{
    use ReversesGiftCards;

    protected $signature = 'checkouts:cleanup-abandoned {--minutes=60} {--dry-run}';

    protected $description = 'Silently delete card-payment bookings and purchases whose charge never completed, so failed checkouts leave no trace';

    public function handle(): int
    {
        $minutes = max(15, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);
        $removed = 0;

        $bookings = Booking::where('status', 'pending')
            ->where('payment_method', 'authorize.net')
            ->where(fn ($q) => $q->where('payment_status', 'pending')->orWhereNull('payment_status'))
            ->where(fn ($q) => $q->where('amount_paid', 0)->orWhereNull('amount_paid'))
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($bookings as $booking) {
            if ($this->hasCompletedPayment(Payment::TYPE_BOOKING, $booking->id)) {
                continue;
            }

            if ($dryRun) {
                $this->line("would delete booking {$booking->reference_number} ({$booking->created_at})");
                $removed++;
                continue;
            }

            try {
                $gcalService = new GoogleCalendarService($booking->location_id);
                if ($gcalService->isConnected() && $booking->google_calendar_event_id) {
                    $gcalService->deleteEvent($booking);
                }
            } catch (\Throwable $e) {
                Log::warning('Google Calendar cleanup failed during abandoned checkout sweep', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->reverseGiftCardFor($booking, Payment::TYPE_BOOKING, 'abandoned_card_checkout');
            $this->logRemoval('booking', $booking->id, $booking->reference_number, $booking->location_id, $booking->created_at?->toIso8601String());
            $booking->forceDelete();
            $removed++;
        }

        $purchaseSets = [
            ['model' => AttractionPurchase::class, 'type' => Payment::TYPE_ATTRACTION_PURCHASE, 'label' => 'attraction purchase'],
            ['model' => EventPurchase::class, 'type' => Payment::TYPE_EVENT_PURCHASE, 'label' => 'event purchase'],
        ];

        foreach ($purchaseSets as $set) {
            $purchases = $set['model']::where('status', 'pending')
                ->where('payment_method', 'authorize.net')
                ->whereNull('ticket_order_id')
                ->where(fn ($q) => $q->where('amount_paid', 0)->orWhereNull('amount_paid'))
                ->where('created_at', '<', $cutoff)
                ->get();

            foreach ($purchases as $purchase) {
                if ($purchase->checked_in_at !== null) {
                    continue;
                }

                if ($this->hasCompletedPayment($set['type'], $purchase->id)) {
                    continue;
                }

                if ($dryRun) {
                    $ref = $purchase->reference_number ?? $purchase->id;
                    $this->line("would delete {$set['label']} {$ref} ({$purchase->created_at})");
                    $removed++;
                    continue;
                }

                $this->reverseGiftCardFor($purchase, $set['type'], 'abandoned_card_checkout');
                $this->logRemoval($set['label'], $purchase->id, $purchase->reference_number ?? (string) $purchase->id, $purchase->location_id ?? null, $purchase->created_at?->toIso8601String());
                $purchase->forceDelete();
                $removed++;
            }
        }

        $verb = $dryRun ? 'would remove' : 'removed';
        $this->info("Abandoned card checkouts: {$verb} {$removed}");

        return self::SUCCESS;
    }

    private function hasCompletedPayment(string $payableType, int $payableId): bool
    {
        return Payment::where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->whereIn('status', ['completed', 'refunded'])
            ->exists();
    }

    private function logRemoval(string $kind, int $id, string $reference, ?int $locationId, ?string $createdAt): void
    {
        Log::info('Abandoned card checkout removed', [
            'kind' => $kind,
            'id' => $id,
            'reference' => $reference,
            'location_id' => $locationId,
            'created_at' => $createdAt,
        ]);

        try {
            ActivityLog::log(
                action: 'Abandoned Checkout Removed',
                category: 'delete',
                description: ucfirst($kind) . " {$reference} removed after its card payment never completed",
                userId: null,
                locationId: $locationId,
                entityType: str_replace(' ', '_', $kind),
                entityId: $id,
                metadata: [
                    'reference_number' => $reference,
                    'created_at' => $createdAt,
                    'removed_at' => now()->toIso8601String(),
                    'removed_by' => 'checkouts:cleanup-abandoned',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Activity log write failed during abandoned checkout sweep', ['error' => $e->getMessage()]);
        }
    }
}
