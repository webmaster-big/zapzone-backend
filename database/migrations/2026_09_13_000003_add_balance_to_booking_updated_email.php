<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEEDLE = '<strong style="color: #6b7280;">Total:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{booking_total}}</span>
                </td>
            </tr>';

    private const REPLACEMENT = '<strong style="color: #6b7280;">Total:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{booking_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Amount Paid:</strong>
                    <span style="color: #111827; float: right;">{{booking_amount_paid}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Balance Due:</strong>
                    <span style="color: #dc2626; font-weight: 700; float: right;">{{booking_balance}}</span>
                </td>
            </tr>';

    public function up(): void
    {
        if (! Schema::hasTable('email_notifications')) {
            return;
        }

        $rows = DB::table('email_notifications')
            ->where('trigger_type', 'booking_updated')
            ->get(['id', 'body', 'default_body']);

        foreach ($rows as $row) {
            $changes = [];

            if ($row->default_body && ! str_contains($row->default_body, '{{booking_balance}}') && str_contains($row->default_body, self::NEEDLE)) {
                $changes['default_body'] = str_replace(self::NEEDLE, self::REPLACEMENT, $row->default_body);
            }

            $bodyIsPristine = $row->body !== null && $row->default_body !== null && $row->body === $row->default_body;

            if ($bodyIsPristine && ! str_contains($row->body, '{{booking_balance}}') && str_contains($row->body, self::NEEDLE)) {
                $changes['body'] = str_replace(self::NEEDLE, self::REPLACEMENT, $row->body);
            }

            if ($changes !== []) {
                DB::table('email_notifications')->where('id', $row->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_notifications')) {
            return;
        }

        $rows = DB::table('email_notifications')
            ->where('trigger_type', 'booking_updated')
            ->get(['id', 'body', 'default_body']);

        foreach ($rows as $row) {
            $changes = [];

            if ($row->default_body && str_contains($row->default_body, self::REPLACEMENT)) {
                $changes['default_body'] = str_replace(self::REPLACEMENT, self::NEEDLE, $row->default_body);
            }

            if ($row->body && str_contains($row->body, self::REPLACEMENT)) {
                $changes['body'] = str_replace(self::REPLACEMENT, self::NEEDLE, $row->body);
            }

            if ($changes !== []) {
                DB::table('email_notifications')->where('id', $row->id)->update($changes);
            }
        }
    }
};
