<?php

namespace App\Http\Traits;

use App\Models\PageView;
use App\Services\PageAnalyticsRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Tiny convenience trait for controllers that need to fire a server-side
 * conversion at the end of a successful action (booking created, refund
 * issued, signup completed, etc.).
 *
 * Usage:
 *     $this->recordConversion('booking_completed', $booking, (float) $booking->total_amount);
 *
 * The recorder swallows its own exceptions, so this call is *always*
 * safe to make even if the analytics table is down.
 */
trait RecordsPageAnalytics
{
    protected function pageAnalyticsRecorder(): PageAnalyticsRecorder
    {
        return app(PageAnalyticsRecorder::class);
    }

    protected function recordConversion(
        string $eventName,
        ?Model $entity = null,
        ?float $value = null,
        array $extra = []
    ): ?PageView {
        return $this->pageAnalyticsRecorder()->recordConversion(
            $eventName,
            $entity,
            $value,
            request(),
            $extra,
        );
    }
}
