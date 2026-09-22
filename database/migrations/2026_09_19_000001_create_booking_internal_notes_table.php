<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_internal_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // user_id is nullOnDelete like every other actor reference here, so the writer's name
            // is snapshotted beside it: a note is permanent and must still say who wrote it long
            // after they have left and their account is gone. Same reasoning as activity_logs.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->string('author_role', 50)->nullable();

            // what the note is about — refund, participants, allergy, and so on. Free notes are
            // allowed, so it is nullable rather than an enum the desk has to fight.
            $table->string('category', 40)->nullable();
            $table->text('body');

            $table->timestamp('created_at')->nullable();

            // who last corrected it, so the list can say so without loading the history
            $table->timestamp('edited_at')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('editor_name')->nullable();
            $table->string('editor_role', 50)->nullable();

            $table->index(['booking_id', 'created_at']);
        });

        // Every superseded version of a note. Editing a note is allowed — staff need to be able to
        // correct their own words — but what it used to say is never thrown away, which is the part
        // that has to be permanent.
        Schema::create('booking_internal_note_revisions', function (Blueprint $table) {
            $table->id();
            // the generated constraint name lands on exactly 64 characters, MySQL's hard limit,
            // so it is named here rather than left one rename away from failing
            $table->unsignedBigInteger('booking_internal_note_id');
            $table->foreign('booking_internal_note_id', 'bin_revisions_note_fk')
                ->references('id')->on('booking_internal_notes')->cascadeOnDelete();

            // the note as it read BEFORE this edit
            $table->text('body');
            $table->string('category', 40)->nullable();

            // and who replaced it
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('editor_name');
            $table->string('editor_role', 50)->nullable();
            $table->timestamp('created_at')->nullable();

            // named explicitly: the generated name would be 73 characters and MySQL stops at 64
            $table->index(['booking_internal_note_id', 'created_at'], 'bin_revisions_note_created_index');
        });

        // Everything already written in bookings.internal_notes becomes the first note, so no desk
        // commentary is lost and every booking's history starts complete. The column never recorded
        // an author, so these are attributed plainly rather than to whoever happens to open them.
        DB::table('bookings')
            ->whereNotNull('internal_notes')
            ->where('internal_notes', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($bookings) {
                $rows = [];

                foreach ($bookings as $booking) {
                    $rows[] = [
                        'booking_id' => $booking->id,
                        'user_id' => null,
                        'author_name' => 'Recorded before notes were logged',
                        'author_role' => null,
                        'category' => null,
                        'body' => $booking->internal_notes,
                        // the best timestamp the old column can offer
                        'created_at' => $booking->updated_at ?: ($booking->created_at ?: now()),
                    ];
                }

                if ($rows !== []) {
                    DB::table('booking_internal_notes')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // bookings.internal_notes is left exactly as it is, so dropping these tables loses only the
        // per-note attribution and the edit history, never the current text.
        Schema::dropIfExists('booking_internal_note_revisions');
        Schema::dropIfExists('booking_internal_notes');
    }
};
