<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGES = [
        'booking_confirmation_customer' => [
            'old' => '{{company_name}}: Party booked! {{package_name}} on {{booking_date}} at {{booking_time}}. Ref {{booking_reference}}. Balance due {{booking_balance}}. Info: {{location_phone}} {{waiver_line}}',
            'new' => '{{company_name}}: {{booking_kind_title}} booked! {{package_name}} on {{booking_date}} at {{booking_time}}. Ref {{booking_reference}}. Balance due {{booking_balance}}. Info: {{location_phone}} {{waiver_line}}',
        ],
        'booking_reminder_customer' => [
            'old' => '{{company_name}} reminder: your {{package_name}} party is {{booking_date}} at {{booking_time}}, {{location_name}}. See you soon! {{location_phone}}',
            'new' => '{{company_name}} reminder: your {{package_name}} {{booking_kind}} is {{booking_date}} at {{booking_time}}, {{location_name}}. See you soon! {{location_phone}}',
        ],
    ];

    public function up(): void
    {
        $this->swap('old', 'new');
    }

    public function down(): void
    {
        $this->swap('new', 'old');
    }

    private function swap(string $from, string $to): void
    {
        if (!Schema::hasTable('sms_notifications')) {
            return;
        }

        foreach (self::CHANGES as $key => $texts) {
            $rows = DB::table('sms_notifications')
                ->where('default_key', $key)
                ->where('is_default', true)
                ->get(['id', 'body', 'default_body']);

            foreach ($rows as $row) {
                $changes = [];

                if ($row->body === $texts[$from]) {
                    $changes['body'] = $texts[$to];
                }

                if ($row->default_body === $texts[$from]) {
                    $changes['default_body'] = $texts[$to];
                }

                if ($changes !== []) {
                    DB::table('sms_notifications')->where('id', $row->id)->update($changes);
                }
            }
        }
    }
};
