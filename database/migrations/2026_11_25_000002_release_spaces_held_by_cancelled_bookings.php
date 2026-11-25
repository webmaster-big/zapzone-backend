<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('package_time_slots') || ! Schema::hasTable('bookings')) {
            return;
        }

        $held = DB::table('package_time_slots as slots')
            ->join('bookings', 'bookings.id', '=', 'slots.booking_id')
            ->where('slots.status', 'booked')
            ->where('bookings.status', 'cancelled')
            ->whereDate('slots.booked_date', '>=', now()->subDay()->toDateString())
            ->get(['slots.id', 'bookings.reference_number']);

        if ($held->isNotEmpty()) {
            DB::table('package_time_slots')
                ->whereIn('id', $held->pluck('id')->all())
                ->where('status', 'booked')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        Log::info('Released spaces still held by cancelled bookings', [
            'slots' => $held->count(),
            'bookings' => $held->pluck('reference_number')->unique()->values()->all(),
        ]);
    }

    public function down(): void
    {
    }
};
