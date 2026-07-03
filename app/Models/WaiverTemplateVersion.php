<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaiverTemplateVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'waiver_template_id',
        'version',
        'body_text',
        'clause_config',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'clause_config' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WaiverTemplate::class, 'waiver_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class);
    }
}
