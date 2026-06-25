<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account/payer is the guardian (the linked Customer). The person the pass
     * actually belongs to — the authorized pass holder — can be someone else (e.g. a
     * child). `holder_name` stores that pass holder's display name independently of the
     * guardian's customer record. Nullable: when blank the holder is the account owner.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('holder_name', 150)->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('holder_name');
        });
    }
};
