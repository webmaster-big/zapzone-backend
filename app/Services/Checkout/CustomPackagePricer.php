<?php

namespace App\Services\Checkout;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Room;
use App\Services\AddOnRuleService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomPackagePricer
{
    public const STAFF_ONLY_CODE = 'CUSTOM_PACKAGE_STAFF_ONLY';

    public const PRICE_MISMATCH_CODE = 'CUSTOM_PRICE_MISMATCH';

    public const STAFF_ONLY_MESSAGE = 'Custom packages can only be booked by staff. Please sign in again and try once more.';

    public const PRICE_MISMATCH_MESSAGE = 'The total for this custom package changed. Please check the price and try again.';

    public const HAS_PACKAGE_MESSAGE = "A custom package booking can't also have a package.";

    public const SWITCH_TO_PACKAGE_MESSAGE = "A custom package booking can't be switched to a regular package.";

    public const NOT_CUSTOM_MESSAGE = 'Only custom package bookings can have custom package details.';

    public const DISCOUNT_MESSAGE = "Discounts can't be used with a custom package.";

    public const ATTRACTIONS_MESSAGE = "Attractions can't be added to a custom package.";

    public const ROOM_MESSAGE = 'Choose a space at this location.';

    public const ADD_ON_UNAVAILABLE_MESSAGE = "One of the add-ons isn't available at this location.";

    public const AMOUNT_PAID_MESSAGE = 'Amount paid cannot be more than the total.';

    public const TOTAL_TOO_LARGE_MESSAGE = 'The total for this custom package is too large. Please check the price and quantities.';

    public const CATEGORY_MESSAGE = 'Choose a category from the list.';

    public const PERCENT_FEE_MESSAGE = 'A percent fee must be between 0 and 100.';

    public const DISCOUNT_FIELDS = ['promo_code', 'gift_card_code', 'membership_id', 'promo_id', 'gift_card_id', 'membership_applied'];

    public const SIGNATURE_FIELDS = [
        'is_custom_package', 'custom_package_name', 'custom_package_category', 'custom_package_terms',
        'package_price_at_booking', 'package_pricing_type_at_booking', 'duration', 'duration_unit',
    ];

    public const MAX_TOTAL_CENTS = 9999999999;

    private const CEILING_CENTS = 100000000000000;

    private const DEFAULT_ADD_ON_MAX = 99;

    public function __construct(private BookingRepricer $repricer)
    {
    }

    public static function toCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public static function dollars(int $cents): float
    {
        return round($cents / 100, 2);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function minutes(mixed $duration, ?string $unit): int
    {
        $value = (float) $duration;

        return in_array($unit, ['hours', 'hours and minutes'], true) ? (int) round($value * 60) : (int) round($value);
    }

    public static function feeLabel(string $type, int $amount): string
    {
        if ($type === 'percent') {
            $whole = intdiv($amount, 100);
            $fraction = $amount % 100;

            return ($fraction === 0 ? (string) $whole : rtrim(sprintf('%d.%02d', $whole, $fraction), '0')) . '%';
        }

        return '$' . number_format($amount / 100, 2);
    }

    public function firstDiscountField(array $input): ?string
    {
        foreach (self::DISCOUNT_FIELDS as $field) {
            if (! empty($input[$field])) {
                return $field;
            }
        }

        return null;
    }

    public function normalizeTerms(mixed $input, ?string $keptCategory = null): array
    {
        $clean = $this->cleanTerms(is_array($input) ? $input : []);

        Validator::make(['custom_package' => $clean], [
            'custom_package.name' => ['required', 'string', 'max:120'],
            'custom_package.category' => ['required', 'string', 'max:255'],
            'custom_package.duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'custom_package.min_guests' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'custom_package.max_guests' => ['required', 'integer', 'min:1', 'max:10000'],
            'custom_package.pricing_type' => ['required', Rule::in(['per_booking', 'per_guest'])],
            'custom_package.price' => ['required', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'custom_package.fees' => ['array', 'max:20'],
            'custom_package.fees.*' => ['array'],
            'custom_package.fees.*.name' => ['required', 'string', 'max:100'],
            'custom_package.fees.*.type' => ['required', Rule::in(['flat', 'percent'])],
            'custom_package.fees.*.amount' => ['required', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'custom_package.one_time_add_ons' => ['array', 'max:50'],
            'custom_package.one_time_add_ons.*' => ['array'],
            'custom_package.one_time_add_ons.*.name' => ['required', 'string', 'max:100'],
            'custom_package.one_time_add_ons.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'custom_package.one_time_add_ons.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
        ], [], [
            'custom_package.name' => 'booking name',
            'custom_package.category' => 'category',
            'custom_package.duration_minutes' => 'duration',
            'custom_package.min_guests' => 'min. guests',
            'custom_package.max_guests' => 'max. guests',
            'custom_package.pricing_type' => 'pricing',
            'custom_package.price' => 'package price',
            'custom_package.fees' => 'fees',
            'custom_package.fees.*' => 'fee',
            'custom_package.fees.*.name' => 'fee name',
            'custom_package.fees.*.type' => 'fee type',
            'custom_package.fees.*.amount' => 'fee amount',
            'custom_package.one_time_add_ons' => 'one-time add-ons',
            'custom_package.one_time_add_ons.*' => 'one-time add-on',
            'custom_package.one_time_add_ons.*.name' => 'add-on name',
            'custom_package.one_time_add_ons.*.quantity' => 'add-on quantity',
            'custom_package.one_time_add_ons.*.unit_price' => 'add-on unit price',
        ])->validate();

        $minGuests = $clean['min_guests'] === null ? 1 : (int) $clean['min_guests'];
        $maxGuests = (int) $clean['max_guests'];
        $errors = [];

        if ($maxGuests < $minGuests) {
            $errors['custom_package.max_guests'] = "Max. guests must be at least {$minGuests}.";
        }

        foreach ($clean['fees'] as $index => $fee) {
            if ($fee['type'] === 'percent' && self::toCents($fee['amount']) > 10000) {
                $errors["custom_package.fees.{$index}.amount"] = self::PERCENT_FEE_MESSAGE;
            }
        }

        $category = $keptCategory !== null && $clean['category'] === $keptCategory
            ? $keptCategory
            : Category::query()->where('name', $clean['category'])->value('name');

        if ($category === null) {
            $errors['custom_package.category'] = self::CATEGORY_MESSAGE;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => (string) $clean['name'],
            'category' => (string) $category,
            'duration_minutes' => (int) $clean['duration_minutes'],
            'min_guests' => $minGuests,
            'max_guests' => $maxGuests,
            'pricing_type' => (string) $clean['pricing_type'],
            'price_cents' => self::toCents($clean['price']),
            'fees' => array_map(fn (array $fee) => [
                'name' => (string) $fee['name'],
                'type' => (string) $fee['type'],
                'amount' => self::toCents($fee['amount']),
            ], $clean['fees']),
            'one_time_add_ons' => array_map(fn (array $row) => [
                'name' => (string) $row['name'],
                'quantity' => (int) $row['quantity'],
                'unit_cents' => self::toCents($row['unit_price']),
            ], $clean['one_time_add_ons']),
        ];
    }

    public function termsFromBooking(Booking $booking): array
    {
        $range = $booking->customGuestRange();

        return [
            'name' => (string) $booking->custom_package_name,
            'category' => (string) $booking->custom_package_category,
            'duration_minutes' => self::minutes($booking->duration, $booking->duration_unit),
            'min_guests' => $range['min'] ?? 1,
            'max_guests' => $range['max'] ?? 0,
            'pricing_type' => $booking->package_pricing_type_at_booking === 'per_person' ? 'per_guest' : 'per_booking',
            'price_cents' => max(0, self::toCents($booking->package_price_at_booking ?? 0)),
            'fees' => array_map(fn (array $fee) => [
                'name' => $fee['name'],
                'type' => $fee['type'],
                'amount' => max(0, self::toCents($fee['amount'])),
            ], $booking->customFees()),
            'one_time_add_ons' => array_map(fn (array $row) => [
                'name' => $row['name'],
                'quantity' => $row['quantity'],
                'unit_cents' => max(0, self::toCents($row['unit_price'])),
            ], $booking->oneTimeAddOns()),
        ];
    }

    public function storedTerms(array $terms): array
    {
        return [
            'min_guests' => (int) $terms['min_guests'],
            'max_guests' => (int) $terms['max_guests'],
            'fees' => array_map(fn (array $fee) => [
                'name' => $fee['name'],
                'type' => $fee['type'],
                'amount' => self::dollars($fee['amount']),
            ], $terms['fees']),
            'one_time_add_ons' => array_map(fn (array $row) => [
                'name' => $row['name'],
                'quantity' => (int) $row['quantity'],
                'unit_price' => self::dollars($row['unit_cents']),
            ], $terms['one_time_add_ons']),
        ];
    }

    public function columnsFor(array $terms): array
    {
        return [
            'is_custom_package' => true,
            'custom_package_name' => $terms['name'],
            'custom_package_category' => $terms['category'],
            'custom_package_terms' => $this->storedTerms($terms),
            'duration' => number_format($terms['duration_minutes'], 2, '.', ''),
            'duration_unit' => 'minutes',
            'package_price_at_booking' => self::money($terms['price_cents']),
            'package_pricing_type_at_booking' => $terms['pricing_type'] === 'per_guest' ? 'per_person' : 'base',
            'price_per_additional_at_booking' => '0.00',
        ];
    }

    public function participantsError(array $terms, int $participants): ?string
    {
        $min = (int) $terms['min_guests'];
        $max = (int) $terms['max_guests'];

        if ($max < 1) {
            return null;
        }

        return $participants < $min || $participants > $max
            ? "Participants must be between {$min} and {$max}."
            : null;
    }

    public function storedLines(Booking $booking): array
    {
        $stored = [];

        foreach ($booking->addOns()->get() as $addOn) {
            $id = (int) $addOn->id;

            if (! isset($stored[$id])) {
                $stored[$id] = [
                    'id' => $id,
                    'quantity' => 0,
                    'unit_cents' => max(0, self::toCents($addOn->pivot->price_at_booking)),
                ];
            }

            $stored[$id]['quantity'] += (int) $addOn->pivot->quantity;
        }

        return $stored;
    }

    public function catalogLines(array $addOnLines, int $locationId, array $stored = []): array|string
    {
        if ($addOnLines === []) {
            return [];
        }

        $addOns = AddOn::query()
            ->whereIn('id', array_map(fn (array $line) => (int) $line['id'], $addOnLines))
            ->get()
            ->keyBy('id');
        $lines = [];

        foreach ($addOnLines as $line) {
            $id = (int) $line['id'];
            $quantity = (int) $line['quantity'];
            $addOn = $addOns->get($id);
            $kept = $stored[$id] ?? null;

            if (! $addOn
                || (int) $addOn->location_id !== $locationId
                || ($kept === null && (! $addOn->is_active || $addOn->price === null))) {
                return self::ADD_ON_UNAVAILABLE_MESSAGE;
            }

            $min = max(1, (int) ($addOn->min_quantity ?? 1));
            $max = $addOn->max_quantity !== null ? (int) $addOn->max_quantity : self::DEFAULT_ADD_ON_MAX;

            if ($quantity < $min || $quantity > $max) {
                return "{$addOn->name} needs a quantity between {$min} and {$max}.";
            }

            $lines[] = [
                'id' => $id,
                'quantity' => $quantity,
                'unit_cents' => $kept !== null ? $kept['unit_cents'] : max(0, self::toCents($addOn->price)),
            ];
        }

        return $lines;
    }

    public function price(array $terms, int $participants, array $catalogLines): array
    {
        $package = self::times($terms['price_cents'], $terms['pricing_type'] === 'per_guest' ? $participants : 1);

        $catalog = 0;
        foreach ($catalogLines as $line) {
            $catalog = self::plus($catalog, self::times($line['unit_cents'], $line['quantity']));
        }

        $oneTime = 0;
        foreach ($terms['one_time_add_ons'] as $row) {
            $oneTime = self::plus($oneTime, self::times($row['unit_cents'], $row['quantity']));
        }

        $subtotal = self::plus(self::plus($package, $catalog), $oneTime);

        $fees = [];
        $feeTotal = 0;
        foreach ($terms['fees'] as $fee) {
            $cents = $fee['type'] === 'percent'
                ? intdiv($subtotal * $fee['amount'] + 5000, 10000)
                : $fee['amount'];
            $feeTotal = self::plus($feeTotal, $cents);
            $fees[] = [
                'name' => $fee['name'],
                'type' => $fee['type'],
                'amount' => $fee['amount'],
                'cents' => $cents,
                'label' => self::feeLabel($fee['type'], $fee['amount']),
            ];
        }

        return [
            'package_cents' => $package,
            'catalog_cents' => $catalog,
            'one_time_cents' => $oneTime,
            'subtotal_cents' => $subtotal,
            'fee_cents' => $feeTotal,
            'fees' => $fees,
            'total_cents' => self::plus($subtotal, $feeTotal),
        ];
    }

    public function appliedFees(array $priced): array
    {
        return array_map(fn (array $fee) => [
            'fee_name' => $fee['name'],
            'fee_amount' => self::dollars($fee['cents']),
            'fee_application_type' => 'additive',
            'fee_calculation_type' => $fee['type'] === 'percent' ? 'percentage' : 'fixed',
            'fee_label' => $fee['label'],
            'fee_support_id' => null,
        ], $priced['fees']);
    }

    public function assess(array $terms, array $input): array
    {
        $locationId = (int) $input['location_id'];
        $participants = (int) $input['participants'];
        $stored = $input['stored_lines'] ?? [];
        $lineInput = $input['add_on_lines'] ?? array_values($stored);
        $roomId = (int) ($input['room_id'] ?? 0);

        if ($roomId < 1 || ! Room::query()->whereKey($roomId)->where('location_id', $locationId)->exists()) {
            return $this->refusal('room_id', self::ROOM_MESSAGE);
        }

        if ($message = $this->participantsError($terms, $participants)) {
            return $this->refusal('participants', $message);
        }

        $lines = $this->catalogLines($lineInput, $locationId, $stored);

        if (is_string($lines)) {
            return $this->refusal('additional_addons', $lines);
        }

        $priced = $this->price($terms, $participants, $lines);

        if ($priced['total_cents'] > self::MAX_TOTAL_CENTS) {
            return $this->refusal('total_amount', self::TOTAL_TOO_LARGE_MESSAGE);
        }

        $clientTotal = $input['client_total'] ?? null;

        if ($clientTotal !== null && abs(self::toCents($clientTotal) - $priced['total_cents']) > 1) {
            return ['refusal' => ['status' => 422, 'body' => [
                'success' => false,
                'code' => self::PRICE_MISMATCH_CODE,
                'message' => self::PRICE_MISMATCH_MESSAGE,
                'expected_total' => self::dollars($priced['total_cents']),
            ]]];
        }

        if (self::toCents($input['amount_paid'] ?? 0) > $priced['total_cents']) {
            return $this->refusal('amount_paid', self::AMOUNT_PAID_MESSAGE);
        }

        return [
            'refusal' => null,
            'priced' => $priced,
            'lines' => $lines,
            'total_amount' => self::money($priced['total_cents']),
            'applied_fees' => $this->appliedFees($priced),
        ];
    }

    public function keepStoredTotal(Booking $booking, mixed $clientTotal, mixed $amountPaid): array
    {
        $totalCents = self::toCents($booking->total_amount);

        if ($clientTotal !== null && abs(self::toCents($clientTotal) - $totalCents) > 1) {
            return ['refusal' => ['status' => 422, 'body' => [
                'success' => false,
                'code' => self::PRICE_MISMATCH_CODE,
                'message' => self::PRICE_MISMATCH_MESSAGE,
                'expected_total' => self::dollars($totalCents),
            ]]];
        }

        if ($amountPaid === null) {
            return ['refusal' => null, 'amount_paid' => null];
        }

        return ['refusal' => null, 'amount_paid' => self::money(min(self::toCents($amountPaid), $totalCents))];
    }

    public function quote(Booking $booking, array $intent): array
    {
        $terms = $this->termsFromBooking($booking);
        $participants = max(1, (int) ($intent['participants'] ?? $booking->participants));
        $stored = $this->storedLines($booking);
        $addOnsGiven = array_key_exists('additional_addons', $intent) && $intent['additional_addons'] !== null;
        $lineInput = $addOnsGiven
            ? app(AddOnRuleService::class)->normalize((array) $intent['additional_addons'], 'addon_id', 'price_at_booking')
            : array_values($stored);
        $lines = $this->quoteLines($lineInput, $stored);
        $priced = $this->price($terms, $participants, $lines);
        $applied = $this->appliedFees($priced);
        $total = self::dollars($priced['total_cents']);
        $additive = self::dollars($priced['fee_cents']);

        if (! $addOnsGiven && $participants === (int) $booking->participants) {
            $applied = is_array($booking->applied_fees) ? array_values($booking->applied_fees) : [];
            $total = self::dollars(self::toCents($booking->total_amount));
            $additive = self::dollars(array_sum(array_map(fn ($fee) => self::toCents($fee['fee_amount'] ?? 0), $applied)));
        }

        $paid = round((float) $booking->amount_paid, 2);

        $quote = [
            'subtotal' => self::dollars($priced['subtotal_cents']),
            'lines' => $this->quoteLineRows($terms, $priced, $lines),
            'fees' => $applied,
            'persist_fees' => $applied,
            'additive_fees' => $additive,
            'special_pricing_discount' => 0.0,
            'membership_discount' => 0.0,
            'redeemed_credit' => 0.0,
            'discount_amount' => 0.0,
            'applied_discounts' => null,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'remaining_balance' => round($total - $paid, 2),
            'payment_status' => $this->repricer->derive($paid, $total, $booking->payment_status),
            'delta' => round($total - round((float) $booking->total_amount, 2), 2),
            'pricing_consistent' => true,
        ];

        if ($error = $this->participantsError($terms, $participants)) {
            $quote['participants_error'] = $error;
        }

        return $quote;
    }

    public function signature(array $values): ?string
    {
        if (empty($values['is_custom_package'])) {
            return null;
        }

        $terms = is_array($values['custom_package_terms'] ?? null) ? $values['custom_package_terms'] : [];

        return json_encode([
            trim((string) ($values['custom_package_name'] ?? '')),
            trim((string) ($values['custom_package_category'] ?? '')),
            self::toCents($values['package_price_at_booking'] ?? 0),
            (string) ($values['package_pricing_type_at_booking'] ?? ''),
            self::minutes($values['duration'] ?? 0, $values['duration_unit'] ?? null),
            (int) ($terms['min_guests'] ?? 1),
            (int) ($terms['max_guests'] ?? 0),
            array_map(fn (array $fee) => [
                trim((string) ($fee['name'] ?? '')),
                (string) ($fee['type'] ?? ''),
                self::toCents($fee['amount'] ?? 0),
            ], array_values(array_filter((array) ($terms['fees'] ?? []), 'is_array'))),
            array_map(fn (array $row) => [
                trim((string) ($row['name'] ?? '')),
                (int) ($row['quantity'] ?? 0),
                self::toCents($row['unit_price'] ?? 0),
            ], array_values(array_filter((array) ($terms['one_time_add_ons'] ?? []), 'is_array'))),
        ]);
    }

    public function moveRefusal(Booking $booking, int $locationId, mixed $roomId): ?array
    {
        if (! $booking->isCustomPackage()) {
            return null;
        }

        if (empty($roomId)) {
            return ['room_id', self::ROOM_MESSAGE];
        }

        $foreignAddOn = $booking->addOns()
            ->where(fn ($elsewhere) => $elsewhere->whereNull('add_ons.location_id')->orWhere('add_ons.location_id', '!=', $locationId))
            ->exists();

        return $foreignAddOn ? ['additional_addons', self::ADD_ON_UNAVAILABLE_MESSAGE] : null;
    }

    public function activityMetadata(Booking $booking): array
    {
        return [
            'package' => ['id' => null, 'name' => $booking->custom_package_name, 'custom' => true],
            'custom_package' => [
                'category' => $booking->custom_package_category,
                'pricing' => $booking->package_pricing_type_at_booking === 'per_person' ? 'per_guest' : 'per_booking',
                'price' => round((float) $booking->package_price_at_booking, 2),
                'fees' => $booking->customFees(),
                'one_time_add_ons' => $booking->oneTimeAddOns(),
            ],
        ];
    }

    private function quoteLines(array $lineInput, array $stored): array
    {
        $fresh = array_values(array_filter(
            array_map(fn (array $line) => (int) $line['id'], $lineInput),
            fn (int $id) => ! isset($stored[$id])
        ));
        $catalogPrices = $fresh === [] ? collect() : AddOn::query()->whereIn('id', $fresh)->pluck('price', 'id');

        return array_map(function (array $line) use ($stored, $catalogPrices) {
            $id = (int) $line['id'];

            return [
                'id' => $id,
                'quantity' => max(0, (int) $line['quantity']),
                'unit_cents' => isset($stored[$id])
                    ? $stored[$id]['unit_cents']
                    : max(0, self::toCents($catalogPrices->get($id) ?? 0)),
            ];
        }, $lineInput);
    }

    private function quoteLineRows(array $terms, array $priced, array $lines): array
    {
        $rows = [[
            'type' => 'package',
            'id' => null,
            'category' => $terms['category'],
            'unit_price' => self::dollars($priced['package_cents']),
            'quantity' => 1,
            'line_total' => self::dollars($priced['package_cents']),
            'client_unit' => null,
        ]];

        foreach ($lines as $line) {
            $rows[] = [
                'type' => 'addon',
                'id' => $line['id'],
                'category' => null,
                'unit_price' => self::dollars($line['unit_cents']),
                'quantity' => $line['quantity'],
                'line_total' => self::dollars(self::times($line['unit_cents'], $line['quantity'])),
                'client_unit' => null,
            ];
        }

        foreach ($terms['one_time_add_ons'] as $row) {
            $rows[] = [
                'type' => 'one_time_addon',
                'id' => null,
                'category' => null,
                'unit_price' => self::dollars($row['unit_cents']),
                'quantity' => $row['quantity'],
                'line_total' => self::dollars(self::times($row['unit_cents'], $row['quantity'])),
                'client_unit' => null,
            ];
        }

        return $rows;
    }

    private function refusal(string $field, string $message): array
    {
        return ['refusal' => ['status' => 422, 'body' => [
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ]]];
    }

    private function cleanTerms(array $raw): array
    {
        $clean = [];

        foreach (['name', 'category', 'duration_minutes', 'min_guests', 'max_guests', 'pricing_type', 'price'] as $key) {
            $clean[$key] = $this->clean($raw[$key] ?? null);
        }

        $clean['fees'] = $this->cleanRows($raw['fees'] ?? null, ['name', 'type', 'amount']);
        $clean['one_time_add_ons'] = $this->cleanRows($raw['one_time_add_ons'] ?? null, ['name', 'quantity', 'unit_price']);

        return $clean;
    }

    private function cleanRows(mixed $rows, array $keys): mixed
    {
        if ($rows === null || $rows === '') {
            return [];
        }

        if (! is_array($rows)) {
            return $rows;
        }

        return array_map(function ($row) use ($keys) {
            if (! is_array($row)) {
                return $row;
            }

            $clean = [];
            foreach ($keys as $key) {
                $clean[$key] = $this->clean($row[$key] ?? null);
            }

            return $clean;
        }, array_values($rows));
    }

    private function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function times(int $a, int $b): int
    {
        if ($a <= 0 || $b <= 0) {
            return 0;
        }

        return $a > intdiv(self::CEILING_CENTS, $b) ? self::CEILING_CENTS : min(self::CEILING_CENTS, $a * $b);
    }

    private static function plus(int $a, int $b): int
    {
        return min(self::CEILING_CENTS, max(0, $a) + max(0, $b));
    }
}
