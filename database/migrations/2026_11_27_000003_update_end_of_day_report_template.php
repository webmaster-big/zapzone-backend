<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'end_of_day_sales_report';

    public function up(): void
    {
        $this->swap(self::oldBody(), self::newBody());
    }

    public function down(): void
    {
        $this->swap(self::newBody(), self::oldBody());
    }

    private function swap(string $from, string $to): void
    {
        if (! Schema::hasTable('email_notifications')) {
            return;
        }

        $changed = [];

        foreach (['body', 'default_body'] as $column) {
            if (! Schema::hasColumn('email_notifications', $column)) {
                continue;
            }

            $ids = DB::table('email_notifications')->where('default_key', self::KEY)->where($column, $from)->pluck('id')->all();

            if ($ids !== []) {
                DB::table('email_notifications')->whereIn('id', $ids)->update([$column => $to]);
                $changed[$column] = $ids;
            }
        }

        Log::info('End of Day Sales Report template swapped where it still matched the shipped default', $changed);
    }

    private static function oldBody(): string
    {
        return <<<'OLD_BODY'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #374151; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">End of Day Sales Report</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.85;">{{report_date}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 24px 16px; text-align: center;">
                    <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7280;">Total Collected</p>
                    <p style="margin: 0; font-size: 32px; font-weight: 700; color: #111827;">{{total_collected}}</p>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #6b7280;">{{report_scope}} &middot; {{items_sold}} items sold</p>
                </td>
            </tr>
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">Financial Summary</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Gross Sales</strong>
                    <span style="color: #111827; float: right;">{{gross_sales}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Discounts</strong>
                    <span style="color: #111827; float: right;">- {{discount_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Net Sales</strong>
                    <span style="color: #111827; float: right;">{{net_sales}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Tax</strong>
                    <span style="color: #111827; float: right;">{{tax_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Fees</strong>
                    <span style="color: #111827; float: right;">{{fee_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Amount Billed</strong>
                    <span style="color: #111827; float: right;">{{total_billed}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Collected (Card)</strong>
                    <span style="color: #111827; float: right;">{{collected_card}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Collected (Cash / In-Store)</strong>
                    <span style="color: #111827; float: right;">{{collected_cash}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Balance Due</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{balance_due}}</span>
                </td>
            </tr>
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">By Location</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e5e7eb; border-radius: 6px; margin: 0 0 24px 0; border-collapse: collapse;">
            <tr style="background: #f3f4f6;">
                <th style="padding: 10px 16px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Location</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Net Sales</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Collected</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Items</th>
            </tr>
            {{location_breakdown_rows}}
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">By Category</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e5e7eb; border-radius: 6px; margin: 0 0 8px 0; border-collapse: collapse;">
            <tr style="background: #f3f4f6;">
                <th style="padding: 10px 16px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Category</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Gross</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Net</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Collected</th>
            </tr>
            {{category_breakdown_rows}}
        </table>

        <p style="margin: 16px 0 0 0; font-size: 12px; color: #9ca3af;">Generated {{generated_at}} &middot; Figures reflect transactions created during the business day (Michigan time).</p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
OLD_BODY;
    }

    private static function newBody(): string
    {
        return <<<'NEW_BODY'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #374151; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">End of Day Sales Report</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.85;">{{report_date}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin: 0 0 8px 0;">
            <tr>
                <td width="50%" style="padding: 24px 16px; text-align: center; border-right: 1px solid #e5e7eb;">
                    <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7280;">Online Revenue</p>
                    <p style="margin: 0; font-size: 28px; font-weight: 700; color: #111827;">{{online_revenue}}</p>
                </td>
                <td width="50%" style="padding: 24px 16px; text-align: center;">
                    <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7280;">In-Store Collected</p>
                    <p style="margin: 0; font-size: 28px; font-weight: 700; color: #111827;">{{in_store_collected}}</p>
                </td>
            </tr>
        </table>
        <table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 8px 0;">
            {{unclassified_receipts_row}}
        </table>
        <p style="margin: 0 0 24px 0; font-size: 13px; color: #6b7280; text-align: center;">{{report_scope}} &middot; {{items_sold}} items sold</p>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">Receipts by Location</h3>
        {{receipts_location_table}}

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">Sales Booked Today</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Gross Sales</strong>
                    <span style="color: #111827; float: right;">{{gross_sales}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Discounts</strong>
                    <span style="color: #111827; float: right;">- {{discount_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Customer Fees</strong>
                    <span style="color: #111827; float: right;">{{fee_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Tax Charged</strong>
                    <span style="color: #111827; float: right;">{{tax_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Total Billed</strong>
                    <span style="color: #111827; float: right;">{{total_billed}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Open Balance</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{balance_due}}</span>
                </td>
            </tr>
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">Sales by Category</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e5e7eb; border-radius: 6px; margin: 0 0 8px 0; border-collapse: collapse;">
            <tr style="background: #f3f4f6;">
                <th style="padding: 10px 16px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Category</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Gross Sales</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Total Billed</th>
            </tr>
            {{category_sales_rows}}
        </table>

        <p style="margin: 16px 0 0 0; font-size: 12px; color: #9ca3af;">Generated {{generated_at}} &middot; Receipts count each payment and refund on the business day it posts (Michigan time). Sales figures cover bookings and purchases created that day.</p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
NEW_BODY;
    }
};
