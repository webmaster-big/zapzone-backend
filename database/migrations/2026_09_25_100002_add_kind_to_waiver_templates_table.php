<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('waiver_templates', 'kind')) {
            return;
        }

        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->string('kind', 20)->default('standard');
            $table->index(['company_id', 'kind'], 'waiver_templates_company_kind_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('waiver_templates', 'kind')) {
            return;
        }

        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->dropIndex('waiver_templates_company_kind_index');
            $table->dropColumn('kind');
        });
    }
};
