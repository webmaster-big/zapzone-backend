<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorizeNetAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'environment' => $this->environment,
            'is_active' => $this->is_active,
            'connected_at' => $this->connected_at,
            'last_tested_at' => $this->last_tested_at,
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'city' => $this->location->city,
                'state' => $this->location->state,
            ],
        ];
    }
}
