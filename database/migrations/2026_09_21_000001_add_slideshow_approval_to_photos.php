<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->string('slideshow_approval_status', 12)->default('pending')->after('slideshow_state');
            $table->timestamp('slideshow_approved_at')->nullable()->after('slideshow_approval_status');
            $table->foreignId('slideshow_approved_by')->nullable()->after('slideshow_approved_at')->constrained('users')->nullOnDelete();
            $table->index(['slideshow_queue_id', 'slideshow_approval_status'], 'photos_queue_approval_idx');
        });

        Schema::table('location_photo_settings', function (Blueprint $table) {
            $table->boolean('slideshow_requires_approval')->default(true)->after('slideshow_duration_seconds');
            $table->boolean('slideshow_auto_add_kiosk')->default(true)->after('slideshow_requires_approval');
            $table->boolean('slideshow_auto_add_staff')->default(false)->after('slideshow_auto_add_kiosk');
        });

        DB::table('photos')
            ->where('slideshow_eligible', true)
            ->update([
                'slideshow_approval_status' => 'approved',
                'slideshow_approved_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('location_photo_settings', function (Blueprint $table) {
            $table->dropColumn(['slideshow_requires_approval', 'slideshow_auto_add_kiosk', 'slideshow_auto_add_staff']);
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex('photos_queue_approval_idx');
            $table->dropConstrainedForeignId('slideshow_approved_by');
            $table->dropColumn(['slideshow_approval_status', 'slideshow_approved_at']);
        });
    }
};
