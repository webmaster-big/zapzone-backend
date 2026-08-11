<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\SlideshowQueue;
use App\Services\PhotoDeliveryService;
use App\Support\OperatingDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RollSlideshowQueues extends Command
{
    protected $signature = 'photos:roll-queues';

    protected $description = 'Close slideshow queues at 6:00 AM location time, open the next one, and flag offline displays';

    public function handle(PhotoDeliveryService $deliveries): int
    {
        $locations = Location::where('is_active', true)->get();
        $closed = 0;
        $opened = 0;
        $offline = 0;

        foreach ($locations as $location) {
            $today = OperatingDay::forLocation($location);

            $stale = SlideshowQueue::where('location_id', $location->id)
                ->where('status', SlideshowQueue::STATUS_ACTIVE)
                ->whereDate('operating_day', '<', $today)
                ->get();

            foreach ($stale as $queue) {
                $queue->update([
                    'status' => SlideshowQueue::STATUS_CLOSED,
                    'closed_at' => now(),
                ]);
                $closed++;
            }

            $setting = LocationPhotoSetting::forLocation($location);

            if (!$setting->slideshow_enabled) {
                continue;
            }

            $existing = SlideshowQueue::where('location_id', $location->id)
                ->whereDate('operating_day', $today)
                ->exists();

            $current = SlideshowQueue::activeFor($location, $today);

            if (!$existing) {
                $opened++;
            }

            if ($this->displayWentOffline($setting, $current)) {
                $deliveries->notifyBackend(
                    $location,
                    'Slideshow display stopped reporting',
                    sprintf(
                        'The slideshow at %s last checked in %s and the active queue still has photos to show.',
                        $location->name,
                        $setting->slideshow_seen_at->diffForHumans()
                    ),
                    [
                        'location_id' => $location->id,
                        'last_seen_at' => $setting->slideshow_seen_at->toIso8601String(),
                    ]
                );
                $offline++;
            }
        }

        $this->info("Slideshow queues: {$closed} closed, {$opened} opened, {$offline} offline alert(s).");
        Log::info('Slideshow queue rollover complete', [
            'closed' => $closed,
            'opened' => $opened,
            'offline_alerts' => $offline,
        ]);

        return Command::SUCCESS;
    }

    protected function displayWentOffline(LocationPhotoSetting $setting, SlideshowQueue $queue): bool
    {
        if ($setting->slideshow_seen_at === null) {
            return false;
        }

        $minutes = $setting->slideshow_seen_at->diffInMinutes(now());

        return $minutes >= 30
            && $minutes < 120
            && $queue->visiblePhotos()->count() > 0;
    }
}
