<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE attraction_purchases ap
            JOIN (
                SELECT payable_id, SUM(amount) AS paid
                FROM payments
                WHERE payable_type = 'attraction_purchase' AND status = 'completed'
                GROUP BY payable_id
            ) p ON p.payable_id = ap.id
            SET ap.amount_paid = p.paid
            WHERE COALESCE(ap.amount_paid, 0) = 0
              AND ap.status NOT IN ('cancelled', 'refunded')
        ");

        DB::statement("
            UPDATE event_purchases ep
            JOIN (
                SELECT payable_id, SUM(amount) AS paid
                FROM payments
                WHERE payable_type = 'event_purchase' AND status = 'completed'
                GROUP BY payable_id
            ) p ON p.payable_id = ep.id
            SET ep.amount_paid = p.paid
            WHERE COALESCE(ep.amount_paid, 0) = 0
              AND ep.status NOT IN ('cancelled', 'refunded')
        ");

        DB::statement("
            UPDATE bookings b
            JOIN (
                SELECT payable_id, SUM(amount) AS paid
                FROM payments
                WHERE payable_type = 'booking' AND status = 'completed'
                GROUP BY payable_id
            ) p ON p.payable_id = b.id
            SET b.amount_paid = p.paid
            WHERE COALESCE(b.amount_paid, 0) = 0
              AND b.status NOT IN ('cancelled')
        ");

        DB::statement("
            UPDATE attraction_purchases
            SET amount_paid = total_amount
            WHERE COALESCE(amount_paid, 0) = 0
              AND created_at < '2025-12-16 00:00:00'
              AND status NOT IN ('cancelled', 'refunded')
              AND (payment_method IN ('card', 'in-store') OR transaction_id IS NOT NULL)
        ");

        DB::statement("
            UPDATE attraction_purchases
            SET status = 'confirmed'
            WHERE status = 'pending'
              AND total_amount > 0
              AND amount_paid >= total_amount
        ");
    }

    public function down(): void
    {
    }
};
