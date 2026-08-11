<?php

namespace App\Console\Commands;

use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\PhotoSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeExpiredPhotos extends Command
{
    protected $signature = 'photos:purge {--dry-run}';

    protected $description = 'Remove photo media from backend access once each location\'s retention period ends';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $settings = LocationPhotoSetting::with('location')->get();
        $purgedPhotos = 0;
        $purgedSessions = 0;

        foreach ($settings as $setting) {
            $retentionDays = max(1, (int) $setting->retention_days);
            $cutoff = now()->subDays($retentionDays);

            $photos = Photo::where('location_id', $setting->location_id)
                ->whereNull('purged_at')
                ->where('created_at', '<', $cutoff)
                ->limit(2000)
                ->get();

            foreach ($photos as $photo) {
                if (!$dryRun) {
                    $photo->purge();
                }
                $purgedPhotos++;
            }

            $sessions = PhotoSession::where('location_id', $setting->location_id)
                ->whereNull('purged_at')
                ->where('created_at', '<', $cutoff)
                ->limit(2000)
                ->get();

            foreach ($sessions as $session) {
                if (!$dryRun) {
                    $session->update(['purged_at' => now()]);
                }
                $purgedSessions++;
            }
        }

        $prefix = $dryRun ? '[dry run] ' : '';
        $this->info("{$prefix}Retention purge complete: {$purgedPhotos} photo(s), {$purgedSessions} session(s).");

        if (!$dryRun) {
            Log::info('Photo retention purge complete', [
                'photos' => $purgedPhotos,
                'sessions' => $purgedSessions,
            ]);
        }

        return Command::SUCCESS;
    }
}
