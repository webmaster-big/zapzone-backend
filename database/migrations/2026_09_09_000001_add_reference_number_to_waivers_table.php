<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('waivers', 'reference_number')) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->string('reference_number', 32)->nullable();
            });
        }

        if (!$this->uniqueIndexExists()) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->unique('reference_number', 'waivers_reference_number_unique');
            });
        }

        $this->backfillReferences();

        $remaining = DB::table('waivers')->whereNull('reference_number')->count();
        if ($remaining > 0) {
            throw new \RuntimeException(
                "Waiver reference backfill incomplete: {$remaining} row(s) still NULL. "
                . 'Re-run this migration — it is safe to repeat.'
            );
        }
    }

    public function down(): void
    {
        if ($this->uniqueIndexExists()) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->dropUnique('waivers_reference_number_unique');
            });
        }

        if (Schema::hasColumn('waivers', 'reference_number')) {
            Schema::table('waivers', function (Blueprint $table) {
                $table->dropColumn('reference_number');
            });
        }
    }

    private function uniqueIndexExists(): bool
    {
        if (!Schema::hasColumn('waivers', 'reference_number')) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'waivers')
            ->where('index_name', 'waivers_reference_number_unique')
            ->exists();
    }

    private function backfillReferences(): void
    {
        do {
            $rows = DB::table('waivers')
                ->select('id', 'created_at')
                ->whereNull('reference_number')
                ->orderBy('id')
                ->limit(500)
                ->get();

            foreach ($rows as $row) {
                $date = $row->created_at
                    ? \Carbon\Carbon::parse($row->created_at)->format('Ymd')
                    : now()->format('Ymd');

                $written = false;

                for ($attempt = 0; $attempt < 8 && !$written; $attempt++) {
                    try {
                        DB::table('waivers')
                            ->where('id', $row->id)
                            ->update(['reference_number' => 'WV' . $date . strtoupper(Str::random(6))]);
                        $written = true;
                    } catch (\Illuminate\Database\QueryException $e) {
                        if ((string) ($e->errorInfo[1] ?? '') !== '1062') {
                            throw $e;
                        }
                    }
                }

                if (!$written) {
                    throw new \RuntimeException(
                        "Could not generate a unique waiver reference for waiver id {$row->id} after 8 attempts."
                    );
                }
            }
        } while ($rows->count() > 0);
    }
};
