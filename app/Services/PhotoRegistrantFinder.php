<?php

namespace App\Services;

use App\Models\PhotoSession;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Support\OperatingDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PhotoRegistrantFinder
{
    public const SEARCH_LIMIT = 25;

    private const CANDIDATE_LIMIT = 200;

    public function available(PhotoSession $session): bool
    {
        return PhotoSession::supportsRegistrations() && !$session->isEscapeRoom();
    }

    public function referenceDay(PhotoSession $session): string
    {
        $session->loadMissing('location');

        return $session->operating_day?->toDateString() ?? OperatingDay::forLocation($session->location);
    }

    public function formsFor(PhotoSession $session): Collection
    {
        $day = Carbon::parse($this->referenceDay($session));
        $from = $day->copy()->subDay()->toDateString();
        $to = $day->copy()->addDay()->toDateString();

        return RegistrationEvent::query()
            ->with('event')
            ->where('company_id', $session->company_id)
            ->where('location_id', $session->location_id)
            ->where('event_date', '>=', $from)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (RegistrationEvent $form) => $form->firstDay() <= $to)
            ->keyBy('id');
    }

    public function search(PhotoSession $session, string $term, array $staffLocationIds): Collection
    {
        $forms = $this->formsFor($session);

        if ($forms->isEmpty()) {
            return collect();
        }

        $day = $this->referenceDay($session);
        $like = '%' . addcslashes($term, '\\%_') . '%';
        $nameKey = EventRegistrationService::nameKey($term);
        $digits = (string) preg_replace('/\D+/', '', $term);
        $words = array_values(array_filter(preg_split('/\s+/u', trim($term)) ?: [], fn ($word) => $word !== ''));

        $entries = $this->scoped($session, $staffLocationIds, $forms)
            ->where(function (Builder $query) use ($like, $nameKey, $digits, $words) {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);

                if ($nameKey !== '') {
                    $query->orWhere('name_key', 'like', '%' . addcslashes($nameKey, '\\%_') . '%');
                }

                if (count($words) > 1) {
                    $query->orWhere(function (Builder $all) use ($words) {
                        foreach ($words as $word) {
                            $all->where('name', 'like', '%' . addcslashes($word, '\\%_') . '%');
                        }
                    });
                }

                if (strlen($digits) >= 4) {
                    $query->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '(', ''), ')', ''), '-', ''), '.', ''), '+', '') LIKE ?",
                        ['%' . $digits . '%']
                    );
                }
            })
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $entries
            ->each(fn (RegistrationEntry $entry) => $entry->setRelation('event', $forms->get($entry->registration_event_id)))
            ->sort(function (RegistrationEntry $a, RegistrationEntry $b) use ($day) {
                $coverage = (int) $b->event->coversDay($day) <=> (int) $a->event->coversDay($day);

                if ($coverage !== 0) {
                    return $coverage;
                }

                $date = strcmp($b->event->event_date->toDateString(), $a->event->event_date->toDateString());

                return $date !== 0 ? $date : $b->id <=> $a->id;
            })
            ->unique(fn (RegistrationEntry $entry) => $entry->registration_contact_id . '|' . $entry->name_key)
            ->take(self::SEARCH_LIMIT)
            ->values();
    }

    public function findForSend(PhotoSession $session, array $entryIds, array $staffLocationIds): Collection
    {
        if ($entryIds === []) {
            return collect();
        }

        $forms = $this->formsFor($session);

        if ($forms->isEmpty()) {
            return collect();
        }

        return $this->scoped($session, $staffLocationIds, $forms)
            ->whereIn('id', $entryIds)
            ->get()
            ->each(fn (RegistrationEntry $entry) => $entry->setRelation('event', $forms->get($entry->registration_event_id)));
    }

    protected function scoped(PhotoSession $session, array $staffLocationIds, Collection $forms): Builder
    {
        $locations = array_values(array_intersect(
            array_map('intval', $staffLocationIds),
            [(int) $session->location_id]
        ));

        return RegistrationEntry::query()
            ->visible()
            ->where('company_id', $session->company_id)
            ->whereIn('location_id', $locations)
            ->whereIn('registration_event_id', $forms->keys()->map(fn ($id) => (int) $id)->all());
    }
}
