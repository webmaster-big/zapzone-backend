<?php

namespace App\Services;

use App\Models\Waiver;
use App\Models\WaiverMinor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class WaiverMetricsService
{
    public const AGE_BRACKETS = [
        ['label' => '18–20', 'min' => 18, 'max' => 20],
        ['label' => '21–25', 'min' => 21, 'max' => 25],
        ['label' => '26–30', 'min' => 26, 'max' => 30],
        ['label' => '31–40', 'min' => 31, 'max' => 40],
        ['label' => '41–50', 'min' => 41, 'max' => 50],
        ['label' => '51–59', 'min' => 51, 'max' => 59],
        ['label' => '60+', 'min' => 60, 'max' => null],
    ];

    public const SOURCE_LABELS = [
        Waiver::SOURCE_KIOSK => 'Kiosk',
        Waiver::SOURCE_CONFIRMATION_EMAIL => 'Email',
        Waiver::SOURCE_SMS_LINK => 'SMS',
        Waiver::SOURCE_STAFF_SENT => 'Staff-sent',
        Waiver::SOURCE_BULK_INVITE => 'Bulk invite',
        Waiver::SOURCE_CHECKOUT => 'Checkout',
    ];

    /**
     * Scalar counts for a scoped + date-filtered waiver query.
     * $base must already be scoped (company/location) and date-filtered; it is
     * never mutated (each read clones it) and excludes soft-deleted rows.
     */
    public function summary(Builder $base): array
    {
        $total = (clone $base)->count();
        $completed = (clone $base)->where('status', Waiver::STATUS_COMPLETED)->count();
        $pending = (clone $base)->where('status', Waiver::STATUS_PENDING)->count();
        $checkedIn = (clone $base)->where('status', Waiver::STATUS_COMPLETED)->whereNotNull('checked_in_at')->count();
        $withMinors = (clone $base)->where('status', Waiver::STATUS_COMPLETED)->has('minors')->count();
        $minorsCovered = (int) WaiverMinor::whereIn('waiver_id', (clone $base)->select('id'))->count();
        $marketingOptedIn = (clone $base)->where('marketing_consent_status', Waiver::MARKETING_OPTED_IN)->count();
        $expired = (clone $base)->where('status', Waiver::STATUS_COMPLETED)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', Carbon::now()->toDateString())
            ->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'checked_in' => $checkedIn,
            'signed_not_checked_in' => max(0, $completed - $checkedIn),
            'adult_signers' => $completed,
            'minors_covered' => $minorsCovered,
            'people_covered' => $completed + $minorsCovered,
            'with_minors' => $withMinors,
            'adults_only' => max(0, $completed - $withMinors),
            'marketing_opted_in' => $marketingOptedIn,
            'expired' => $expired,
        ];
    }

    /** [{status, count}] over the completed/pending split (+ checked-in subset). */
    public function statusBreakdown(Builder $base): array
    {
        $s = $this->summary($base);
        return [
            ['status' => 'Completed', 'count' => $s['completed']],
            ['status' => 'Pending', 'count' => $s['pending']],
            ['status' => 'Checked in', 'count' => $s['checked_in']],
        ];
    }

    /** [{source, count}] in fixed source order, omitting zero rows. */
    public function sourceBreakdown(Builder $base): array
    {
        $rows = (clone $base)->selectRaw('source, COUNT(*) as aggregate')
            ->groupBy('source')->pluck('aggregate', 'source');

        $out = [];
        foreach (self::SOURCE_LABELS as $key => $label) {
            $count = (int) ($rows[$key] ?? 0);
            if ($count > 0) {
                $out[] = ['source' => $label, 'count' => $count];
            }
        }
        return $out;
    }

    /**
     * Adult age distribution of COMPLETED waivers with a recorded DOB, bucketed in
     * PHP (DB-portable) via Carbon age. Zero-count brackets are kept so the chart
     * axis is stable; an "Under 18" bucket appears only if such data exists.
     */
    public function ageBrackets(Builder $base): array
    {
        $dobs = (clone $base)->where('status', Waiver::STATUS_COMPLETED)
            ->whereNotNull('adult_dob')->pluck('adult_dob');

        $counts = [];
        foreach (self::AGE_BRACKETS as $bracket) {
            $counts[$bracket['label']] = 0;
        }
        $under18 = 0;

        foreach ($dobs as $dob) {
            if (!$dob) {
                continue;
            }
            $age = Carbon::parse($dob)->age;
            if ($age < 18) {
                $under18++;
                continue;
            }
            foreach (self::AGE_BRACKETS as $bracket) {
                if ($age >= $bracket['min'] && ($bracket['max'] === null || $age <= $bracket['max'])) {
                    $counts[$bracket['label']]++;
                    break;
                }
            }
        }

        $out = [];
        if ($under18 > 0) {
            $out[] = ['bracket' => 'Under 18', 'count' => $under18];
        }
        foreach (self::AGE_BRACKETS as $bracket) {
            $out[] = ['bracket' => $bracket['label'], 'count' => $counts[$bracket['label']]];
        }
        return $out;
    }
}
