<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->longText('signature_image')->nullable()->after('typed_legal_name');
            $table->string('device_id')->nullable()->after('device');
            $table->string('browser')->nullable()->after('device_id');
            $table->string('operating_system')->nullable()->after('browser');
            $table->text('user_agent')->nullable()->after('operating_system');
            $table->unsignedInteger('read_seconds')->nullable()->after('user_agent');
            $table->decimal('gps_latitude', 10, 7)->nullable()->after('read_seconds');
            $table->decimal('gps_longitude', 10, 7)->nullable()->after('gps_latitude');
            $table->float('gps_accuracy')->nullable()->after('gps_longitude');
            $table->string('pdf_path')->nullable()->after('gps_accuracy');
            $table->string('pdf_hash', 64)->nullable()->after('pdf_path');
            $table->timestamp('pdf_generated_at')->nullable()->after('pdf_hash');
        });
    }

    public function down(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->dropColumn([
                'signature_image',
                'device_id',
                'browser',
                'operating_system',
                'user_agent',
                'read_seconds',
                'gps_latitude',
                'gps_longitude',
                'gps_accuracy',
                'pdf_path',
                'pdf_hash',
                'pdf_generated_at',
            ]);
        });
    }
};
