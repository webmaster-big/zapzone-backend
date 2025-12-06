<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePackageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // You can implement authorization logic here
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_id' => 'required|exists:locations,id',
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|string|max:255',
            'features' => 'nullable|string',
            'price' => 'required|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'price_per_additional' => 'nullable|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'max_participants' => 'required|integer|min:1',
            'duration' => 'required|integer|min:1',
            'duration_unit' => ['required', Rule::in(['hours', 'minutes'])],
            'price_per_additional_30min' => 'nullable|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'price_per_additional_1hr' => 'nullable|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
            'availability_type' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'available_days' => 'nullable|array',
            'available_week_days' => 'nullable|array',
            'available_month_days' => 'nullable|array',
            'image' => 'nullable|string',
            // NEW: Time slot validation
            'time_slot_start' => 'required|date_format:H:i',
            'time_slot_end' => 'required|date_format:H:i|after:time_slot_start',
            'time_slot_interval' => 'required|integer|min:15|max:240',
            'is_active' => 'boolean',
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'exists:attractions,id',
            'addon_ids' => 'nullable|array',
            'addon_ids.*' => 'exists:add_ons,id',
            'gift_card_ids' => 'nullable|array',
            'gift_card_ids.*' => 'exists:gift_cards,id',
            'promo_ids' => 'nullable|array',
            'promo_ids.*' => 'exists:promos,id',
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'exists:rooms,id',
            'partial_payment_percentage' => 'nullable|integer|min:0|max:100',
            'partial_payment_fixed' => 'nullable|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'Location is required',
            'location_id.exists' => 'Selected location does not exist',
            'name.required' => 'Package name is required',
            'description.required' => 'Package description is required',
            'price.required' => 'Package price is required',
            'price.min' => 'Package price must be greater than or equal to 0',
            'max_participants.required' => 'Maximum participants is required',
            'max_participants.min' => 'Maximum participants must be at least 1',
            'duration.required' => 'Duration is required',
            'duration.min' => 'Duration must be at least 1',
            'duration_unit.in' => 'Duration unit must be either hours or minutes',
            'availability_type.in' => 'Availability type must be daily, weekly, or monthly',
            'attraction_ids.array' => 'Attraction IDs must be an array',
            'attraction_ids.*.exists' => 'One or more selected attractions do not exist',
            'addon_ids.array' => 'Add-on IDs must be an array',
            'addon_ids.*.exists' => 'One or more selected add-ons do not exist',
            'gift_card_ids.array' => 'Gift card IDs must be an array',
            'gift_card_ids.*.exists' => 'One or more selected gift cards do not exist',
            'promo_ids.array' => 'Promo IDs must be an array',
            'promo_ids.*.exists' => 'One or more selected promos do not exist',
            'room_ids.array' => 'Room IDs must be an array',
            'room_ids.*.exists' => 'One or more selected rooms do not exist',
            'time_slot_start.required' => 'Time slot start is required',
            'time_slot_start.date_format' => 'Time slot start must be in HH:MM format',
            'time_slot_end.required' => 'Time slot end is required',
            'time_slot_end.date_format' => 'Time slot end must be in HH:MM format',
            'time_slot_end.after' => 'Time slot end must be after time slot start',
            'time_slot_interval.required' => 'Time slot interval is required',
            'time_slot_interval.min' => 'Time slot interval must be at least 15 minutes',
            'time_slot_interval.max' => 'Time slot interval must not exceed 240 minutes (4 hours)',
            'partial_payment_percentage.integer' => 'Partial payment percentage must be an integer',
            'partial_payment_percentage.min' => 'Partial payment percentage must be at least 0',
            'partial_payment_percentage.max' => 'Partial payment percentage must not exceed 100',
            'partial_payment_fixed.integer' => 'Partial payment fixed amount must be an integer',
            'partial_payment_fixed.min' => 'Partial payment fixed amount must be at least 0',
        ];
    }
}
