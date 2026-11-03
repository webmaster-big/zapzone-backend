<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = ['purchase_confirmation_customer', 'purchase_reminder_customer', 'purchase_reschedule_customer'];

    private const DATE_NEEDLE = '<strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{purchase_date}}</span>';

    private const DATE_REPLACEMENT = '<strong style="color: #6b7280;">Visit Date:</strong>
                    <span style="color: #111827; float: right;">{{purchase_visit_date}} {{purchase_visit_time}}</span>';

    private const TOTAL_NEEDLE = '            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Total:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{purchase_total}}</span>';

    private const DISCOUNT_ROW = '            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Discount:</strong>
                    <span style="color: #111827; float: right;">{{purchase_discount}}</span>
                </td>
            </tr>
';

    public function up(): void
    {
        $this->rewrite(fn (string $html, string $key) => $this->forward($html, $key));
    }

    public function down(): void
    {
        $this->rewrite(fn (string $html) => str_replace(
            [self::DATE_REPLACEMENT, self::DISCOUNT_ROW . self::TOTAL_NEEDLE],
            [self::DATE_NEEDLE, self::TOTAL_NEEDLE],
            $html
        ));
    }

    private function forward(string $html, string $key): string
    {
        if (str_contains($html, self::DATE_NEEDLE)) {
            $html = str_replace(self::DATE_NEEDLE, self::DATE_REPLACEMENT, $html);
        }

        if ($key === 'purchase_confirmation_customer'
            && str_contains($html, '{{purchase_unit_price}}')
            && ! str_contains($html, '{{purchase_discount}}')
            && str_contains($html, self::TOTAL_NEEDLE)) {
            $html = str_replace(self::TOTAL_NEEDLE, self::DISCOUNT_ROW . self::TOTAL_NEEDLE, $html);
        }

        return $html;
    }

    private function rewrite(callable $transform): void
    {
        if (! Schema::hasTable('email_notifications') || ! Schema::hasColumn('email_notifications', 'default_key')) {
            return;
        }

        $rows = DB::table('email_notifications')
            ->whereIn('default_key', self::KEYS)
            ->get(['id', 'default_key', 'body', 'default_body']);

        foreach ($rows as $row) {
            $changes = [];
            $pristine = $row->body !== null && $row->default_body !== null && $row->body === $row->default_body;

            if ($row->default_body) {
                $next = $transform($row->default_body, $row->default_key);
                if ($next !== $row->default_body) {
                    $changes['default_body'] = $next;
                }
            }

            if ($pristine) {
                $next = $transform($row->body, $row->default_key);
                if ($next !== $row->body) {
                    $changes['body'] = $next;
                }
            }

            if ($changes !== []) {
                DB::table('email_notifications')->where('id', $row->id)->update($changes);
            }
        }
    }
};
