<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One entry in a booking's internal log.
 *
 * A note can be corrected — staff have to be able to fix a typo or sharpen a warning, and a log
 * nobody can tidy stops being read. What is permanent is the record: every superseded version is
 * copied into booking_internal_note_revisions before the change lands, and nothing is ever
 * deleted. So the log always answers "what does it say now" and "what did it say before, and who
 * changed it" — which is the part that actually matters.
 */
class BookingInternalNote extends Model
{
    use HasFactory;

    /** The kinds of thing a note is meant to document. Free notes stay allowed. */
    public const CATEGORIES = [
        'refund' => 'Refund',
        'participants' => 'Participant change',
        'booking_change' => 'Booking change',
        'special_request' => 'Special request',
        'safety' => 'Allergy or safety concern',
        'payment' => 'Payment exception',
        'general' => 'General',
    ];

    /** Edits are tracked in edited_at, so Eloquent's own updated_at would only be noise. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_id',
        'user_id',
        'author_name',
        'author_role',
        'category',
        'body',
        'edited_at',
        'edited_by',
        'editor_name',
        'editor_role',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $note) {
            // keep what it said before this edit. Written first, and inside the caller's
            // transaction, so a failed save cannot leave a version unaccounted for.
            if (! $note->isDirty(['body', 'category'])) {
                return;
            }

            $editor = $note->edited_by ? User::find($note->edited_by) : auth()->user();
            $editorName = $editor instanceof User ? ActivityLog::describeActor($editor) : 'Unknown employee';
            $editorRole = $editor instanceof User ? $editor->role : null;

            BookingInternalNoteRevision::create([
                'booking_internal_note_id' => $note->getKey(),
                'body' => (string) $note->getOriginal('body'),
                'category' => $note->getOriginal('category'),
                'edited_by' => $editor instanceof User ? $editor->getKey() : null,
                'editor_name' => $editorName,
                'editor_role' => $editorRole,
            ]);

            $note->edited_at = now();
            $note->edited_by = $editor instanceof User ? $editor->getKey() : null;
            $note->editor_name = $editorName;
            $note->editor_role = $editorRole;
        });

        static::deleting(function () {
            // the one thing that stays absolute: a note never disappears from a booking's history
            throw new \RuntimeException('A note cannot be deleted. Edit it instead — the previous version is kept.');
        });

        static::creating(function (self $note) {
            if ($note->author_name === null || $note->author_role === null) {
                $actor = $note->user_id ? User::find($note->user_id) : auth()->user();

                if ($actor instanceof User) {
                    $note->author_name = $note->author_name ?? ActivityLog::describeActor($actor);
                    $note->author_role = $note->author_role ?? $actor->role;
                }
            }

            // never store a blank author on a permanent record
            if (! is_string($note->author_name) || trim($note->author_name) === '') {
                $note->author_name = 'Unknown employee';
            }
        });

        static::created(function (self $note) {
            self::refreshBookingSummary((int) $note->booking_id);
        });

        static::updated(function (self $note) {
            self::refreshBookingSummary((int) $note->booking_id);
        });
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /** Every superseded version, newest first. */
    public function revisions()
    {
        return $this->hasMany(BookingInternalNoteRevision::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Keep bookings.internal_notes as a readable digest of the log.
     *
     * The column is no longer where notes live, but a dozen places still read it — the schedule
     * badges, the amber note icon, the admin list and export, three staff PDFs. Rebuilding it here
     * means every one of them keeps working without being touched.
     *
     * Written with the query builder on purpose: this must not touch the booking's updated_at or
     * fire its events, because adding a note is not a change to the booking.
     */
    public static function refreshBookingSummary(int $bookingId): void
    {
        $notes = self::query()
            ->where('booking_id', $bookingId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['author_name', 'category', 'body', 'created_at', 'edited_at', 'editor_name']);

        $summary = $notes
            ->map(function (self $note) {
                $when = $note->created_at?->format('M j, Y g:i A') ?? 'Date unknown';
                $label = $note->category ? (self::CATEGORIES[$note->category] ?? $note->category).' — ' : '';
                $edited = $note->edited_at
                    ? ' (edited '.$note->edited_at->format('M j, Y g:i A').' by '.($note->editor_name ?: 'someone').')'
                    : '';

                return "[{$when} · {$note->author_name}{$edited}] {$label}{$note->body}";
            })
            ->implode("\n\n");

        DB::table('bookings')->where('id', $bookingId)->update([
            'internal_notes' => $summary === '' ? null : $summary,
        ]);
    }
}
