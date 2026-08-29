<?php

namespace App\Http\Requests;

use App\Models\Package;
use App\Support\CatalogRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePackageAvailabilityScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedules' => 'present|array',
            'schedules.*.availability_type' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'schedules.*.day_configuration' => 'nullable|array',
            'schedules.*.day_configuration.*' => [
                'string',
                function ($attribute, $value, $fail) {
                    $parts = explode('.', $attribute);
                    $index = $parts[1];
                    $type = $this->input("schedules.{$index}.availability_type");

                    if ($type === 'weekly' && $value) {
                        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        if (!in_array(strtolower($value), $validDays)) {
                            $fail('Day configuration must be a valid day name (e.g., monday, tuesday).');
                        }
                    }

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
            'schedules.*.min_participants' => 'nullable|integer|min:1|max:10000',
            'schedules.*.priority' => 'nullable|integer|min:0',
            'schedules.*.is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $routePackage = $this->route('package');
            $package = $routePackage instanceof Package ? $routePackage : Package::find($routePackage);
            $durationMinutes = $package ? $package->getDurationInMinutes() : 0;
            $schedules = $this->input('schedules', []);

            if (!is_array($schedules)) {
                return;
            }

            $context = ['package_id' => $package?->id, 'user_id' => auth()->id()];

            foreach ($schedules as $index => $schedule) {
                if (!is_array($schedule)) {
                    continue;
                }

                $label = 'Schedule ' . ($index + 1);
                $start = $schedule['time_slot_start'] ?? null;
                $end = $schedule['time_slot_end'] ?? null;

                if (CatalogRules::sameClock($start, $end)) {
                    CatalogRules::flag($validator, 'package_schedules', "schedules.{$index}.time_slot_end", "{$label}: start and end time cannot be the same. Use 00:00 as the end time for a window that runs to midnight.", $context + ['index' => $index]);
                    continue;
                }

                $window = CatalogRules::windowMinutes($start, $end);

                if ($window !== null && $durationMinutes > 0 && $durationMinutes > $window) {
                    CatalogRules::flag($validator, 'package_schedules', "schedules.{$index}.time_slot_end", "{$label}: the {$window}-minute window is shorter than the {$durationMinutes}-minute package duration, so no time slot could ever be offered.", $context + ['index' => $index, 'window' => $window, 'duration' => $durationMinutes]);
                }

                $minOverride = $schedule['min_participants'] ?? null;

                if ($minOverride !== null && $package) {
                    $ceiling = $package->max_participants !== null ? (int) $package->max_participants : null;
                    $cap = $package->effectiveTicketCap();
                    $limit = $ceiling !== null && $cap !== null ? min($ceiling, $cap) : ($ceiling ?? $cap);

                    if ($limit !== null && (int) $minOverride > $limit) {
                        CatalogRules::flag($validator, 'package_schedules', "schedules.{$index}.min_participants", "{$label}: a minimum of {$minOverride} exceeds the {$limit} this package can take in one slot, so no booking could ever be made on those days.", $context + ['index' => $index, 'min_override' => (int) $minOverride, 'limit' => $limit]);
                    }
                }
            }

            $active = array_filter($schedules, fn ($s) => is_array($s) && filter_var($s['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN));
            $keys = array_keys($active);

            for ($i = 0; $i < count($keys); $i++) {
                for ($j = $i + 1; $j < count($keys); $j++) {
                    $a = $active[$keys[$i]];
                    $b = $active[$keys[$j]];

                    if ((int) ($a['priority'] ?? 0) !== (int) ($b['priority'] ?? 0)) {
                        continue;
                    }

                    if (($a['availability_type'] ?? null) === 'monthly' && ($b['availability_type'] ?? null) === 'monthly') {
                        $shared = array_intersect(array_map('strtolower', (array) ($a['day_configuration'] ?? [])), array_map('strtolower', (array) ($b['day_configuration'] ?? [])));
                    } else {
                        $shared = array_intersect(CatalogRules::scheduleDays($a), CatalogRules::scheduleDays($b));
                    }

                    if ($shared === []) {
                        continue;
                    }

                    CatalogRules::flag($validator, 'package_schedules', "schedules.{$keys[$j]}.priority", sprintf('Schedules %d and %d both apply on %s with the same priority; give one a higher priority so it is clear which one wins.', $keys[$i] + 1, $keys[$j] + 1, ucfirst((string) reset($shared))), $context + ['indexes' => [$keys[$i], $keys[$j]]]);
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'schedules.present' => 'Schedules must be provided, even as an empty list',
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
