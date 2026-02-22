<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttractionAddOn extends Model
{
    protected $table = 'attraction_add_ons';

    protected $fillable = [
        'attraction_id',
        'add_on_id',
    ];

    public function attraction()
    {
        return $this->belongsTo(Attraction::class);
    }

    public function addOn()
    {
        return $this->belongsTo(AddOn::class, 'add_on_id');
    }
}
