<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('company_name');
            $table->string('website')->nullable()->after('email');
            $table->string('tax_id')->nullable()->after('phone');
            $table->string('registration_number')->nullable()->after('tax_id');
            $table->string('city')->nullable()->after('address');
            $table->string('state')->nullable()->after('city');
            $table->string('country')->default('USA')->after('state');
            $table->string('zip_code')->nullable()->after('country');
            $table->string('industry')->nullable()->after('zip_code');
            $table->enum('company_size', ['1-10', '11-50', '51-200', '201-500', '501-1000', '1000+'])->nullable()->after('industry');
            $table->date('founded_date')->nullable()->after('company_size');
            $table->text('description')->nullable()->after('founded_date');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('total_employees');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path',
                'website',
                'tax_id',
                'registration_number',
                'city',
                'state',
                'country',
                'zip_code',
                'industry',
                'company_size',
                'founded_date',
                'description',
                'status',
            ]);
        });
    }
};
