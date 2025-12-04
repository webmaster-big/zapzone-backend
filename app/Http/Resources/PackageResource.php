<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'features' => $this->features,
            'price' => $this->price,
            'price_per_additional' => $this->price_per_additional,
            'max_participants' => $this->max_participants,
            'duration' => $this->duration,
            'duration_unit' => $this->duration_unit,
            'price_per_additional_30min' => $this->price_per_additional_30min,
            'price_per_additional_1hr' => $this->price_per_additional_1hr,
            'availability_type' => $this->availability_type,
            'available_days' => $this->available_days,
            'available_week_days' => $this->available_week_days,
            'available_month_days' => $this->available_month_days,
            'time_slot_start' => $this->time_slot_start,
            'time_slot_end' => $this->time_slot_end,
            'time_slot_interval' => $this->time_slot_interval,
            'image' => $this->image,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relationships
            'location' => $this->whenLoaded('location'),
            'attractions' => $this->whenLoaded('attractions'),
            'add_ons' => $this->whenLoaded('addOns'),
            'rooms' => $this->whenLoaded('rooms'),
            'gift_cards' => $this->whenLoaded('giftCards'),
            'promos' => $this->whenLoaded('promos'),
            'bookings_count' => $this->when($this->relationLoaded('bookings'), function () {
                return $this->bookings->count();
            }),
            'partial_payment_percentage' => $this->partial_payment_percentage,
            'partial_payment_fixed' => $this->partial_payment_fixed,
        ];
    }
}
