<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('custom_fields')) {
            Schema::create('custom_fields', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('label');
                $table->string('type', 30)->default('checkbox');
                $table->string('help_text')->nullable();
                $table->boolean('is_required')->default(false);
                $table->string('audience', 20)->default('both');

                // Empty means "every one of them", matching promos and gift cards.
                $table->json('location_ids')->nullable();
                $table->json('package_ids')->nullable();
                $table->json('attraction_ids')->nullable();
                $table->json('event_ids')->nullable();

                $table->unsignedInteger('display_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'is_active']);
            });
        }

        if (!Schema::hasTable('custom_field_responses')) {
            Schema::create('custom_field_responses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('custom_field_id')->nullable()->constrained()->nullOnDelete();

                // The label is copied in so a renamed or deleted field never rewrites
                // what a guest was actually shown when they ticked the box.
                $table->string('label');
                $table->string('type', 30)->default('checkbox');
                $table->boolean('value')->default(false);

                $table->string('respondable_type', 40);
                $table->unsignedBigInteger('respondable_id');
                $table->timestamps();

                $table->index(['respondable_type', 'respondable_id']);
                $table->index('custom_field_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_responses');
        Schema::dropIfExists('custom_fields');
    }
};
