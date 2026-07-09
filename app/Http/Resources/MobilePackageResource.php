<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Lightweight package representation for the mobileApp.
class MobilePackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'package_type' => $this->package_type,
            'price' => $this->price,
            'price_per_additional' => $this->price_per_additional,
            'price_per_additional_30min' => $this->price_per_additional_30min,
            'price_per_additional_1hr' => $this->price_per_additional_1hr,
            'duration' => $this->duration,
            'duration_unit' => $this->duration_unit,
            'min_participants' => $this->min_participants,
            'max_participants' => $this->max_participants,
            'min_booking_notice_hours' => $this->min_booking_notice_hours,
            'booking_window_days' => $this->booking_window_days,
            'has_guest_of_honor' => $this->has_guest_of_honor,
            'partial_payment_percentage' => $this->partial_payment_percentage,
            'partial_payment_fixed' => $this->partial_payment_fixed,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,

            'location' => $this->whenLoaded('location', function () {
                return [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                ];
            }),
        ];
    }
}
