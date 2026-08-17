<?php

namespace App\Console\Commands;

use App\Models\Waiver;
use App\Services\WaiverMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseWaiverCounts extends Command
{
    protected $signature = 'waivers:diagnose
        {--timeframe=today : today, last_24h, last_7d, last_30d, all_time}
        {--company= : Company id, defaults to the only one}
        {--location= : Narrow to a location id, as the sidebar selector does}';

    protected $description = 'Explain a waiver count: which rows a timeframe covers and why a screen might disagree';

    public function handle(): int
    {
        $metrics = app(WaiverMetricsService::class);
        $timezone = config('app.timezone', 'UTC');
        $timeframe = (string) $this->option('timeframe');

        $companyId = $this->option('company') ?: DB::table('waivers')->value('company_id');
        $locationId = $this->option('location') ? (int) $this->option('location') : null;

        [$from, $to] = $metrics->periodFor($timeframe, null, null, $timezone);

        $this->line('');
        $this->info("Timeframe: {$timeframe}   timezone: {$timezone}   now: " . now($timezone)->toDateTimeString());
        $this->line('  covers visit days: ' . ($from ?? 'unbounded') . ' .. ' . ($to ?? 'unbounded'));
        $this->line('  company: ' . ($companyId ?: 'any') . '   location filter: ' . ($locationId ?: 'none (all locations)'));
        $this->line('');

        $base = function () use ($companyId, $locationId) {
            $q = Waiver::query();
            if ($companyId) $q->where('company_id', $companyId);
            if ($locationId) $q->where('location_id', $locationId);
            return $q;
        };

        $scoped = $metrics->applyTimeframe($base(), $timeframe, null, null, $timezone);
        $summary = $metrics->summary($scoped);

        $this->line('What the dashboard card and the Records summary both count:');
        $this->table(
            ['total', 'signed', 'pending', 'checked in'],
            [[$summary['total'], $summary['completed'], $summary['pending'], $summary['checked_in']]]
        );

        // The most common reasons two screens disagree.
        $this->line('If a screen shows something different, it is almost always one of these:');

        $allLocations = $metrics->summary($metrics->applyTimeframe(
            (clone $base())->when($locationId, fn ($q) => $q->whereRaw('1=1')),
            $timeframe, null, null, $timezone
        ));

        $withoutLocation = Waiver::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $metrics->applyTimeframe($withoutLocation, $timeframe, null, null, $timezone);
        $ignoringLocation = $withoutLocation->count();

        $noLocationRows = Waiver::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('location_id');
        $metrics->applyTimeframe($noLocationRows, $timeframe, null, null, $timezone);

        $this->table(
            ['check', 'count', 'meaning'],
            [
                ['with your location filter', $summary['total'], 'what this run counted'],
                ['ignoring location entirely', $ignoringLocation, $ignoringLocation !== $summary['total']
                    ? 'the location filter is hiding rows — the two screens must agree on the sidebar location'
                    : 'location makes no difference here'],
                ['rows with no location at all', (clone $noLocationRows)->count(), 'these vanish the moment any location is selected'],
                ['completed only', $summary['completed'], 'what Records shows unless status is set to All'],
            ]
        );

        $rows = (clone $scoped)
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'status', 'selected_date', 'submitted_at', 'created_at', 'location_id']);

        if ($rows->isEmpty()) {
            $this->warn('No waivers matched. Try --timeframe=all_time to confirm any exist at all.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('The matching rows (newest 25):');
        $this->table(
            ['id', 'status', 'visit date', 'signed at', 'counted on', 'location'],
            $rows->map(fn ($w) => [
                $w->id,
                $w->status,
                $w->selected_date ?: '— none —',
                $w->submitted_at ?: '— none —',
                $w->selected_date ?: substr((string) ($w->submitted_at ?: $w->created_at), 0, 10),
                $w->location_id ?: '— none —',
            ])->all()
        );

        $this->line('');
        $this->line('"counted on" is the day this waiver is counted on, which is its visit date when it');
        $this->line('has one and the day it was signed when it does not.');

        return self::SUCCESS;
    }
}
