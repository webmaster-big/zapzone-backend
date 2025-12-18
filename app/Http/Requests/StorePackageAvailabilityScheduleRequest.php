<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePackageAvailabilityScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schedules' => 'required|array|min:1',
            'schedules.*.availability_type' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'schedules.*.day_configuration' => 'nullable|array',
            'schedules.*.day_configuration.*' => [
                'string',
                function ($attribute, $value, $fail) {
                    // Extract schedule index from attribute path like 'schedules.0.day_configuration.0'
                    $parts = explode('.', $attribute);
                    $index = $parts[1];
                    $type = $this->input("schedules.{$index}.availability_type");

                    // Validate format for weekly (should be a day name)
                    if ($type === 'weekly' && $value) {
                        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        if (!in_array(strtolower($value), $validDays)) {
                            $fail('Day configuration must be a valid day name (e.g., monday, tuesday).');
                        }
                    }

                    // Validate format for monthly (should be occurrence-day pattern)
                    if ($type === 'monthly' && $value) {
                        $pattern = '/^(first|second|third|fourth|last)-(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i';
                        if (!preg_match($pattern, $value)) {
                            $fail('Day configuration must follow the pattern: occurrence-day (e.g., last-sunday, first-monday).');
                        }
                    }
                },
            ],
            'schedules.*.time_slot_start' => 'required|date_format:H:i',
            'schedules.*.time_slot_end' => 'required|date_format:H:i',
            'schedules.*.time_slot_interval' => 'required|integer|min:15|max:240',
            'schedules.*.priority' => 'nullable|integer|min:0',
            'schedules.*.is_active' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'schedules.required' => 'At least one schedule is required',
            'schedules.array' => 'Schedules must be an array',
            'schedules.*.availability_type.required' => 'Availability type is required for each schedule',
            'schedules.*.availability_type.in' => 'Availability type must be daily, weekly, or monthly',
            'schedules.*.time_slot_start.required' => 'Time slot start is required for each schedule',
            'schedules.*.time_slot_start.date_format' => 'Time slot start must be in HH:MM format',
            'schedules.*.time_slot_end.required' => 'Time slot end is required for each schedule',
            'schedules.*.time_slot_end.date_format' => 'Time slot end must be in HH:MM format',
            'schedules.*.time_slot_interval.required' => 'Time slot interval is required for each schedule',
            'schedules.*.time_slot_interval.min' => 'Time slot interval must be at least 15 minutes',
            'schedules.*.time_slot_interval.max' => 'Time slot interval must not exceed 240 minutes (4 hours)',
            'schedules.*.priority.integer' => 'Priority must be an integer',
            'schedules.*.priority.min' => 'Priority must be at least 0',
        ];
    }
}
