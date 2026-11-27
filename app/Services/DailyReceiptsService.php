<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Payment;
use App\Support\DateRange;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyReceiptsService
{
    public const ONLINE = 'online';
    public const IN_STORE = 'in_store';
    public const UNCLASSIFIED = 'unclassified';

    public const COLUMNS = [self::ONLINE, self::IN_STORE, self::UNCLASSIFIED];

    private const ITEM_TYPES = [
        Payment::TYPE_BOOKING => 'Package',
        Payment::TYPE_ATTRACTION_PURCHASE => 'Attraction',
        Payment::TYPE_EVENT_PURCHASE => 'Event',
        Payment::TYPE_GIFT_CARD => 'Gift card',
    ];

    public function daily(?array $locationIds, string $startDate, string $endDate, string $groupBy = 'location'): array
    {
        $businessTz = DateRange::businessTimezone();
        $events = $this->events(
            $locationIds,
            Carbon::parse($startDate, $businessTz)->startOfDay()->subDay(),
            Carbon::parse($endDate, $businessTz)->endOfDay()->addDay(),
        )->filter(fn (array $event) => $event['date'] >= $startDate && $event['date'] <= $endDate)->values();

        $lines = $groupBy === 'item' ? $this->itemLines($events) : $this->locationLines($events);
        $days = [];

        foreach ($lines->groupBy('date')->sortKeysDesc() as $date => $dayLines) {
            $rows = $dayLines->groupBy('key')->map(function (Collection $group) {
                $first = $group->first();

                return $this->money([
                    'key' => $first['key'],
                    'label' => $first['label'],
                    'type' => $first['type'] ?? null,
                ] + $this->sumColumns($group));
            })->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

            $days[] = [
                'date' => $date,
                'rows' => $rows,
                'totals' => $this->money($this->sumColumns($dayLines)),
            ];
        }

        $totals = $this->sumColumns($lines);

        return [
            'group_by' => $groupBy === 'item' ? 'item' : 'location',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'staff_card_column' => $this->staffCardColumn(),
            'has_unclassified' => $lines->contains(fn (array $line) => $line[self::UNCLASSIFIED] !== 0),
            'days' => $days,
            'totals' => $this->money($totals),
        ];
    }

    public function totals(?array $locationIds, ?Carbon $from, ?Carbon $to): array
    {
        $events = $this->events($locationIds, $from, $to)
            ->filter(fn (array $event) => ($from === null || $event['posted_at']->gte($from)) && ($to === null || $event['posted_at']->lte($to)));

        return $this->money($this->sumColumns($events->map(fn (array $event) => $this->spread($event))));
    }

    public function totalsByLocation(?array $locationIds, ?Carbon $from, ?Carbon $to): array
    {
        return $this->events($locationIds, $from, $to)
            ->filter(fn (array $event) => ($from === null || $event['posted_at']->gte($from)) && ($to === null || $event['posted_at']->lte($to)))
            ->groupBy(fn (array $event) => (int) ($event['location_id'] ?? 0))
            ->map(fn (Collection $events) => $this->money($this->sumColumns($events->map(fn (array $event) => $this->spread($event)))))
            ->all();
    }

    public static function window(mixed $from, mixed $to): array
    {
        $businessTz = DateRange::businessTimezone();
        $start = $from === null ? null : ($from instanceof Carbon ? $from->copy() : Carbon::parse($from, $businessTz)->startOfDay());
        $end = $to === null ? null : ($to instanceof Carbon ? $to->copy() : Carbon::parse($to, $businessTz)->endOfDay());

        return [$start, $end];
    }

    public function events(?array $locationIds, ?Carbon $from, ?Carbon $to): Collection
    {
        $storageTz = DateRange::storageTimezone();
        $window = $from && $to ? [$from->copy()->setTimezone($storageTz), $to->copy()->setTimezone($storageTz)] : null;

        $rows = DB::table('payments')
            ->whereNull('deleted_at')
            ->whereIn('status', ['completed', 'refunded', 'voided'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->when($window, fn ($query) => $query->where(function ($inner) use ($window) {
                $inner->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), $window)
                    ->orWhereBetween(DB::raw('COALESCE(refunded_at, created_at)'), $window);
            }))
            ->select([
                'id', 'location_id', 'payable_type', 'payable_id', 'amount', 'status', 'channel', 'method',
                'reverses_payment_id', 'transaction_id', 'external_reference', 'paid_at', 'refunded_at', 'created_at',
                DB::raw('LEFT(COALESCE(notes, \'\'), 24) as notes_head'),
            ])
            ->orderBy('id')
            ->get();

        $reversedInPlace = $this->capturesWithoutReversalRows($rows);
        $duplicateReceipts = $this->duplicateReceiptIds($rows);
        $timezones = $this->locationTimezones($rows->pluck('location_id')->filter()->unique()->all());
        $events = collect();

        foreach ($rows as $row) {
            if (isset($duplicateReceipts[$row->id]) || ($this->isReversalRow($row) && isset($duplicateReceipts[(int) $row->reverses_payment_id]))) {
                continue;
            }

            $cents = (int) round(((float) $row->amount) * 100);
            $column = $this->columnFor($row->channel, $row->method);
            $base = [
                'payment_id' => (int) $row->id,
                'location_id' => $row->location_id !== null ? (int) $row->location_id : null,
                'payable_type' => $row->payable_type,
                'payable_id' => $row->payable_id !== null ? (int) $row->payable_id : null,
                'column' => $column,
            ];
            $tz = $timezones[$row->location_id] ?? DateRange::businessTimezone();

            if ($this->isReversalRow($row)) {
                $events->push($this->event($base, -$cents, $row->refunded_at ?? $row->created_at, $storageTz, $tz));

                continue;
            }

            $events->push($this->event($base, $cents, $row->paid_at ?? $row->created_at, $storageTz, $tz));

            if (isset($reversedInPlace[$row->id])) {
                $events->push($this->event($base, -$cents, $row->refunded_at ?? $row->paid_at ?? $row->created_at, $storageTz, $tz));
            }
        }

        return $events;
    }

    public static function allocateCents(int $cents, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sign = $cents < 0 ? -1 : 1;
        $amount = abs($cents);
        $weights = array_map(fn ($weight) => max(0, (int) $weight), $weights);
        $total = array_sum($weights);

        if ($total === 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
            $total = count($weights);
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $exact = $amount * $weight;
            $shares[$key] = intdiv($exact, $total);
            $remainders[$key] = $exact % $total;
        }

        $left = $amount - array_sum($shares);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }

            $shares[$key]++;
            $left--;
        }

        return array_map(fn ($share) => $sign * $share, $shares);
    }

    public function columnFor(?string $channel, ?string $method = null): string
    {
        return match ($channel) {
            Payment::CHANNEL_ONLINE => self::ONLINE,
            Payment::CHANNEL_IN_STORE => self::IN_STORE,
            Payment::CHANNEL_STAFF_CARD => $this->staffCardColumn(),
            default => match (true) {
                in_array($method, ['cash', 'card', 'in-store'], true) => self::IN_STORE,
                $method === 'authorize.net' && $this->staffCardColumn() === self::ONLINE => self::ONLINE,
                default => self::UNCLASSIFIED,
            },
        };
    }

    public function staffCardColumn(): string
    {
        $configured = (string) config('accounting.staff_card_channel', self::UNCLASSIFIED);

        return in_array($configured, self::COLUMNS, true) ? $configured : self::UNCLASSIFIED;
    }

    private function isReversalRow(object $row): bool
    {
        $txn = (string) $row->transaction_id;

        if ($row->status === 'refunded') {
            return (bool) $row->reverses_payment_id || str_starts_with($row->notes_head, 'Refund from Payment #') || str_starts_with($txn, 'REFUND-');
        }

        if ($row->status === 'voided') {
            return (bool) $row->reverses_payment_id || str_starts_with($txn, 'VOID-') || str_starts_with($row->notes_head, 'Void of Payment #');
        }

        return false;
    }

    private function capturesWithoutReversalRows(Collection $rows): array
    {
        $candidates = $rows->filter(fn ($row) => in_array($row->status, ['refunded', 'voided'], true) && !$this->isReversalRow($row));

        if ($candidates->isEmpty()) {
            return [];
        }

        $ids = $candidates->pluck('id')->all();
        $voidTxns = $candidates->pluck('transaction_id')->filter()->map(fn ($txn) => 'VOID-' . $txn)->all();
        $covered = DB::table('payments')
            ->whereNull('deleted_at')
            ->whereIn('status', ['refunded', 'voided'])
            ->where(fn ($query) => $query->whereIn('reverses_payment_id', $ids)->when($voidTxns !== [], fn ($q) => $q->orWhereIn('transaction_id', $voidTxns)))
            ->get(['reverses_payment_id', 'transaction_id']);
        $coveredIds = array_flip($covered->pluck('reverses_payment_id')->filter()->map(fn ($id) => (int) $id)->all());
        $coveredTxns = array_flip($covered->pluck('transaction_id')->filter()->map(fn ($txn) => (string) $txn)->all());
        $open = [];

        foreach ($candidates as $row) {
            if (!isset($coveredIds[(int) $row->id]) && !isset($coveredTxns['VOID-' . $row->transaction_id])) {
                $open[$row->id] = true;
            }
        }

        return $open;
    }

    private function duplicateReceiptIds(Collection $rows): array
    {
        $knownIds = array_flip($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $reversedElsewhere = $rows->filter(fn ($row) => $this->isReversalRow($row) && $row->reverses_payment_id && !isset($knownIds[(int) $row->reverses_payment_id]))
            ->pluck('reverses_payment_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $originals = $reversedElsewhere === [] ? collect() : DB::table('payments')
            ->whereIn('id', $reversedElsewhere)
            ->get(['id', 'location_id', 'payable_type', 'payable_id', 'channel', 'external_reference', 'reverses_payment_id', 'status', 'transaction_id', DB::raw('LEFT(COALESCE(notes, \'\'), 24) as notes_head')]);
        $receipts = $rows->merge($originals)->filter(fn ($row) => $row->channel === Payment::CHANNEL_IN_STORE && $row->external_reference !== null && $row->external_reference !== '' && !$this->isReversalRow($row));

        if ($receipts->isEmpty()) {
            return [];
        }

        $firstIds = DB::table('payments')
            ->whereNull('deleted_at')
            ->whereIn('status', ['completed', 'refunded', 'voided'])
            ->where('channel', Payment::CHANNEL_IN_STORE)
            ->whereIn('external_reference', $receipts->pluck('external_reference')->unique()->all())
            ->selectRaw('MIN(id) as first_id, location_id, external_reference, payable_type, payable_id')
            ->groupBy('location_id', 'external_reference', 'payable_type', 'payable_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$this->receiptKey($row) => (int) $row->first_id]);
        $duplicates = [];

        foreach ($receipts as $row) {
            if (($firstIds[$this->receiptKey($row)] ?? (int) $row->id) !== (int) $row->id) {
                $duplicates[$row->id] = true;
            }
        }

        return $duplicates;
    }

    private function receiptKey(object $row): string
    {
        return implode('|', [(int) $row->location_id, mb_strtolower(trim((string) $row->external_reference)), $row->payable_type, (int) $row->payable_id]);
    }

    private function locationTimezones(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return Location::query()->whereIn('id', $locationIds)->pluck('timezone', 'id')
            ->map(function ($tz) {
                if (!is_string($tz) || $tz === '') {
                    return DateRange::businessTimezone();
                }

                try {
                    new \DateTimeZone($tz);

                    return $tz;
                } catch (\Throwable) {
                    return DateRange::businessTimezone();
                }
            })
            ->all();
    }

    private function event(array $base, int $cents, mixed $postedAt, string $storageTz, string $localTz): array
    {
        $posted = Carbon::parse($postedAt, $storageTz);

        return $base + [
            'cents' => $cents,
            'posted_at' => $posted,
            'date' => $posted->copy()->setTimezone($localTz)->toDateString(),
        ];
    }

    private function spread(array $event): array
    {
        $line = array_fill_keys(self::COLUMNS, 0);
        $line[$event['column']] = $event['cents'];

        return $line;
    }

    private function locationLines(Collection $events): Collection
    {
        $names = Location::query()->whereIn('id', $events->pluck('location_id')->filter()->unique()->all())->pluck('name', 'id');

        return $events->map(fn (array $event) => [
            'date' => $event['date'],
            'key' => 'location:' . ($event['location_id'] ?? 0),
            'label' => $names[$event['location_id']] ?? 'No location',
        ] + $this->spread($event));
    }

    private function itemLines(Collection $events): Collection
    {
        $labels = $this->itemLabels($events);
        $orderLines = $this->ticketOrderLines($events);
        $lines = collect();

        foreach ($events as $event) {
            if ($event['payable_type'] === Payment::TYPE_TICKET_ORDER && !empty($orderLines[$event['payable_id']])) {
                $parts = $orderLines[$event['payable_id']];
                $shares = self::allocateCents($event['cents'], array_map(fn ($part) => $part['weight'], $parts));

                foreach ($parts as $index => $part) {
                    $lines->push($this->itemLine($event, $part['type'], $part['name'], $shares[$index]));
                }

                continue;
            }

            [$type, $name] = $labels[$event['payable_type'] . ':' . $event['payable_id']] ?? [self::ITEM_TYPES[$event['payable_type']] ?? 'Other', self::ITEM_TYPES[$event['payable_type']] ?? 'Other'];
            $lines->push($this->itemLine($event, $type, $name, $event['cents']));
        }

        return $lines;
    }

    private function itemLine(array $event, string $type, string $name, int $cents): array
    {
        $line = array_fill_keys(self::COLUMNS, 0);
        $line[$event['column']] = $cents;

        return [
            'date' => $event['date'],
            'key' => 'item:' . mb_strtolower($type . '|' . $name),
            'label' => $name,
            'type' => $type,
        ] + $line;
    }

    private function itemLabels(Collection $events): array
    {
        $ids = fn (string $type) => $events->where('payable_type', $type)->pluck('payable_id')->filter()->unique()->all();
        $labels = [];

        if ($bookingIds = $ids(Payment::TYPE_BOOKING)) {
            DB::table('bookings')
                ->leftJoin('packages', 'bookings.package_id', '=', 'packages.id')
                ->whereIn('bookings.id', $bookingIds)
                ->select('bookings.id', DB::raw("COALESCE(packages.name, NULLIF(bookings.custom_package_name, ''), 'Custom Package') as name"))
                ->get()
                ->each(function ($row) use (&$labels) {
                    $labels[Payment::TYPE_BOOKING . ':' . $row->id] = ['Package', (string) $row->name];
                });
        }

        if ($purchaseIds = $ids(Payment::TYPE_ATTRACTION_PURCHASE)) {
            DB::table('attraction_purchases')
                ->leftJoin('attractions', 'attraction_purchases.attraction_id', '=', 'attractions.id')
                ->whereIn('attraction_purchases.id', $purchaseIds)
                ->select('attraction_purchases.id', DB::raw("COALESCE(attractions.name, 'Attraction') as name"))
                ->get()
                ->each(function ($row) use (&$labels) {
                    $labels[Payment::TYPE_ATTRACTION_PURCHASE . ':' . $row->id] = ['Attraction', (string) $row->name];
                });
        }

        if ($eventIds = $ids(Payment::TYPE_EVENT_PURCHASE)) {
            DB::table('event_purchases')
                ->leftJoin('events', 'event_purchases.event_id', '=', 'events.id')
                ->whereIn('event_purchases.id', $eventIds)
                ->select('event_purchases.id', DB::raw("COALESCE(events.name, 'Event') as name"))
                ->get()
                ->each(function ($row) use (&$labels) {
                    $labels[Payment::TYPE_EVENT_PURCHASE . ':' . $row->id] = ['Event', (string) $row->name];
                });
        }

        foreach ($ids(Payment::TYPE_GIFT_CARD) as $giftCardId) {
            $labels[Payment::TYPE_GIFT_CARD . ':' . $giftCardId] = ['Gift card', 'Gift cards'];
        }

        return $labels;
    }

    private function ticketOrderLines(Collection $events): array
    {
        $orderIds = $events->where('payable_type', Payment::TYPE_TICKET_ORDER)->pluck('payable_id')->filter()->unique()->all();

        if ($orderIds === []) {
            return [];
        }

        $lines = [];

        DB::table('attraction_purchases')
            ->leftJoin('attractions', 'attraction_purchases.attraction_id', '=', 'attractions.id')
            ->whereIn('attraction_purchases.ticket_order_id', $orderIds)
            ->orderBy('attraction_purchases.id')
            ->get(['attraction_purchases.ticket_order_id', 'attraction_purchases.total_amount', DB::raw("COALESCE(attractions.name, 'Attraction') as name")])
            ->each(function ($row) use (&$lines) {
                $lines[$row->ticket_order_id][] = ['type' => 'Attraction', 'name' => (string) $row->name, 'weight' => (int) round(((float) $row->total_amount) * 100)];
            });

        DB::table('event_purchases')
            ->leftJoin('events', 'event_purchases.event_id', '=', 'events.id')
            ->whereIn('event_purchases.ticket_order_id', $orderIds)
            ->orderBy('event_purchases.id')
            ->get(['event_purchases.ticket_order_id', 'event_purchases.total_amount', DB::raw("COALESCE(events.name, 'Event') as name")])
            ->each(function ($row) use (&$lines) {
                $lines[$row->ticket_order_id][] = ['type' => 'Event', 'name' => (string) $row->name, 'weight' => (int) round(((float) $row->total_amount) * 100)];
            });

        return $lines;
    }

    private function sumColumns(Collection $lines): array
    {
        $sums = array_fill_keys(self::COLUMNS, 0);

        foreach ($lines as $line) {
            foreach (self::COLUMNS as $column) {
                $sums[$column] += (int) ($line[$column] ?? 0);
            }
        }

        return $sums;
    }

    private function money(array $line): array
    {
        $total = 0;

        foreach (self::COLUMNS as $column) {
            $total += (int) ($line[$column] ?? 0);
            $line[$column] = round(((int) ($line[$column] ?? 0)) / 100, 2);
        }

        $line['total'] = round($total / 100, 2);

        return $line;
    }
}
