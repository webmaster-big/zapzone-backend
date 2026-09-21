<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a booking note used to say, kept from the moment someone changed it.
 *
 * A note can be corrected — staff have to be able to fix their own words — but the version they
 * replaced is never thrown away. These rows are the part that is genuinely permanent, so they
 * refuse to be edited or deleted themselves.
 */
class BookingInternalNoteRevision extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_internal_note_id',
        'body',
        'category',
        'edited_by',
        'editor_name',
        'editor_role',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('A note\'s history cannot be changed.');
        });

        static::deleting(function () {
            throw new \RuntimeException('A note\'s history cannot be deleted.');
        });
    }

    public function note()
    {
        return $this->belongsTo(BookingInternalNote::class, 'booking_internal_note_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
