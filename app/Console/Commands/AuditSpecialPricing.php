<?php

namespace App\Console\Commands;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Package;
use App\Models\SpecialPricing;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AuditSpecialPricing extends Command
{
    protected $signature = 'special-pricing:audit {--from= : First visit date (Y-m-d)} {--to= : Last visit date (Y-m-d)} {--location= : Location id} {--csv= : Also write the rows to this CSV file}';

    protected $description = 'Read-only: list purchases whose special pricing does not match the special for their VISIT date';

    private array $rules = [];

    public function handle(): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->toDateString() : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->toDateString() : null;
        $locationId = $this->option('location') ? (int) $this->option('location') : null;

        $this->rules = SpecialPricing::query()
            ->get(['id', 'is_active', 'discount_type', 'created_at', 'updated_at'])
            ->keyBy('id')
            ->all();

        $rows = array_merge(
            $this->attractionRows($from, $to, $locationId),
            $this->eventRows($from, $to, $locationId),
            $this->bookingRows($from, $to, $locationId)
        );

        if ($rows === []) {
            $this->info('Every purchase matches the special for its visit date.');

            return self::SUCCESS;
        }

        $headers = ['Type', 'ID', 'Reference', 'Location', 'Visit date', 'Bought', 'Stored special', 'Visit-date special', 'Difference', 'Issue'];
        $this->table($headers, $rows);

        $counts = collect($rows)->countBy(fn ($row) => $row[9]);
        foreach ($counts as $issue => $count) {
            $this->line("{$issue}: {$count}");
        }
        $this->line('Difference = visit-date special minus stored special (positive means the guest was charged too much). Nothing was changed.');
        $this->line('"Check by hand" rows involve a rule that was edited, switched off or deleted after the purchase, or a ticket price that was never stored, so the report cannot be sure.');

        if ($path = $this->option('csv')) {
            $handle = fopen($path, 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
            $this->info("Wrote {$path}");
        }

        return self::SUCCESS;
    }

    private function attractionRows(?string $from, ?string $to, ?int $locationId): array
    {
        $rows = [];
        $attractions = Attraction::query()->withoutGlobalScopes()->get(['id', 'name', 'price', 'location_id'])->keyBy('id');

        AttractionPurchase::query()
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->when($from, fn ($q) => $q->whereRaw('DATE(COALESCE(scheduled_date, purchase_date)) >= ?', [$from]))
            ->when($to, fn ($q) => $q->whereRaw('DATE(COALESCE(scheduled_date, purchase_date)) <= ?', [$to]))
            ->when($locationId, fn ($q) => $q->whereIn('attraction_id', $attractions->where('location_id', $locationId)->keys()))
            ->orderBy('id')
            ->chunkById(500, function ($purchases) use (&$rows, $attractions) {
                foreach ($purchases as $purchase) {
                    $attraction = $attractions->get($purchase->attraction_id);
                    if (! $attraction) {
                        continue;
                    }

                    $visit = $purchase->scheduled_date
                        ? ['date' => $purchase->scheduled_date->toDateString(), 'time' => $purchase->scheduled_time?->format('H:i')]
                        : $this->walkInVisit($purchase);

                    if (! $visit['date']) {
                        continue;
                    }

                    $row = $this->compare(
                        'attraction',
                        $purchase,
                        '#' . $purchase->id . ($purchase->ticket_order_id ? ' (order line)' : ''),
                        SpecialPricing::ENTITY_ATTRACTION,
                        (int) $attraction->id,
                        $attraction->location_id ? (int) $attraction->location_id : null,
                        (float) ($purchase->unit_price ?? $attraction->price),
                        $purchase->unit_price !== null,
                        max(1, (int) $purchase->quantity),
                        $visit
                    );

                    if ($row) {
                        $rows[] = $row;
                    }
                }
            });

        return $rows;
    }

    private function eventRows(?string $from, ?string $to, ?int $locationId): array
    {
        $rows = [];
        $events = Event::query()->withoutGlobalScopes()->get(['id', 'price', 'location_id'])->keyBy('id');

        EventPurchase::query()
            ->whereNotIn('status', ['cancelled'])
            ->when($from, fn ($q) => $q->whereDate('purchase_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('purchase_date', '<=', $to))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderBy('id')
            ->chunkById(500, function ($purchases) use (&$rows, $events) {
                foreach ($purchases as $purchase) {
                    $event = $events->get($purchase->event_id);
                    if (! $event || ! $purchase->purchase_date) {
                        continue;
                    }

                    $row = $this->compare(
                        'event',
                        $purchase,
                        (string) ($purchase->reference_number ?? '#' . $purchase->id),
                        SpecialPricing::ENTITY_EVENT,
                        (int) $event->id,
                        $event->location_id ? (int) $event->location_id : null,
                        (float) ($purchase->unit_price ?? $event->price),
                        $purchase->unit_price !== null,
                        max(1, (int) $purchase->quantity),
                        ['date' => $purchase->purchase_date->toDateString(), 'time' => $purchase->ticket_order_id && $purchase->purchase_time?->format('H:i') === '00:00' ? null : $purchase->purchase_time?->format('H:i')]
                    );

                    if ($row) {
                        $rows[] = $row;
                    }
                }
            });

        return $rows;
    }

    private function bookingRows(?string $from, ?string $to, ?int $locationId): array
    {
        $rows = [];
        $packages = Package::query()->withoutGlobalScopes()->get(['id', 'price', 'pricing_type', 'location_id'])->keyBy('id');

        Booking::query()
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('package_id')
            ->when($from, fn ($q) => $q->whereDate('booking_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('booking_date', '<=', $to))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderBy('id')
            ->chunkById(500, function ($bookings) use (&$rows, $packages) {
                foreach ($bookings as $booking) {
                    $package = $packages->get($booking->package_id);
                    if (! $package || ! $booking->booking_date) {
                        continue;
                    }

                    $pricingType = $booking->package_pricing_type_at_booking ?? $package->pricing_type;

                    $row = $this->compare(
                        'booking',
                        $booking,
                        (string) ($booking->reference_number ?? '#' . $booking->id),
                        SpecialPricing::ENTITY_PACKAGE,
                        (int) $package->id,
                        (int) ($booking->location_id ?? $package->location_id),
                        (float) ($booking->package_price_at_booking ?? $package->price),
                        $booking->package_price_at_booking !== null,
                        $pricingType === 'per_person' ? max(1, (int) $booking->participants) : 1,
                        ['date' => Carbon::parse($booking->booking_date)->toDateString(), 'time' => $booking->booking_time?->format('H:i')]
                    );

                    if ($row) {
                        $rows[] = $row;
                    }
                }
            });

        return $rows;
    }

    private function walkInVisit(AttractionPurchase $purchase): array
    {
        $bought = $this->local($purchase->created_at);
        $date = $purchase->purchase_date?->toDateString() ?? $bought?->toDateString();

        return [
            'date' => $date,
            'time' => $bought && $bought->toDateString() === $date ? $bought->format('H:i') : null,
        ];
    }

    private function local(mixed $at): ?Carbon
    {
        return $at ? Carbon::parse($at)->setTimezone(config('app.business_timezone', 'America/Detroit')) : null;
    }

    private function compare(string $type, $record, string $reference, string $entityType, int $entityId, ?int $locationId, float $unitPrice, bool $priceKnown, int $quantity, array $visit): ?array
    {
        $entries = array_values(array_filter((array) ($record->applied_discounts ?? []), fn ($entry) => SpecialPricing::isSpecialPricingEntry($entry)));
        $orderLine = ! empty($record->ticket_order_id);

        $stored = round(array_sum(array_map(
            fn (array $entry) => (float) ($entry['discount_amount'] ?? 0) * ($orderLine && ! array_key_exists('unit_discount_amount', $entry) ? $quantity : 1),
            $entries
        )), 2);

        $boughtAt = $record->created_at ? Carbon::parse($record->created_at) : null;

        $breakdown = SpecialPricing::getFullPriceBreakdown(
            $entityType,
            $entityId,
            $unitPrice,
            Carbon::parse($visit['date']),
            $locationId,
            $visit['time'],
            $quantity,
            $boughtAt
        );

        $expected = round((float) $breakdown['total_discount'], 2);

        if (abs($expected - $stored) <= 0.01 * $quantity + 0.001) {
            return null;
        }

        $ruleIds = collect(array_merge(
            array_column($entries, 'special_pricing_id'),
            array_column($breakdown['discounts_applied'], 'special_pricing_id')
        ))->filter()->map(fn ($id) => (int) $id)->unique();

        $ruleChanged = $ruleIds->contains(function (int $id) use ($boughtAt) {
            $rule = $this->rules[$id] ?? null;

            return ! $rule || ! $rule->is_active || ($boughtAt && $rule->updated_at && $rule->updated_at->gt($boughtAt));
        }) || ($entries !== [] && $ruleIds->isEmpty());

        $percentage = $ruleIds->contains(fn (int $id) => ($this->rules[$id]->discount_type ?? null) === SpecialPricing::DISCOUNT_PERCENTAGE);

        $issue = match (true) {
            $ruleChanged => 'rule changed since purchase, check by hand',
            $stored > 0 && $expected <= 0 => 'special given, visit is not a special day',
            $stored <= 0 && $expected > 0 => 'special missed on a special visit day',
            ! $priceKnown && $percentage => 'special amount differs, ticket price not stored, check by hand',
            default => 'special amount differs',
        };

        return [
            $type,
            $record->id,
            $reference,
            $locationId,
            $visit['date'],
            $this->local($boughtAt)?->toDateString(),
            number_format($stored, 2),
            number_format($expected, 2),
            number_format($expected - $stored, 2),
            $issue,
        ];
    }
}
