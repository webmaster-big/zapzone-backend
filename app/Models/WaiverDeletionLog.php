<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverDeletionLog extends Model
{
    use HasFactory;

    protected $table = 'waiver_deletion_log';

    protected $fillable = [
        'company_id',
        'waiver_id',
        'deleted_by',
        'reason',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
