<?php

namespace App\Services;

use App\Models\EmailNotification;
use App\Models\RegistrationContact;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventRegistrationService
{
    public const EXPORT_LIMIT = 10000;

    public const FILTERS = ['all', 'duplicates', 'returning', 'previous_edition', 'hidden'];

    private const RETRYABLE_DRIVER_CODES = [1205, 1213];

    public static function emailKey(string $email): string
    {
        return mb_strtolower(trim((string) EmailNotification::scrubEmail($email)));
    }

    public static function nameKey(string $name): string
    {
        return mb_substr((string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name)), 0, 120);
    }

    public static function phoneKey(?string $storedPhone): ?string
    {
        return $storedPhone === null ? null : SmsService::toE164($storedPhone);
    }

    public function register(RegistrationEvent $event, array $clean): array
    {
        try {
            return $this->attemptRegister($event, $clean);
        } catch (QueryException $e) {
            if (!in_array((int) ($e->errorInfo[1] ?? 0), self::RETRYABLE_DRIVER_CODES, true)) {
                throw new \RuntimeException('Event registration could not be saved (SQLSTATE ' . ($e->errorInfo[0] ?? '?') . ').');
            }
        }

        try {
            return $this->attemptRegister($event, $clean);
        } catch (QueryException $e) {
            throw new \RuntimeException('Event registration could not be saved (SQLSTATE ' . ($e->errorInfo[0] ?? '?') . ').');
        }
    }

    public function findBySubmissionKey(RegistrationEvent $event, string $submissionKey): ?RegistrationEntry
    {
        return RegistrationEntry::query()
            ->with('contact')
            ->where('registration_event_id', $event->id)
            ->where('submission_key', $submissionKey)
            ->first();
    }

    public function counts(RegistrationEvent $event): array
    {
        $previousId = $this->livePreviousEditionId($event);
        [$retSql, $retBindings] = $this->registeredBeforeSql('e');
        [$prevSql, $prevBindings] = $previousId !== null ? $this->previousEditionSql('e', $previousId) : ['0 = 1', []];

        $main = DB::table('registration_entries as e')
            ->where('e.registration_event_id', $event->id)
            ->whereNull('e.hidden_at')
            ->selectRaw('COUNT(*) AS total_entries')
            ->selectRaw('COUNT(DISTINCT e.registration_contact_id) AS unique_registrations')
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$retSql} THEN e.registration_contact_id END) AS returning_contacts", $retBindings)
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$prevSql} THEN e.registration_contact_id END) AS previous_edition_registrants", $prevBindings)
            ->first();

        $groups = DB::query()
            ->fromSub(
                DB::table('registration_entries')
                    ->where('registration_event_id', $event->id)
                    ->whereNull('hidden_at')
                    ->groupBy('registration_contact_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->selectRaw('registration_contact_id, COUNT(*) AS entry_count'),
                'd'
            )
            ->selectRaw('COUNT(*) AS duplicate_groups, COALESCE(SUM(d.entry_count), 0) AS entries_in_duplicate_groups')
            ->first();

        $hidden = DB::table('registration_entries')
            ->where('registration_event_id', $event->id)
            ->whereNotNull('hidden_at')
            ->count();

        return [
            'total_entries' => (int) ($main->total_entries ?? 0),
            'unique_registrations' => (int) ($main->unique_registrations ?? 0),
            'duplicate_groups' => (int) ($groups->duplicate_groups ?? 0),
            'entries_in_duplicate_groups' => (int) ($groups->entries_in_duplicate_groups ?? 0),
            'returning_contacts' => (int) ($main->returning_contacts ?? 0),
            'previous_edition_registrants' => (int) ($main->previous_edition_registrants ?? 0),
            'hidden_entries' => (int) $hidden,
        ];
    }

    public function listCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return DB::table('registration_entries')
            ->whereIn('registration_event_id', $eventIds)
            ->whereNull('hidden_at')
            ->groupBy('registration_event_id')
            ->selectRaw('registration_event_id, COUNT(*) AS total_entries, COUNT(DISTINCT registration_contact_id) AS unique_registrations')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->registration_event_id => [
                'total_entries' => (int) $row->total_entries,
                'unique_registrations' => (int) $row->unique_registrations,
            ]])
            ->all();
    }

    public function groups(RegistrationEvent $event, ?string $search, string $filter, int $page, int $perPage): array
    {
        $previousId = $this->livePreviousEditionId($event);
        $hiddenOnly = $filter === 'hidden';

        $base = DB::table('registration_entries as e')
            ->where('e.registration_event_id', $event->id)
            ->when($hiddenOnly, fn ($q) => $q->whereNotNull('e.hidden_at'), fn ($q) => $q->whereNull('e.hidden_at'))
            ->groupBy('e.registration_contact_id')
            ->select('e.registration_contact_id')
            ->selectRaw('COUNT(*) AS entry_count, MIN(e.id) AS first_id, MAX(e.id) AS last_id');

        if ($filter === 'duplicates') {
            $base->havingRaw('COUNT(*) > 1');
        } elseif ($filter === 'returning') {
            $this->whereGroupHas($base, $event, $this->registeredBeforeSql('r'));
        } elseif ($filter === 'previous_edition') {
            if ($previousId === null) {
                $base->whereRaw('0 = 1');
            } else {
                $this->whereGroupHas($base, $event, $this->previousEditionSql('r', $previousId));
            }
        }

        $this->applySearch($base, $event, $search, $hiddenOnly);

        $total = (int) DB::query()->fromSub($base, 'g')->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $rows = (clone $base)
            ->orderByDesc('last_id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'groups' => $this->presentGroups($event, $rows, $hiddenOnly, $previousId),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function export(RegistrationEvent $event): array
    {
        $previousId = $this->livePreviousEditionId($event);
        [$retSql, $retBindings] = $this->registeredBeforeSql('e');
        [$prevSql, $prevBindings] = $previousId !== null ? $this->previousEditionSql('e', $previousId) : ['0 = 1', []];

        $sizes = DB::table('registration_entries')
            ->where('registration_event_id', $event->id)
            ->whereNull('hidden_at')
            ->groupBy('registration_contact_id')
            ->selectRaw('registration_contact_id, COUNT(*) AS entry_count');

        $rows = DB::table('registration_entries as e')
            ->joinSub($sizes, 'd', 'd.registration_contact_id', '=', 'e.registration_contact_id')
            ->where('e.registration_event_id', $event->id)
            ->whereNull('e.hidden_at')
            ->orderBy('e.id')
            ->limit(self::EXPORT_LIMIT + 1)
            ->select('e.id', 'e.created_at', 'e.name', 'e.email', 'e.phone', 'e.registration_contact_id', 'd.entry_count')
            ->selectRaw("({$retSql}) AS is_registered_before", $retBindings)
            ->selectRaw("({$prevSql}) AS is_previous_edition", $prevBindings)
            ->get();

        $capped = $rows->count() > self::EXPORT_LIMIT;
        $total = (int) DB::table('registration_entries')
            ->where('registration_event_id', $event->id)
            ->whereNull('hidden_at')
            ->count();

        return [
            'rows' => $rows->take(self::EXPORT_LIMIT)->map(fn ($row) => [
                'entry_id' => (int) $row->id,
                'registered_at' => $this->iso($row->created_at),
                'name' => $row->name,
                'email' => $row->email,
                'phone' => $row->phone,
                'contact_id' => (int) $row->registration_contact_id,
                'same_contact_entries' => (int) $row->entry_count,
                'is_duplicate' => (int) $row->entry_count > 1,
                'returning' => (bool) $row->is_registered_before,
                'registered_previous_edition' => (bool) $row->is_previous_edition,
            ])->values()->all(),
            'total' => $total,
            'capped' => $capped,
        ];
    }

    public function history(RegistrationEvent $event, RegistrationContact $contact, array $visibleLocationIds): ?array
    {
        $latest = RegistrationEntry::query()
            ->where('registration_event_id', $event->id)
            ->where('registration_contact_id', $contact->id)
            ->whereNull('hidden_at')
            ->orderByDesc('id')
            ->first(['id', 'email', 'phone']);

        if ($latest === null) {
            return null;
        }

        $previousId = $this->livePreviousEditionId($event);

        $rows = DB::table('registration_entries as e')
            ->join('registration_events as ev', function ($join) {
                $join->on('ev.id', '=', 'e.registration_event_id')->whereNull('ev.deleted_at');
            })
            ->leftJoin('locations as l', 'l.id', '=', 'ev.location_id')
            ->where('e.registration_contact_id', $contact->id)
            ->where('ev.company_id', $event->company_id)
            ->whereNull('e.hidden_at')
            ->orderByDesc('e.id')
            ->select('e.id', 'e.registration_event_id', 'e.name', 'e.created_at', 'ev.name as event_name', 'ev.event_date', 'ev.location_id', 'l.name as location_name')
            ->get();

        $visible = array_map('intval', $visibleLocationIds);
        $shown = $rows->filter(fn ($row) => in_array((int) $row->location_id, $visible, true))->values();
        $times = $shown->pluck('created_at')->filter()->sort()->values();

        return [
            'contact' => [
                'id' => (int) $contact->id,
                'email' => $latest->email,
                'phone' => $latest->phone,
                'first_registered_at' => $times->isEmpty() ? null : $this->iso($times->first()),
                'last_registered_at' => $times->isEmpty() ? null : $this->iso($times->last()),
            ],
            'history' => $shown->map(fn ($row) => [
                'entry_id' => (int) $row->id,
                'event_id' => (int) $row->registration_event_id,
                'event_name' => $row->event_name,
                'event_date' => substr((string) $row->event_date, 0, 10),
                'location_name' => $row->location_name,
                'registered_at' => $this->iso($row->created_at),
                'name' => $row->name,
                'is_current_event' => (int) $row->registration_event_id === (int) $event->id,
                'is_previous_edition' => $previousId !== null && (int) $row->registration_event_id === $previousId,
            ])->all(),
            'other_location_registrations' => $rows
                ->reject(fn ($row) => in_array((int) $row->location_id, $visible, true))
                ->pluck('registration_event_id')
                ->unique()
                ->count(),
        ];
    }

    public function livePreviousEditionId(RegistrationEvent $event): ?int
    {
        if ($event->previous_edition_id === null) {
            return null;
        }

        $exists = RegistrationEvent::query()
            ->whereKey($event->previous_edition_id)
            ->where('company_id', $event->company_id)
            ->exists();

        return $exists ? (int) $event->previous_edition_id : null;
    }

    private function attemptRegister(RegistrationEvent $event, array $clean): array
    {
        RegistrationContact::query()->upsert(
            [[
                'company_id' => $event->company_id,
                'email_key' => $clean['email_key'],
                'phone_key' => $clean['phone_key'],
            ]],
            ['company_id', 'email_key', 'phone_key'],
            ['updated_at']
        );

        $contactId = RegistrationContact::query()
            ->where('company_id', $event->company_id)
            ->where('email_key', $clean['email_key'])
            ->where('phone_key', $clean['phone_key'])
            ->value('id');

        if ($contactId === null) {
            throw new \RuntimeException('Event registration contact could not be resolved.');
        }

        try {
            $entry = RegistrationEntry::create([
                'registration_event_id' => $event->id,
                'registration_contact_id' => (int) $contactId,
                'company_id' => $event->company_id,
                'location_id' => $event->location_id,
                'name' => $clean['name'],
                'name_key' => $clean['name_key'],
                'email' => $clean['email'],
                'phone' => $clean['phone'],
                'submission_key' => $clean['submission_key'],
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = RegistrationEntry::query()
                ->with('contact')
                ->where('registration_event_id', $event->id)
                ->where('submission_key', $clean['submission_key'])
                ->sharedLock()
                ->first();

            if ($existing === null) {
                throw new \RuntimeException('Event registration replay could not be resolved.');
            }

            return ['entry' => $existing, 'replayed' => true];
        }

        return ['entry' => $entry, 'replayed' => false];
    }

    private function registeredBeforeSql(string $alias): array
    {
        return [
            "EXISTS (SELECT 1 FROM registration_entries o"
            . " JOIN registration_events ox ON ox.id = o.registration_event_id AND ox.deleted_at IS NULL"
            . " WHERE o.registration_contact_id = {$alias}.registration_contact_id"
            . " AND o.name_key = {$alias}.name_key"
            . " AND o.registration_event_id <> {$alias}.registration_event_id"
            . " AND o.hidden_at IS NULL"
            . " AND o.id < {$alias}.id"
            . " AND ox.event_date < DATE({$alias}.created_at))",
            [],
        ];
    }

    private function earlierAnyNameSql(string $alias): array
    {
        return [
            "EXISTS (SELECT 1 FROM registration_entries o"
            . " JOIN registration_events ox ON ox.id = o.registration_event_id AND ox.deleted_at IS NULL"
            . " WHERE o.registration_contact_id = {$alias}.registration_contact_id"
            . " AND o.registration_event_id <> {$alias}.registration_event_id"
            . " AND o.hidden_at IS NULL"
            . " AND o.id < {$alias}.id"
            . " AND ox.event_date < DATE({$alias}.created_at))",
            [],
        ];
    }

    private function previousEditionSql(string $alias, int $previousId): array
    {
        return [
            "EXISTS (SELECT 1 FROM registration_entries p"
            . " WHERE p.registration_event_id = ?"
            . " AND p.registration_contact_id = {$alias}.registration_contact_id"
            . " AND p.name_key = {$alias}.name_key"
            . " AND p.hidden_at IS NULL)",
            [$previousId],
        ];
    }

    private function whereGroupHas(Builder $base, RegistrationEvent $event, array $predicate): void
    {
        [$sql, $bindings] = $predicate;

        $base->whereExists(function ($query) use ($event, $sql, $bindings) {
            $query->selectRaw('1')
                ->from('registration_entries as r')
                ->whereColumn('r.registration_contact_id', 'e.registration_contact_id')
                ->where('r.registration_event_id', $event->id)
                ->whereNull('r.hidden_at')
                ->whereRaw($sql, $bindings);
        });
    }

    private function applySearch(Builder $base, RegistrationEvent $event, ?string $search, bool $hiddenOnly): void
    {
        $term = trim((string) $search);
        if ($term === '') {
            return;
        }

        $like = '%' . addcslashes($term, '\\%_') . '%';
        $digits = (string) preg_replace('/\D+/', '', $term);

        $base->where(function ($where) use ($event, $like, $digits, $hiddenOnly) {
            $where->whereExists(function ($query) use ($event, $like, $hiddenOnly) {
                $query->selectRaw('1')
                    ->from('registration_entries as s')
                    ->whereColumn('s.registration_contact_id', 'e.registration_contact_id')
                    ->where('s.registration_event_id', $event->id)
                    ->when($hiddenOnly, fn ($q) => $q->whereNotNull('s.hidden_at'), fn ($q) => $q->whereNull('s.hidden_at'))
                    ->where(function ($match) use ($like) {
                        $match->where('s.name', 'like', $like)->orWhere('s.email', 'like', $like);
                    });
            });

            if (strlen($digits) >= 3) {
                $where->orWhereExists(function ($query) use ($digits) {
                    $query->selectRaw('1')
                        ->from('registration_contacts as c')
                        ->whereColumn('c.id', 'e.registration_contact_id')
                        ->where('c.phone_key', 'like', '%' . $digits . '%');
                });
            }
        });
    }

    private function presentGroups(RegistrationEvent $event, Collection $rows, bool $hiddenOnly, ?int $previousId): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $contactIds = $rows->pluck('registration_contact_id')->map(fn ($id) => (int) $id)->all();
        [$retSql, $retBindings] = $hiddenOnly ? ['0 = 1', []] : $this->registeredBeforeSql('e');
        [$prevSql, $prevBindings] = (!$hiddenOnly && $previousId !== null) ? $this->previousEditionSql('e', $previousId) : ['0 = 1', []];

        $entries = DB::table('registration_entries as e')
            ->where('e.registration_event_id', $event->id)
            ->when($hiddenOnly, fn ($q) => $q->whereNotNull('e.hidden_at'), fn ($q) => $q->whereNull('e.hidden_at'))
            ->whereIn('e.registration_contact_id', $contactIds)
            ->orderBy('e.id')
            ->select('e.id', 'e.registration_contact_id', 'e.name', 'e.email', 'e.phone', 'e.created_at', 'e.hidden_at')
            ->selectRaw("({$retSql}) AS is_registered_before", $retBindings)
            ->selectRaw("({$prevSql}) AS is_previous_edition", $prevBindings)
            ->get()
            ->groupBy(fn ($entry) => (int) $entry->registration_contact_id);

        $otherEvents = DB::table('registration_entries as o')
            ->join('registration_events as ox', function ($join) {
                $join->on('ox.id', '=', 'o.registration_event_id')->whereNull('ox.deleted_at');
            })
            ->whereIn('o.registration_contact_id', $contactIds)
            ->where('o.registration_event_id', '<>', $event->id)
            ->whereNull('o.hidden_at')
            ->groupBy('o.registration_contact_id')
            ->selectRaw('o.registration_contact_id, COUNT(DISTINCT o.registration_event_id) AS event_count')
            ->pluck('event_count', 'registration_contact_id')
            ->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count]);

        $earlierAnyName = [];
        if (!$hiddenOnly) {
            [$anySql, $anyBindings] = $this->earlierAnyNameSql('e');
            $earlierAnyName = DB::table('registration_entries as e')
                ->whereIn('e.id', $rows->pluck('first_id')->map(fn ($id) => (int) $id)->all())
                ->whereRaw($anySql, $anyBindings)
                ->pluck('e.registration_contact_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $rows->map(function ($row) use ($entries, $otherEvents, $earlierAnyName, $hiddenOnly) {
            $contactId = (int) $row->registration_contact_id;
            $list = collect($entries->get($contactId, []))->values();
            $latest = $list->last();

            $presented = $list->map(fn ($entry) => [
                'id' => (int) $entry->id,
                'name' => $entry->name,
                'email' => $entry->email,
                'phone' => $entry->phone,
                'registered_at' => $this->iso($entry->created_at),
                'returning' => (bool) $entry->is_registered_before,
                'registered_previous_edition' => (bool) $entry->is_previous_edition,
                'hidden_at' => $entry->hidden_at !== null ? $this->iso($entry->hidden_at) : null,
            ])->values();

            $returning = $presented->contains(fn ($entry) => $entry['returning']);

            return [
                'contact_id' => $contactId,
                'entry_count' => (int) $row->entry_count,
                'is_duplicate' => !$hiddenOnly && (int) $row->entry_count > 1,
                'first_registered_at' => $presented->isEmpty() ? null : $presented->first()['registered_at'],
                'last_registered_at' => $presented->isEmpty() ? null : $presented->last()['registered_at'],
                'email' => $latest?->email,
                'phone' => $latest?->phone,
                'names' => $presented->pluck('name')->unique()->values()->all(),
                'returning' => $returning,
                'registered_previous_edition' => $presented->contains(fn ($entry) => $entry['registered_previous_edition']),
                'registered_before_other_name' => !$returning && in_array($contactId, $earlierAnyName, true),
                'other_event_count' => $otherEvents->get($contactId, 0),
                'entries' => $presented->all(),
            ];
        })->values()->all();
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'))->toIso8601String();
    }
}
