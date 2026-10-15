<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $deliveryColumns = [
        'provider_sid' => ['string', 64, 'error'],
        'provider_from' => ['string', 24, 'provider_sid'],
        'provider_status' => ['string', 20, 'provider_from'],
        'provider_error_code' => ['string', 12, 'provider_status'],
        'provider_checked_at' => ['timestamp', null, 'provider_error_code'],
        'delivered_at' => ['timestamp', null, 'provider_checked_at'],
        'receipt_confirmed_at' => ['timestamp', null, 'delivered_at'],
        'receipt_confirmed_by' => ['unsignedBigInteger', null, 'receipt_confirmed_at'],
        'receipt_confirmed_via' => ['string', 20, 'receipt_confirmed_by'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('photo_deliveries')) {
            if (!Schema::hasColumn('photo_deliveries', 'provider_sid')) {
                DB::table('photo_deliveries')->whereNotNull('opened_at')->update(['opened_at' => null]);
            }

            foreach ($this->deliveryColumns as $column => [$type, $length, $after]) {
                if (Schema::hasColumn('photo_deliveries', $column)) {
                    continue;
                }

                Schema::table('photo_deliveries', function (Blueprint $table) use ($column, $type, $length, $after) {
                    $definition = $length !== null ? $table->{$type}($column, $length) : $table->{$type}($column);
                    $definition->nullable();

                    if (Schema::hasColumn('photo_deliveries', $after)) {
                        $definition->after($after);
                    }
                });
            }

            if (!Schema::hasIndex('photo_deliveries', 'photo_deliveries_provider_sid_idx')) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->index('provider_sid', 'photo_deliveries_provider_sid_idx'));
            }

            if (!Schema::hasIndex('photo_deliveries', 'photo_deliveries_text_status_idx')) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->index(['channel', 'provider_status', 'sent_at'], 'photo_deliveries_text_status_idx'));
            }

            if (!$this->hasForeignKeyOn('photo_deliveries', 'receipt_confirmed_by')) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->foreign('receipt_confirmed_by')->references('id')->on('users')->nullOnDelete());
            }
        }

        if (!Schema::hasTable('sms_sender_verifications')) {
            Schema::create('sms_sender_verifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('sender', 24);
                $table->unsignedBigInteger('photo_delivery_id')->nullable();
                $table->string('method', 20);
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at');
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->string('revoke_reason', 255)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasIndex('sms_sender_verifications', 'sms_sender_verifications_active_idx')) {
            Schema::table('sms_sender_verifications', fn (Blueprint $table) => $table->index(['company_id', 'sender', 'revoked_at'], 'sms_sender_verifications_active_idx'));
        }

        foreach ([
            'company_id' => ['companies', 'cascade'],
            'location_id' => ['locations', 'null'],
            'photo_delivery_id' => ['photo_deliveries', 'null'],
            'confirmed_by' => ['users', 'null'],
            'revoked_by' => ['users', 'null'],
        ] as $column => [$references, $onDelete]) {
            if ($this->hasForeignKeyOn('sms_sender_verifications', $column)) {
                continue;
            }

            Schema::table('sms_sender_verifications', function (Blueprint $table) use ($column, $references, $onDelete) {
                $foreign = $table->foreign($column)->references('id')->on($references);
                $onDelete === 'cascade' ? $foreign->cascadeOnDelete() : $foreign->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_sender_verifications');

        if (!Schema::hasTable('photo_deliveries')) {
            return;
        }

        if ($this->hasForeignKeyOn('photo_deliveries', 'receipt_confirmed_by')) {
            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropForeign(['receipt_confirmed_by']));
        }

        foreach (['photo_deliveries_text_status_idx', 'photo_deliveries_provider_sid_idx'] as $index) {
            if (Schema::hasIndex('photo_deliveries', $index)) {
                Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }

        $columns = array_values(array_filter(
            array_keys($this->deliveryColumns),
            fn (string $column) => Schema::hasColumn('photo_deliveries', $column)
        ));

        if ($columns !== []) {
            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    protected function hasForeignKeyOn(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }
};
