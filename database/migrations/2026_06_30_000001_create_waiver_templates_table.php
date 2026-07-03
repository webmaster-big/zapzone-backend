<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            // null location = applies to all of the company's locations
            $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');

            $table->string('title');
            $table->text('internal_description')->nullable();
            $table->enum('status', ['draft', 'active', 'inactive', 'archived'])->default('draft');
            // catch-all template used when a booking's activity has no specific assignment
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('current_version')->default(1);
            $table->longText('body_text');

            // null = never expires
            $table->unsignedInteger('validity_duration_days')->nullable();
            $table->unsignedInteger('max_minors')->default(5);
            $table->enum('duplicate_rule', ['none', 'allow', 'manager_only'])->default('manager_only');
            $table->boolean('reminder_eligible')->default(true);

            // Assignment / targeting — which activities make this waiver "appear".
            // Same concept as fee_supports.entity_ids (JSON arrays of IDs on the row),
            // extended so one template can cover several activity types at once.
            // Each activity ID belongs to at most one template (enforced in app logic).
            $table->json('assigned_package_ids')->nullable();
            $table->json('assigned_attraction_ids')->nullable();
            $table->json('assigned_event_ids')->nullable();
            $table->json('assigned_party_types')->nullable();

            // clause toggles
            $table->boolean('minor_section_enabled')->default(true);
            $table->boolean('dob_required')->default(true);
            $table->boolean('relationship_required')->default(true);
            $table->boolean('photo_video_release_enabled')->default(false);
            $table->boolean('medical_ack_enabled')->default(false);
            $table->boolean('property_damage_enabled')->default(false);
            $table->boolean('group_leader_clause_enabled')->default(false);
            $table->boolean('electronic_consent_enabled')->default(true);

            // marketing consent config
            $table->boolean('marketing_consent_enabled')->default(true);
            $table->text('marketing_consent_text')->nullable();
            $table->text('marketing_helper_text')->nullable();
            $table->boolean('crm_sync_allowed')->default(false);
            $table->boolean('crm_sync_birthday')->default(false);
            $table->boolean('crm_sync_minor')->default(false); // legal-gated

            $table->boolean('attorney_reviewed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('location_id');
            $table->index('status');
        });

        // Immutable snapshots — what was actually agreed to
        Schema::create('waiver_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiver_template_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('version');
            $table->longText('body_text');
            $table->json('clause_config'); // frozen snapshot of all toggles + marketing text
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['waiver_template_id', 'version']);
            $table->index('waiver_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_template_versions');
        Schema::dropIfExists('waiver_templates');
    }
};
