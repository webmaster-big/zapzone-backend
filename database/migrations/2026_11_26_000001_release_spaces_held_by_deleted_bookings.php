<?php

use Carbon\Carbon;
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
            ->whereNotNull('bookings.deleted_at')
            ->whereDate('slots.booked_date', '>=', now()->subDay()->toDateString())
            ->get(['slots.id', 'slots.room_id', 'slots.booked_date', 'bookings.reference_number']);

        $kept = $held->filter(fn ($slot) => $slot->room_id !== null && DB::table('bookings')
            ->whereNull('deleted_at')
            ->where('room_id', $slot->room_id)
            ->whereDate('booking_date', Carbon::parse($slot->booked_date)->toDateString())
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('package_time_slots')
                ->whereColumn('package_time_slots.booking_id', 'bookings.id')
                ->where('package_time_slots.status', 'booked'))
            ->exists());

        if ($kept->isNotEmpty()) {
            Log::warning('Kept the space of a deleted booking because a live booking in the same space that day has no space of its own', [
                'slots' => $kept->pluck('id')->all(),
                'bookings' => $kept->pluck('reference_number')->unique()->values()->all(),
            ]);
        }

        $held = $held->reject(fn ($slot) => $kept->contains('id', $slot->id))->values();

        if ($held->isNotEmpty()) {
            DB::table('package_time_slots')
                ->whereIn('id', $held->pluck('id')->all())
                ->where('status', 'booked')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        Log::info('Released spaces still held by deleted bookings', [
            'slots' => $held->count(),
            'bookings' => $held->pluck('reference_number')->unique()->values()->all(),
        ]);
    }

    public function down(): void
    {
    }
};
