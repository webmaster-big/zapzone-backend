<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AddOnRuleService
{
    public function isStaff(mixed $actor): bool
    {
        return $actor instanceof User;
    }

    public function normalize(array $rows, string $idKey, string $priceKey): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $id = (int) ($row[$idKey] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 1);

            if ($id < 1 || $quantity < 1) {
                continue;
            }

            if (!isset($merged[$id])) {
                $merged[$id] = ['id' => $id, 'quantity' => 0, 'price' => $row[$priceKey] ?? 0];
            }

            $merged[$id]['quantity'] += $quantity;
        }

        return array_values($merged);
    }

    public function assertQuantities(array $lines, ?int $packageId, bool $staff, string $context): void
    {
        if ($lines === []) {
            return;
        }

        $mode = (string) config('booking_rules.add_on_quantity', 'log');
        $addOns = AddOn::whereIn('id', array_column($lines, 'id'))->get()->keyBy('id');

        foreach ($lines as $line) {
            $addOn = $addOns->get($line['id']);

            if (!$addOn) {
                continue;
            }

            $quantity = (int) $line['quantity'];
            $min = $this->effectiveMinimum($addOn, $packageId);
            $max = $addOn->max_quantity !== null ? (int) $addOn->max_quantity : null;

            if ($max !== null && $quantity > $max) {
                $this->violation($mode, $staff, "You can add at most {$max} x {$addOn->name}.", $context, $addOn, $quantity);
            }

            if ($quantity < $min) {
                $this->violation($mode, $staff, "You need at least {$min} x {$addOn->name}.", $context, $addOn, $quantity);
            }
        }
    }

    public function assertForced(Package $package, array $lines, bool $staff, string $context): void
    {
        $mode = (string) config('booking_rules.forced_add_ons', 'log');

        if ($staff && $mode !== 'all') {
            return;
        }
        $chosen = collect($lines)->keyBy('id');

        $forced = $package->addOns()
            ->where('is_active', true)
            ->where('is_force_add_on', true)
            ->get();

        foreach ($forced as $addOn) {
            $rule = $this->packageRule($addOn, (int) $package->id);

            if (!$rule || (int) ($rule['minimum_quantity'] ?? 0) < 1) {
                continue;
            }

            $required = (int) $rule['minimum_quantity'];
            $have = (int) ($chosen->get($addOn->id)['quantity'] ?? 0);

            if ($have >= $required) {
                continue;
            }

            $message = "{$addOn->name} is required with this package (at least {$required}).";

            if (in_array($mode, ['enforce', 'customer', 'all'], true)) {
                throw new RuntimeException($message);
            }

            Log::warning('Forced add-on missing (log-only)', [
                'context' => $context,
                'package_id' => $package->id,
                'add_on_id' => $addOn->id,
                'required' => $required,
                'have' => $have,
            ]);
        }
    }

    private function effectiveMinimum(AddOn $addOn, ?int $packageId): int
    {
        if ($addOn->is_force_add_on && $packageId) {
            $rule = $this->packageRule($addOn, $packageId);

            if ($rule && (int) ($rule['minimum_quantity'] ?? 0) > 0) {
                return (int) $rule['minimum_quantity'];
            }
        }

        return max(1, (int) ($addOn->min_quantity ?? 1));
    }

    private function packageRule(AddOn $addOn, int $packageId): ?array
    {
        foreach ((array) ($addOn->price_each_packages ?? []) as $entry) {
            if ((int) ($entry['package_id'] ?? 0) === $packageId) {
                return $entry;
            }
        }

        return null;
    }

    private function violation(string $mode, bool $staff, string $message, string $context, AddOn $addOn, int $quantity): void
    {
        if ($mode === 'all' || ($mode === 'customer' && !$staff)) {
            throw new RuntimeException($message);
        }

        Log::warning('Add-on quantity rule (log-only)', [
            'context' => $context,
            'staff' => $staff,
            'add_on_id' => $addOn->id,
            'quantity' => $quantity,
            'message' => $message,
        ]);
    }
}
