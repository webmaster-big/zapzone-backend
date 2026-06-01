<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\BookingAddOn;
use App\Models\PackageTimeSlot;
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

    public function processRows(array $rows, int $locationId, ?int $createdBy = null): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $importedBookings = [];

        foreach ($rows as $index => $row) {
            try {
                $result = $this->processRow($row, $index, $locationId, $createdBy);

                if ($result === null) {
                    $skipped++;
                    continue;
                }

                $importedBookings[] = $result;
                $imported++;
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
            'bookings' => $importedBookings,
        ];
    }

    protected function processRow(array $row, int $index, int $locationId, ?int $createdBy): ?Booking
    {


        $appointmentDate = $this->parseDateTime($row['Appointment date'] ?? $row['appointment_date'] ?? null);
        if (!$appointmentDate) {
            throw new \RuntimeException('Invalid or missing appointment date');
        }

        $bookingDate = $appointmentDate->format('Y-m-d');
        $bookingTime = $appointmentDate->format('H:i');

        $createdDate = $this->parseDateTime($row['Created'] ?? $row['created'] ?? null);

        $customerName = trim($row['Customer name'] ?? $row['customer_name'] ?? '');
        $customerEmail = trim($row['Customer email'] ?? $row['customer_email'] ?? '');
        $customerPhone = trim($row['Customer phone'] ?? $row['customer_phone'] ?? '');
        $customerAddress = trim($row['Customer address'] ?? $row['customer_address'] ?? '');

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

        $serviceRaw = trim((string) ($row['Service'] ?? $row['service'] ?? ''));
        $parsed = $this->parseServiceAndAddons($serviceRaw);
        $packageName = $parsed['package_name'];

        $package = Package::where('name', $packageName)->first();
        if (!$package) {
            $package = Package::where('name', 'LIKE', '%' . $packageName . '%')->first();
        }
        if (!$package && !empty($packageName)) {
            $basePackageName = trim(explode('|', $packageName)[0]);
            if ($basePackageName !== $packageName) {
                $package = Package::where('name', 'LIKE', '%' . $basePackageName . '%')->first();
            }
        }

        $roomName = trim((string) ($row['Party Area'] ?? $row['party_area'] ?? ''));
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

        $csvPersons = trim((string) ($row['Number of Persons'] ?? $row['number_of_persons'] ?? $row['Persons'] ?? $row['persons'] ?? $row['Participants'] ?? $row['participants'] ?? ''));
        $csvPersonsInt = is_numeric($csvPersons) ? (int) $csvPersons : null;

        $csvStaff = trim((string) ($row['Staff'] ?? $row['staff'] ?? $row['Staff Member'] ?? $row['staff_member'] ?? ''));

        $csvInternalNote = trim((string) ($row['Internal Note'] ?? $row['internal_note'] ?? $row['Internal Notes'] ?? $row['internal_notes'] ?? ''));

        $duration = (int) ($row['Duration'] ?? $row['duration'] ?? 0);
        $durationUnit = 'minutes';

        $paymentRaw = trim((string) ($row['Payment'] ?? $row['payment'] ?? ''));
        $paymentInfo = $this->parsePayment($paymentRaw);

        $statusRaw = strtolower(trim((string) ($row['Status'] ?? $row['status'] ?? 'pending')));
        $status = self::STATUS_MAP[$statusRaw] ?? 'pending';

        $notes = trim((string) ($row['Notes'] ?? $row['notes'] ?? ''));

        $guestOfHonorName = trim((string) ($row["Guest of Honor's Name"] ?? $row['guest_of_honor_name'] ?? ''));
        $guestOfHonorAge = trim((string) ($row["Guest of Honor's Age (or General Age of Group)"] ?? $row['guest_of_honor_age'] ?? ''));
        $guestOfHonorAge = is_numeric($guestOfHonorAge) ? (int) $guestOfHonorAge : null;

        $externalId = trim((string) ($row['ID'] ?? $row['id'] ?? ''));
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

        do {
            $referenceNumber = 'BK' . now()->format('Ymd') . strtoupper(Str::random(6));
        } while (Booking::where('reference_number', $referenceNumber)->exists());

        $internalNotesParts = [];

        if (!$package && !empty($packageName)) {
            $internalNotesParts[] = "Package not found: {$serviceRaw}";
        }

        if (!$room && !empty($roomName) && strtolower($roomName) !== 'no area') {
            $internalNotesParts[] = "Space not found: {$roomName}";
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
            'participants' => $csvPersonsInt ?? $package?->min_participants ?? 10,
            'duration' => $duration ?: ($package?->duration ?? 120),
            'duration_unit' => $durationUnit,
            'total_amount' => $paymentInfo['total_amount'],
            'amount_paid' => $paymentInfo['amount_paid'],
            'payment_method' => $paymentInfo['payment_method'],
            'payment_status' => $paymentStatus,
            'status' => $status,
            'notes' => !empty($notes) ? $notes : null,
            'internal_notes' => $internalNotes,
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

        if ($room && $package) {
            PackageTimeSlot::create([
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
            ]);
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
                    $currentNotes = $booking->internal_notes ?? '';
                    if (empty($currentNotes)) {
                        $currentNotes = "[CSV Import]\n" . implode("\n", $addonLines);
                    } else {
                        $currentNotes .= "\n" . implode("\n", $addonLines);
                    }
                    $booking->update(['internal_notes' => $currentNotes]);
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
                return Carbon::createFromTimestamp((int) $unixTimestamp);
            } catch (\Exception $e) {
                return null;
            }
        }

        $value = trim((string) $value);

        if (is_numeric($value) && strpos($value, '/') === false && strpos($value, '-') === false) {
            try {
                $unixTimestamp = ((float) $value - 25569) * 86400;
                return Carbon::createFromTimestamp((int) $unixTimestamp);
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
                if (preg_match('/^(\d+)\s*[×x]\s*(.+)$/i', $part, $qMatch)) {
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
                $addon = \App\Models\AddOn::whereRaw('LOWER(name) = ?', [Str::lower($addonName)])->first();
                if ($addon) {
                    $matchType = 'exact';
                }
            }

            if (!$addon) {
                $addon = \App\Models\AddOn::where('name', 'LIKE', '%' . $addonName . '%')->first();
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

        if (preg_match('/\$?([\d,.]+)\s+of\s+\$?([\d,.]+)\s*(.*)/i', $payment, $matches)) {
            $result['amount_paid'] = (float) str_replace(',', '', $matches[1]);
            $result['total_amount'] = (float) str_replace(',', '', $matches[2]);

            $remainder = trim($matches[3]);

            if (stripos($remainder, 'Authorize') !== false) {
                $result['payment_method'] = 'authorize.net';
            } elseif (stripos($remainder, 'Stripe') !== false) {
                $result['payment_method'] = 'card';
            } elseif (stripos($remainder, 'PayPal') !== false) {
                $result['payment_method'] = 'card';
            } elseif (stripos($remainder, 'cash') !== false) {
                $result['payment_method'] = 'in-store';
            } else {
                $result['payment_method'] = 'paylater';
            }

            if (stripos($remainder, 'Completed') !== false) {
                $result['payment_status_raw'] = 'completed';
            } elseif (stripos($remainder, 'Pending') !== false) {
                $result['payment_status_raw'] = 'pending';
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
        return [
            'ID' => $row['ID'] ?? $row['id'] ?? null,
            'Customer name' => $row['Customer name'] ?? $row['customer_name'] ?? null,
            'Customer email' => $row['Customer email'] ?? $row['customer_email'] ?? null,
            'Service' => $row['Service'] ?? $row['service'] ?? null,
            'Party Area' => $row['Party Area'] ?? $row['party_area'] ?? null,
            'Appointment date' => $row['Appointment date'] ?? $row['appointment_date'] ?? null,
            'Status' => $row['Status'] ?? $row['status'] ?? null,
        ];
    }
}
