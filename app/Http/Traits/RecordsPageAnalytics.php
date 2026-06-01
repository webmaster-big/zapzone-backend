<?php

namespace App\Http\Traits;

use App\Models\PageView;
use App\Services\PageAnalyticsRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
