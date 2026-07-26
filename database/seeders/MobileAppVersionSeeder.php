<?php

namespace Database\Seeders;

use App\Models\MobileAppVersion;
use Illuminate\Database\Seeder;

class MobileAppVersionSeeder extends Seeder
{
    public function run(): void
    {
        MobileAppVersion::updateOrCreate(
            ['platform' => 'android'],
            [
                'latest_version'  => '1.0.0',
                'minimum_version' => '1.0.0',
                'force_update'    => false,
                'apk_url'         => null,
                'update_message'  => 'A new version of ZapZone Admin is available.',
                'release_notes'   => [],
                'is_active'       => true,
            ]
        );

        $this->command->info('✓ Mobile app version seeder complete: android 1.0.0');
    }
}
