<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingInternalNote;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\BookingAddOn;
use App\Models\PackageTimeSlot;
use App\Services\SlotCapacityGuard;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BookingCsvImportService
{
    protected const STATUS_MAP = [
        'done' => 'completed',
        'approved' => 'confirmed',
        'pending' => 'pending',
        'cancelled' => 'cancelled',
        'rejected' => 'cancelled',
        'confirmed' => 'confirmed',
        'checked-in' => 'checked-in',
        'completed' => 'completed',
    ];

    protected const VALID_PAYMENT_METHODS = ['card', 'in-store', 'paylater', 'authorize.net'];

    protected const NAME_FILLER_WORDS = ['a', 'an', 'and', 'the', 'of', 'for', 'with', 'package', 'packages', 'pkg'];

    protected array $rowWarnings = [];

    public function parseFile(string $filePath, string $originalName = ''): array
    {
        $extension = strtolower(pathinfo($originalName ?: $filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->parseExcel($filePath);
        }

        return $this->parseCsv($filePath);
    }

    public function parseCsv(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('CSV file is empty or has no header row');
        }

        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
        $header = array_map('trim', $header);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                continue; // Skip malformed rows
            }
            $rows[] = array_combine($header, $row);
        }

        fclose($handle);

        return $rows;
    }

    public function parseExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];

        $data = $worksheet->toArray(null, true, true, false);

        if (empty($data)) {
            throw new \RuntimeException('Excel file is empty');
        }

        $header = array_map('trim', array_map('strval', $data[0]));

        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            if (count($row) !== count($header)) {
                continue;
            }

            $rows[] = array_combine($header, $row);
        }

        return $rows;
    }

    public function processRows(array $rows, int $locationId, ?int $createdBy = null, bool $skipDuplicates = true): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $importedBookings = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            $this->rowWarnings = [];

            try {
                $result = $this->processRow($row, $index, $locationId, $createdBy, $skipDuplicates);

                if ($result === null) {
                    $skipped++;
                    continue;
                }

                $importedBookings[] = $result;
                $imported++;

                foreach ($this->rowWarnings as $message) {
                    $warnings[$message][] = $index + 2;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 2, // +2 for 1-based + header row
                    'data' => $this->sanitizeRowForLog($row),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'warnings' => collect($warnings)->map(fn ($rowNumbers, $message) => [
                'message' => $message,
                'rows' => $rowNumbers,
            ])->values()->all(),
            'bookings' => $importedBookings,
        ];
    }

    protected function processRow(array $row, int $index, int $locationId, ?int $createdBy, bool $skipDuplicates = true): ?Booking
    {
        $row = $this->normalizeRow($row);

        $appointmentDate = $this->parseDateTime($this->cell($row, 'appointment date'));
        if (!$appointmentDate) {
            throw new \RuntimeException('Invalid or missing appointment date');
        }

        $bookingDate = $appointmentDate->format('Y-m-d');
        $bookingTime = $appointmentDate->format('H:i');

        $createdDate = $this->parseDateTime($this->cell($row, 'created'));

        $customerName = trim((string) $this->cell($row, 'customer name'));
        $customerEmail = trim((string) $this->cell($row, 'customer email'));
        $customerPhone = trim((string) $this->cell($row, 'customer phone'));
        $customerAddress = trim((string) $this->cell($row, 'customer address'));

        if (empty($customerName) && empty($customerEmail)) {
            throw new \RuntimeException('Customer name and email are both empty');
        }

        $nameParts = $this->splitName($customerName);
        $addressParts = $this->parseAddress($customerAddress);
        $companyId = Location::find($locationId)?->company_id;

        if (!empty($customerEmail) && $companyId) {
            Contact::firstOrCreate(
                ['company_id' => $companyId, 'email' => $customerEmail],
                [
                    'location_id' => $locationId,
                    'first_name' => $nameParts['first_name'],
                    'last_name' => $nameParts['last_name'],
                    'phone' => $this->cleanPhone($customerPhone),
                    'address' => $addressParts['address'],
                    'city' => $addressParts['city'],
                    'state' => $addressParts['state'],
                    'zip' => $addressParts['zip'],
                    'country' => 'US',
                    'source' => 'booking',
                    'status' => 'active',
                    'created_by' => $createdBy,
                ]
            );
        }

        $serviceRaw = trim((string) $this->cell($row, 'service'));
        $parsed = $this->parseServiceAndAddons($serviceRaw);
        $packageName = $parsed['package_name'];

        $package = null;
        if ($packageName !== '') {
            $package = Package::where('location_id', $locationId)->where('name', $packageName)->first()
                ?? Package::where('location_id', $locationId)->where('name', 'LIKE', '%' . $packageName . '%')->first();
        }
        if (!$package && !empty($packageName)) {
            $basePackageName = trim(explode('|', $packageName)[0]);
            if ($basePackageName !== $packageName) {
                $package = Package::where('location_id', $locationId)->where('name', 'LIKE', '%' . $basePackageName . '%')->first();
            }
        }

        $packageMatchedByWords = false;
        if (!$package && !empty($packageName)) {
            $package = $this->matchPackageByWords($locationId, $packageName);
            $packageMatchedByWords = $package !== null;
        }

        $csvStaff = trim((string) $this->cell($row, 'staff', 'staff member'));

        $roomName = trim((string) $this->cell($row, 'party area'));
        $room = null;
        if (!empty($roomName) && strtolower($roomName) !== 'no area') {
            $room = Room::where('location_id', $locationId)
                ->where('name', $roomName)
                ->first();

            if (!$room) {
                $cleanRoomName = preg_replace('/\s*\(Any\)\s*$/i', '', $roomName);
                $room = Room::where('location_id', $locationId)
                    ->where('name', $cleanRoomName)
                    ->first();
            }

            if (!$room) {
                $cleanRoomName = preg_replace('/\s*\(Any\)\s*$/i', '', $roomName);
                $room = Room::where('location_id', $locationId)
                    ->where('name', 'LIKE', '%' . $cleanRoomName . '%')
                    ->first();
            }
        }

        if (!$room && $roomName === '' && $csvStaff !== '') {
            $room = Room::where('location_id', $locationId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($csvStaff)])
                ->first();
        }

        $csvPersons = trim((string) $this->cell($row, 'number of persons', 'persons', 'number of participants', 'participants'));
        $csvPersonsInt = is_numeric($csvPersons) ? (int) $csvPersons : null;

        $csvInternalNote = trim((string) $this->cell($row, 'internal note', 'internal notes'));

        $duration = (int) $this->cell($row, 'duration');
        $durationUnit = 'minutes';

        $paymentRaw = trim((string) $this->cell($row, 'payment'));
        $paymentInfo = $this->parsePayment($paymentRaw);

        $statusRaw = strtolower(trim((string) ($this->cell($row, 'status') ?? 'pending')));
        $status = self::STATUS_MAP[$statusRaw] ?? 'pending';

        $notes = trim((string) $this->cell($row, 'notes'));

        $guestOfHonorName = trim((string) $this->cell($row, "guest of honor's name", 'guest of honor name'));
        $guestOfHonorAge = trim((string) $this->cell($row, "guest of honor's age (or general age of group)", "guest of honor's age", 'guest of honor age'));
        $guestOfHonorAge = is_numeric($guestOfHonorAge) ? (int) $guestOfHonorAge : null;

        $externalId = trim((string) $this->cell($row, 'id'));
        if ($skipDuplicates) {
            $duplicateQuery = Booking::where('booking_date', $bookingDate)
                ->where('booking_time', $bookingTime)
                ->where('location_id', $locationId);
            if (!empty($customerEmail)) {
                $duplicateQuery->where('guest_email', $customerEmail);
            } elseif (!empty($customerName)) {
                $duplicateQuery->where('guest_name', $customerName);
            }
            if ($duplicateQuery->exists()) {
                return null; // Skip duplicate
            }
        }

        do {
            $referenceNumber = 'BK' . now()->format('Ymd') . strtoupper(Str::random(6));
        } while (Booking::where('reference_number', $referenceNumber)->exists());

        $participants = $csvPersonsInt ?? ($package?->min_participants !== null ? $package->effectiveMinParticipants($bookingDate) : 10);

        $internalNotesParts = [];

        if (!empty($externalId)) {
            $internalNotesParts[] = "Bookly ID: {$externalId}";
        }

        if (!$package && !empty($packageName)) {
            $internalNotesParts[] = "Package not found: {$serviceRaw}";
            $this->rowWarnings[] = "No package here matches \"{$packageName}\". Imported without a package.";
        }

        if ($packageMatchedByWords) {
            $internalNotesParts[] = "Package matched by name: {$packageName} → {$package->name}";
            $this->rowWarnings[] = "\"{$packageName}\" was matched to the package \"{$package->name}\".";
        }

        if (!$room && !empty($roomName) && strtolower($roomName) !== 'no area') {
            $internalNotesParts[] = "Space not found: {$roomName}";
            $this->rowWarnings[] = "No space here is named \"{$roomName}\". Imported without a space.";
        }

        if ($csvPersonsInt === null) {
            $internalNotesParts[] = "Number of persons missing from the file; set to {$participants}";
            $this->rowWarnings[] = "The file has no number of persons. The guest count was set to {$participants}.";
        }

        if (!empty($csvStaff)) {
            $internalNotesParts[] = "Staff: {$csvStaff}";
        }

        if (!empty($csvInternalNote)) {
            $internalNotesParts[] = "Bookly note: {$csvInternalNote}";
        }

        $internalNotes = null;
        if (!empty($internalNotesParts)) {
            $internalNotes = "[CSV Import]\n" . implode("\n", $internalNotesParts);
        }

        $paymentStatus = 'pending';
        if ($paymentInfo['total_amount'] > 0) {
            if ($paymentInfo['amount_paid'] >= $paymentInfo['total_amount']) {
                $paymentStatus = 'paid';
            } elseif ($paymentInfo['amount_paid'] > 0) {
                $paymentStatus = 'partial';
            }
        }

        $completedAt = $status === 'completed' ? now() : null;
        $cancelledAt = $status === 'cancelled' ? now() : null;

        if ($package) {
            $this->assertParticipants($package, $participants, $bookingDate);

            if ($status !== 'cancelled') {
                $this->assertCapacity($package, $bookingDate, $bookingTime, $participants);
            }
        }

        $booking = Booking::create([
            'reference_number' => $referenceNumber,
            'customer_id' => null,
            'package_id' => $package?->id,
            'location_id' => $locationId,
            'room_id' => $room?->id,
            'created_by' => $createdBy,
            'type' => 'package',
            'booking_date' => $bookingDate,
            'booking_time' => $bookingTime,
            'participants' => $participants,
            'package_price_at_booking' => $package?->price,
            'price_per_additional_at_booking' => $package?->price_per_additional,
            'package_pricing_type_at_booking' => $package?->pricing_type,
            'duration' => $duration ?: ($package?->duration ?? 120),
            'duration_unit' => $durationUnit,
            'total_amount' => $paymentInfo['total_amount'],
            'amount_paid' => $paymentInfo['amount_paid'],
            'payment_method' => $paymentInfo['payment_method'],
            'payment_status' => $paymentStatus,
            'status' => $status,
            'notes' => !empty($notes) ? $notes : null,
            'guest_name' => $customerName ?: null,
            'guest_email' => $customerEmail ?: null,
            'guest_phone' => !empty($customerPhone) ? $this->cleanPhone($customerPhone) : null,
            'guest_address' => $addressParts['address'],
            'guest_city' => $addressParts['city'],
            'guest_state' => $addressParts['state'],
            'guest_zip' => $addressParts['zip'],
            'guest_country' => !empty($customerAddress) ? 'US' : null,
            'guest_of_honor_name' => !empty($guestOfHonorName) ? $guestOfHonorName : null,
            'guest_of_honor_age' => $guestOfHonorAge,
            'completed_at' => $completedAt,
            'cancelled_at' => $cancelledAt,
        ]);

        if ($createdDate) {
            $booking->created_at = $createdDate;
            $booking->save();
        }

        // whatever the import worked out about this row goes into the booking's permanent log,
        // attributed to whoever ran the import
        if (!empty($internalNotes)) {
            $importActor = auth()->user();
            $importActorIsUser = $importActor instanceof \App\Models\User;

            BookingInternalNote::create([
                'booking_id' => $booking->id,
                'user_id' => $importActorIsUser ? $importActor->getKey() : null,
                'author_name' => $importActorIsUser ? null : 'CSV import',
                'category' => null,
                'body' => $internalNotes,
            ]);
        }

        if ($room && $package) {
            (new PackageTimeSlot())->allowOverlap()->fill([
                'package_id' => $package->id,
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'customer_id' => null,
                'user_id' => $createdBy,
                'booked_date' => $bookingDate,
                'time_slot_start' => $bookingTime,
                'duration' => $duration ?: ($package->duration ?? 120),
                'duration_unit' => $durationUnit,
                'status' => $status === 'cancelled' ? 'cancelled' : 'booked',
                'notes' => $notes ?: null,
            ])->save();
        }

        if (!empty($parsed['addons'])) {
            $unmatchedAddons = $this->attachAddons($booking, $package, $parsed['addons']);

            if (!empty($unmatchedAddons)) {
                $addonLines = [];
                foreach ($unmatchedAddons as $a) {
                    if (isset($a['fuzzy_matched_to'])) {
                        continue; // fuzzy matched is fine, skip
                    }
                    $qty = $a['quantity'] > 1 ? " (qty: {$a['quantity']})" : '';
                    $addonLines[] = "Add-on not found: {$a['name']}{$qty}";
                }
                if (!empty($addonLines)) {
                    $addonActor = auth()->user();
                    $addonActorIsUser = $addonActor instanceof \App\Models\User;

                    BookingInternalNote::create([
                        'booking_id' => $booking->id,
                        'user_id' => $addonActorIsUser ? $addonActor->getKey() : null,
                        'author_name' => $addonActorIsUser ? null : 'CSV import',
                        'category' => null,
                        'body' => "[CSV Import]\n" . implode("\n", $addonLines),
                    ]);
                }
            }
        }

        return $booking;
    }

    protected function parseDateTime($value): ?Carbon
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_numeric($value) && !is_string($value)) {
            try {
                $unixTimestamp = ((float) $value - 25569) * 86400;
                return Carbon::createFromTimestamp((int) $unixTimestamp, 'UTC');
            } catch (\Exception $e) {
                return null;
            }
        }

        $value = trim((string) $value);

        if (is_numeric($value) && strpos($value, '/') === false && strpos($value, '-') === false) {
            try {
                $unixTimestamp = ((float) $value - 25569) * 86400;
                return Carbon::createFromTimestamp((int) $unixTimestamp, 'UTC');
            } catch (\Exception $e) {
            }
        }

        $formats = [
            'n/j/Y G:i',       // 4/5/2026 13:00
            'n/j/Y H:i',       // 4/5/2026 13:00
            'm/d/Y G:i',       // 04/05/2026 13:00
            'm/d/Y H:i',       // 04/05/2026 13:00
            'n/j/Y g:i A',     // 4/5/2026 1:00 PM
            'm/d/Y g:i A',     // 04/05/2026 1:00 PM
            'n/j/Y H:i:s',     // 1/9/2026 20:53:00
            'm/d/Y H:i:s',     // 01/09/2026 20:53:00
            'Y-m-d H:i:s',     // 2026-04-05 13:00:00
            'Y-m-d H:i',       // 2026-04-05 13:00
            'Y-m-d\TH:i:s',   // ISO 8601
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed) {
                    return $parsed;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseServiceAndAddons(string $service): array
    {
        $addons = [];
        $packageName = $service;

        if (preg_match('/^(.+?)\s*\((.+)\)\s*$/', $service, $matches)) {
            $packageName = trim($matches[1]);
            $addonString = $matches[2];

            $addonParts = preg_split('/\s*,\s*/', $addonString);

            foreach ($addonParts as $part) {
                $part = trim($part);
                if (empty($part)) continue;

                $quantity = 1;
                if (preg_match('/^(\d+)\s*[×x]\s*(.+)$/iu', $part, $qMatch)) {
                    $quantity = (int) $qMatch[1];
                    $part = trim($qMatch[2]);
                }

                $addons[] = [
                    'name' => html_entity_decode($part, ENT_QUOTES, 'UTF-8'),
                    'quantity' => $quantity,
                ];
            }
        }

        return [
            'package_name' => $packageName,
            'addons' => $addons,
        ];
    }

    protected function attachAddons(Booking $booking, ?Package $package, array $addonList): array
    {
        $unmatchedAddons = [];

        $packageAddons = $package ? $package->addOns()->get() : collect();

        foreach ($addonList as $addonInfo) {
            $addonName = $addonInfo['name'];
            $quantity = $addonInfo['quantity'];
            $matchType = 'none';

            $addon = $packageAddons->first(function ($a) use ($addonName) {
                return Str::lower($a->name) === Str::lower($addonName);
            });
            if ($addon) {
                $matchType = 'exact';
            }

            if (!$addon) {
                $addon = \App\Models\AddOn::where(function ($q) use ($booking) {
                    $q->where('location_id', $booking->location_id)->orWhereNull('location_id');
                })->whereRaw('LOWER(name) = ?', [Str::lower($addonName)])->first();
                if ($addon) {
                    $matchType = 'exact';
                }
            }

            if (!$addon) {
                $addon = \App\Models\AddOn::where(function ($q) use ($booking) {
                    $q->where('location_id', $booking->location_id)->orWhereNull('location_id');
                })->where('name', 'LIKE', '%' . $addonName . '%')->first();
                if ($addon) {
                    $matchType = 'fuzzy';
                }
            }

            if ($addon) {
                BookingAddOn::create([
                    'booking_id' => $booking->id,
                    'add_on_id' => $addon->id,
                    'quantity' => $quantity,
                    'price_at_booking' => $addon->price ?? 0,
                ]);

                if ($matchType === 'fuzzy') {
                    $unmatchedAddons[] = [
                        'name' => $addonName,
                        'quantity' => $quantity,
                        'fuzzy_matched_to' => $addon->name,
                        'fuzzy_matched_id' => $addon->id,
                    ];
                }
            } else {
                $unmatchedAddons[] = [
                    'name' => $addonName,
                    'quantity' => $quantity,
                ];
            }
        }

        return $unmatchedAddons;
    }

    protected function parsePayment(string $payment): array
    {
        $result = [
            'amount_paid' => 0,
            'total_amount' => 0,
            'payment_method' => null,
            'payment_status_raw' => null,
        ];

        if (empty($payment)) {
            return $result;
        }

        if (stripos($payment, 'Authorize') !== false) {
            $result['payment_method'] = 'authorize.net';
        } elseif (stripos($payment, 'Stripe') !== false) {
            $result['payment_method'] = 'card';
        } elseif (stripos($payment, 'PayPal') !== false) {
            $result['payment_method'] = 'card';
        } elseif (stripos($payment, 'cash') !== false) {
            $result['payment_method'] = 'in-store';
        } else {
            $result['payment_method'] = 'paylater';
        }

        if (stripos($payment, 'Completed') !== false) {
            $result['payment_status_raw'] = 'completed';
        } elseif (stripos($payment, 'Pending') !== false) {
            $result['payment_status_raw'] = 'pending';
        }

        if (preg_match('/\$?([\d,.]+)\s+of\s+\$?([\d,.]+)/i', $payment, $matches)) {
            $result['amount_paid'] = (float) str_replace(',', '', $matches[1]);
            $result['total_amount'] = (float) str_replace(',', '', $matches[2]);
        } elseif (preg_match('/\$?(\d[\d,]*(?:\.\d{1,2})?)/', $payment, $matches)) {
            $amount = (float) str_replace(',', '', $matches[1]);
            $result['total_amount'] = $amount;
            if ($result['payment_status_raw'] === 'completed') {
                $result['amount_paid'] = $amount;
            }
        }

        return $result;
    }

    protected function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    protected function parseAddress(string $address): array
    {
        $result = [
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
        ];

        if (empty($address)) {
            return $result;
        }

        $parts = array_map('trim', explode(',', $address));
        $parts = array_filter($parts, fn($p) => !empty($p));
        $parts = array_values($parts);

        if (count($parts) >= 3) {
            $result['address'] = $parts[0];

            foreach ($parts as $i => $part) {
                if ($i === 0) continue;

                if (preg_match('/^\d{5}(-\d{4})?$/', $part)) {
                    $result['zip'] = $part;
                } elseif (preg_match('/^[A-Z]{2}$/i', $part) && !$result['state']) {
                    $result['state'] = strtoupper($part);
                } elseif (!$result['city'] && !preg_match('/^\d/', $part) && strlen($part) > 2) {
                    $result['city'] = $part;
                }
            }
        } elseif (count($parts) >= 1) {
            $result['address'] = implode(', ', $parts);
        }

        return $result;
    }

    protected function cleanPhone(string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        $cleaned = preg_replace('/[^\d]/', '', $phone);

        return $cleaned;
    }

    protected function sanitizeRowForLog(array $row): array
    {
        $row = $this->normalizeRow($row);

        return [
            'ID' => $this->cell($row, 'id'),
            'Customer name' => $this->cell($row, 'customer name'),
            'Customer email' => $this->cell($row, 'customer email'),
            'Service' => $this->cell($row, 'service'),
            'Party Area' => $this->cell($row, 'party area'),
            'Appointment date' => $this->cell($row, 'appointment date'),
            'Status' => $this->cell($row, 'status'),
        ];
    }

    protected function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $header => $value) {
            $key = $this->headerKey((string) $header);

            if (!array_key_exists($key, $normalized) || $this->isBlank($normalized[$key])) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    protected function headerKey(string $header): string
    {
        $header = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $header);

        return mb_strtolower(trim(preg_replace('/[\s_]+/u', ' ', $header)));
    }

    protected function cell(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && !$this->isBlank($row[$key])) {
                return $row[$key];
            }
        }

        return null;
    }

    protected function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    protected function matchPackageByWords(int $locationId, string $name): ?Package
    {
        $wanted = $this->nameWords($name);
        if ($wanted === []) {
            return null;
        }

        $bestId = null;
        $bestScore = 0;
        $overlaps = [];

        foreach (Package::where('location_id', $locationId)->get(['id', 'name']) as $candidate) {
            $words = $this->nameWords((string) $candidate->name);
            $shared = count(array_intersect($words, $wanted));
            if ($shared === 0) {
                continue;
            }

            $overlaps[$candidate->id] = $shared;

            if ($shared < count($words) && $shared < count($wanted)) {
                continue;
            }

            if ($shared > $bestScore) {
                $bestId = $candidate->id;
                $bestScore = $shared;
            }
        }

        if ($bestId === null) {
            return null;
        }

        foreach ($overlaps as $id => $shared) {
            if ($id !== $bestId && $shared >= $bestScore) {
                return null;
            }
        }

        return Package::find($bestId);
    }

    protected function nameWords(string $name): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(html_entity_decode($name, ENT_QUOTES, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_diff($words, self::NAME_FILLER_WORDS)));
    }

    protected function assertParticipants(Package $package, int $participants, ?string $date = null): void
    {
        $label = strtolower($package->participant_label ?: 'participant');
        $problem = null;
        $effectiveMin = $package->effectiveMinParticipants($date);

        if ($participants < $effectiveMin) {
            $problem = "{$package->name} needs at least {$effectiveMin} {$label}s (row has {$participants})";
        } elseif ($package->max_participants !== null && $participants > (int) $package->max_participants) {
            $problem = "{$package->name} takes at most {$package->max_participants} {$label}s (row has {$participants})";
        }

        if ($problem === null) {
            return;
        }

        if ((string) config('booking_rules.csv_participants', 'log') === 'enforce') {
            throw new \RuntimeException($problem);
        }

        Log::warning('CSV import participants rule (log-only)', ['package_id' => $package->id, 'participants' => $participants, 'problem' => $problem]);
    }

    protected function assertCapacity(Package $package, string $bookingDate, string $bookingTime, int $participants): void
    {
        $guard = app(SlotCapacityGuard::class);

        if ($guard->isPast($bookingDate)) {
            return;
        }

        $remaining = $package->remainingTicketsForSlot($bookingDate, $bookingTime);

        if ($remaining === null || $participants <= $remaining) {
            return;
        }

        $problem = "{$package->name} has only {$remaining} seat(s) left at {$bookingTime} on {$bookingDate}";

        if ((string) config('booking_rules.capacity', 'log') === 'enforce') {
            throw new \RuntimeException($problem);
        }

        Log::warning('CSV import capacity rule (log-only)', ['package_id' => $package->id, 'date' => $bookingDate, 'time' => $bookingTime, 'participants' => $participants, 'remaining' => $remaining]);
    }
}
