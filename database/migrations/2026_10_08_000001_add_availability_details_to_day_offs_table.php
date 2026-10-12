<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            if (! Schema::hasColumn('day_offs', 'opens_at')) {
                $table->time('opens_at')->nullable()->after('time_end');
            }

            if (! Schema::hasColumn('day_offs', 'closes_at')) {
                $table->time('closes_at')->nullable()->after('opens_at');
            }

            if (! Schema::hasColumn('day_offs', 'group_name')) {
                $table->string('group_name', 150)->nullable()->after('reason');
            }

            if (! Schema::hasColumn('day_offs', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('event_ids')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('day_offs', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $dayOffs = DB::table('day_offs')->whereNull('created_by')->orderBy('id')->get(['id', 'date']);

        foreach ($dayOffs as $dayOff) {
            $creations = DB::table('activity_logs')
                ->where('entity_type', 'day_off')
                ->where('entity_id', $dayOff->id)
                ->where('action', 'Day Off Created')
                ->whereNotNull('user_id')
                ->orderBy('id')
                ->get(['user_id', 'metadata']);

            foreach ($creations as $creation) {
                $metadata = json_decode((string) $creation->metadata, true);
                $loggedDate = $metadata['day_off_details']['date'] ?? null;

                if ($loggedDate !== substr((string) $dayOff->date, 0, 10)) {
                    continue;
                }

                if (! DB::table('users')->where('id', $creation->user_id)->exists()) {
                    break;
                }

                DB::table('day_offs')->where('id', $dayOff->id)->update(['created_by' => $creation->user_id]);

                break;
            }
        }
    }

    public function down(): void
    {
        $windows = DB::table('day_offs')
            ->where(fn ($query) => $query->whereNotNull('opens_at')->orWhereNotNull('closes_at'))
            ->orderBy('id')
            ->get();

        foreach ($windows as $window) {
            $opensAt = $window->opens_at && substr((string) $window->opens_at, 0, 5) !== '00:00' ? substr((string) $window->opens_at, 0, 5) : null;
            $closesAt = $window->closes_at && substr((string) $window->closes_at, 0, 5) !== '00:00' ? substr((string) $window->closes_at, 0, 5) : null;
            $reason = $this->reasonWithGroup($window);

            if ($opensAt === null && $closesAt === null) {
                DB::table('day_offs')->where('id', $window->id)->delete();

                continue;
            }

            DB::table('day_offs')->where('id', $window->id)->update([
                'time_start' => $opensAt === null ? $closesAt : null,
                'time_end' => $opensAt,
                'reason' => $reason,
            ]);

            if ($opensAt !== null && $closesAt !== null) {
                $copy = (array) $window;
                unset($copy['id']);
                DB::table('day_offs')->insert(array_merge($copy, [
                    'time_start' => $closesAt,
                    'time_end' => null,
                    'reason' => $reason,
                ]));
            }
        }

        DB::table('day_offs')
            ->whereNull('opens_at')
            ->whereNull('closes_at')
            ->whereNotNull('group_name')
            ->orderBy('id')
            ->get()
            ->each(fn ($row) => DB::table('day_offs')->where('id', $row->id)->update(['reason' => $this->reasonWithGroup($row)]));

        Schema::table('day_offs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['opens_at', 'closes_at', 'group_name']);
        });
    }

    private function reasonWithGroup(object $row): ?string
    {
        $group = trim((string) ($row->group_name ?? ''));
        $reason = trim((string) ($row->reason ?? ''));

        if ($group === '' || str_starts_with($reason, $group . ':')) {
            return $row->reason;
        }

        return mb_substr($reason === '' ? $group : "{$group}: {$reason}", 0, 255);
    }
};
