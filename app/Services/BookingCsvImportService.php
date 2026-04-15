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
    /**
     * Status mapping from Bookly CSV to our system.
     */
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

    /**
     * Valid payment methods in the database enum.
     */
    protected const VALID_PAYMENT_METHODS = ['card', 'in-store', 'paylater', 'authorize.net'];

    /**
     * Parse a file (CSV or Excel) and return structured rows.
     */
    public function parseFile(string $filePath, string $originalName = ''): array
    {
        $extension = strtolower(pathinfo($originalName ?: $filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->parseExcel($filePath);
        }

        return $this->parseCsv($filePath);
    }

    /**
     * Parse a CSV file and return structured rows.
     */
    public function parseCsv(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file');
        }

        // Read and normalize header
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('CSV file is empty or has no header row');
        }

        // Remove BOM if present
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

    /**
     * Parse an Excel file (xlsx/xls) and return structured rows.
     */
    public function parseExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];

        $data = $worksheet->toArray(null, true, true, false);

        if (empty($data)) {
            throw new \RuntimeException('Excel file is empty');
        }

        // First row is the header
        $header = array_map('trim', array_map('strval', $data[0]));

        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            // Skip empty rows
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

    /**
     * Process parsed rows into booking data.
     */
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

    /**
     * Process a single CSV/Excel row into a booking.
     */
    protected function processRow(array $row, int $index, int $locationId, ?int $createdBy): ?Booking
    {
        // Collect unmatched data for internal notes
        $unmatchedNotes = [];

        // Parse appointment date and time
        $appointmentDate = $this->parseDateTime($row['Appointment date'] ?? $row['appointment_date'] ?? null);
        if (!$appointmentDate) {
            throw new \RuntimeException('Invalid or missing appointment date');
        }

        $bookingDate = $appointmentDate->format('Y-m-d');
        $bookingTime = $appointmentDate->format('H:i');

        // Parse the original creation date from Bookly
        $createdDate = $this->parseDateTime($row['Created'] ?? $row['created'] ?? null);

        // Parse customer info
        $customerName = trim($row['Customer name'] ?? $row['customer_name'] ?? '');
        $customerEmail = trim($row['Customer email'] ?? $row['customer_email'] ?? '');
        $customerPhone = trim($row['Customer phone'] ?? $row['customer_phone'] ?? '');
        $customerAddress = trim($row['Customer address'] ?? $row['customer_address'] ?? '');

        if (empty($customerName) && empty($customerEmail)) {
            throw new \RuntimeException('Customer name and email are both empty');
        }

        // Add to contacts table (no customer account creation)
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

        // Parse service/package name (the CSV "Service" column may have add-ons in parentheses)
        $serviceRaw = trim((string) ($row['Service'] ?? $row['service'] ?? ''));
        $parsed = $this->parseServiceAndAddons($serviceRaw);
        $packageName = $parsed['package_name'];

        // Match package
        $packageMatchType = 'none';
        $package = Package::where('name', $packageName)->first();
        if ($package) {
            $packageMatchType = 'exact';
        }
        if (!$package) {
            // Try fuzzy match
            $package = Package::where('name', 'LIKE', '%' . $packageName . '%')->first();
            if ($package) {
                $packageMatchType = 'fuzzy';
            }
        }
        if (!$package && !empty($packageName)) {
            // Try matching by splitting pipe (Summer Camp | Unlimited → "Summer Camp")
            $basePackageName = trim(explode('|', $packageName)[0]);
            if ($basePackageName !== $packageName) {
                $package = Package::where('name', 'LIKE', '%' . $basePackageName . '%')->first();
                if ($package) {
                    $packageMatchType = 'fuzzy';
                }
            }
        }
        if (!$package && !empty($packageName)) {
            $unmatchedNotes[] = "UNMATCHED PACKAGE: \"{$packageName}\" (from Service: \"{$serviceRaw}\")";
        } elseif ($package && $packageMatchType === 'fuzzy') {
            $unmatchedNotes[] = "FUZZY MATCHED PACKAGE: CSV \"{$packageName}\" → matched \"{$package->name}\" (ID:{$package->id})";
        }

        // Match room from "Party Area" column
        $roomName = trim((string) ($row['Party Area'] ?? $row['party_area'] ?? ''));
        $room = null;
        $roomMatchType = 'none';
        if (!empty($roomName) && strtolower($roomName) !== 'no area') {
            // Try exact match first
            $room = Room::where('location_id', $locationId)
                ->where('name', $roomName)
                ->first();
            if ($room) {
                $roomMatchType = 'exact';
            }

            if (!$room) {
                // Try matching without "(Any)" suffix
                $cleanRoomName = preg_replace('/\s*\(Any\)\s*$/i', '', $roomName);
                $room = Room::where('location_id', $locationId)
                    ->where('name', $cleanRoomName)
                    ->first();
                if ($room) {
                    $roomMatchType = 'fuzzy';
                }
            }

            if (!$room) {
                // Try LIKE match
                $cleanRoomName = preg_replace('/\s*\(Any\)\s*$/i', '', $roomName);
                $room = Room::where('location_id', $locationId)
                    ->where('name', 'LIKE', '%' . $cleanRoomName . '%')
                    ->first();
                if ($room) {
                    $roomMatchType = 'fuzzy';
                }
            }

            if (!$room) {
                $unmatchedNotes[] = "UNMATCHED ROOM: \"{$roomName}\"";
            } elseif ($roomMatchType === 'fuzzy') {
                $unmatchedNotes[] = "FUZZY MATCHED ROOM: CSV \"{$roomName}\" → matched \"{$room->name}\" (ID:{$room->id})";
            }
        }

        // Parse number of persons from CSV
        $csvPersons = trim((string) ($row['Number of Persons'] ?? $row['number_of_persons'] ?? $row['Persons'] ?? $row['persons'] ?? $row['Participants'] ?? $row['participants'] ?? ''));
        $csvPersonsInt = is_numeric($csvPersons) ? (int) $csvPersons : null;

        // Parse staff from CSV
        $csvStaff = trim((string) ($row['Staff'] ?? $row['staff'] ?? $row['Staff Member'] ?? $row['staff_member'] ?? ''));

        // Parse Bookly internal note (separate from customer-facing Notes)
        $csvInternalNote = trim((string) ($row['Internal Note'] ?? $row['internal_note'] ?? $row['Internal Notes'] ?? $row['internal_notes'] ?? ''));

        // Parse duration
        $duration = (int) ($row['Duration'] ?? $row['duration'] ?? 0);
        $durationUnit = 'minutes';

        // Parse payment info
        $paymentRaw = trim((string) ($row['Payment'] ?? $row['payment'] ?? ''));
        $paymentInfo = $this->parsePayment($paymentRaw);

        // Parse status
        $statusRaw = strtolower(trim((string) ($row['Status'] ?? $row['status'] ?? 'pending')));
        $status = self::STATUS_MAP[$statusRaw] ?? 'pending';

        // Parse notes
        $notes = trim((string) ($row['Notes'] ?? $row['notes'] ?? ''));

        // Guest of honor
        $guestOfHonorName = trim((string) ($row["Guest of Honor's Name"] ?? $row['guest_of_honor_name'] ?? ''));
        $guestOfHonorAge = trim((string) ($row["Guest of Honor's Age (or General Age of Group)"] ?? $row['guest_of_honor_age'] ?? ''));
        $guestOfHonorAge = is_numeric($guestOfHonorAge) ? (int) $guestOfHonorAge : null;

        // Check for duplicate by external ID
        $externalId = trim((string) ($row['ID'] ?? $row['id'] ?? ''));
        if (!empty($externalId)) {
            $existing = Booking::where('internal_notes', 'LIKE', '%bookly_id:' . $externalId . '%')
                ->first();
            if ($existing) {
                return null; // Skip duplicate
            }
        }

        // Generate reference number
        do {
            $referenceNumber = 'BK' . now()->format('Ymd') . strtoupper(Str::random(6));
        } while (Booking::where('reference_number', $referenceNumber)->exists());

        // Build internal notes with import tracking info
        $internalNotesParts = ['Imported from Bookly CSV'];
        if (!empty($externalId)) {
            $internalNotesParts[] = 'bookly_id:' . $externalId;
        }
        if ($createdDate) {
            $internalNotesParts[] = 'Original created: ' . $createdDate->format('Y-m-d H:i');
        }
        if (!empty($paymentRaw)) {
            $internalNotesParts[] = 'Original payment: ' . $paymentRaw;
        }

        // Always store original CSV values for traceability
        if (!empty($serviceRaw)) {
            $internalNotesParts[] = 'CSV Service: ' . $serviceRaw;
        }
        if ($package) {
            $internalNotesParts[] = 'Matched Package: ' . $package->name . ' (ID:' . $package->id . ')';
        }
        if (!empty($roomName)) {
            $internalNotesParts[] = 'CSV Party Area: ' . $roomName;
        }
        if ($room) {
            $internalNotesParts[] = 'Matched Room: ' . $room->name . ' (ID:' . $room->id . ')';
        }
        if (!empty($csvStaff)) {
            $internalNotesParts[] = 'CSV Staff: ' . $csvStaff;
        }
        if ($csvPersonsInt !== null) {
            $internalNotesParts[] = 'CSV Persons: ' . $csvPersonsInt;
        }
        if (!empty($csvInternalNote)) {
            $internalNotesParts[] = 'Bookly Internal Note: ' . $csvInternalNote;
        }
        // Store parsed add-on names from CSV for traceability
        if (!empty($parsed['addons'])) {
            $addonSummary = array_map(fn($a) => ($a['quantity'] > 1 ? $a['quantity'] . '× ' : '') . $a['name'], $parsed['addons']);
            $internalNotesParts[] = 'CSV Add-ons: ' . implode(', ', $addonSummary);
        }

        // Collect any extra CSV columns not explicitly mapped
        $knownColumns = [
            'ID', 'id', 'Appointment date', 'appointment_date', 'Created', 'created',
            'Customer name', 'customer_name', 'Customer email', 'customer_email',
            'Customer phone', 'customer_phone', 'Customer address', 'customer_address',
            'Service', 'service', 'Party Area', 'party_area',
            'Duration', 'duration', 'Payment', 'payment', 'Status', 'status',
            'Notes', 'notes', 'Internal Note', 'internal_note', 'Internal Notes', 'internal_notes',
            "Guest of Honor's Name", 'guest_of_honor_name',
            "Guest of Honor's Age (or General Age of Group)", 'guest_of_honor_age',
            'Number of Persons', 'number_of_persons', 'Persons', 'persons',
            'Participants', 'participants', 'Staff', 'staff', 'Staff Member', 'staff_member',
        ];
        foreach ($row as $colName => $colValue) {
            $colValue = trim((string) $colValue);
            if (!in_array($colName, $knownColumns) && !empty($colValue)) {
                $internalNotesParts[] = 'CSV ' . $colName . ': ' . $colValue;
            }
        }

        // Append all unmatched data warnings
        if (!empty($unmatchedNotes)) {
            $internalNotesParts[] = '--- UNMATCHED DATA ---';
            $internalNotesParts = array_merge($internalNotesParts, $unmatchedNotes);
        }

        // Determine payment status
        $paymentStatus = 'pending';
        if ($paymentInfo['total_amount'] > 0) {
            if ($paymentInfo['amount_paid'] >= $paymentInfo['total_amount']) {
                $paymentStatus = 'paid';
            } elseif ($paymentInfo['amount_paid'] > 0) {
                $paymentStatus = 'partial';
            }
        }

        // Map to completed_at / cancelled_at timestamps based on status
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
            'internal_notes' => implode(' | ', $internalNotesParts),
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

        // Create time slot if room is assigned
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

        // Attach parsed add-ons (works with or without a matched package)
        if (!empty($parsed['addons'])) {
            $unmatchedAddons = $this->attachAddons($booking, $package, $parsed['addons']);

            // If there were unmatched or fuzzy-matched add-ons, append them to internal notes
            if (!empty($unmatchedAddons)) {
                $addonWarnings = array_map(function ($a) {
                    if (isset($a['fuzzy_matched_to'])) {
                        return "FUZZY MATCHED ADD-ON: \"{$a['name']}\" (qty: {$a['quantity']}) → matched \"{$a['fuzzy_matched_to']}\" (ID:{$a['fuzzy_matched_id']})";
                    }
                    return "UNMATCHED ADD-ON: \"{$a['name']}\" (qty: {$a['quantity']})";
                }, $unmatchedAddons);
                $currentNotes = $booking->internal_notes;
                if (strpos($currentNotes, '--- UNMATCHED DATA ---') === false) {
                    $currentNotes .= ' | --- UNMATCHED DATA ---';
                }
                $currentNotes .= ' | ' . implode(' | ', $addonWarnings);
                $booking->update(['internal_notes' => $currentNotes]);
            }
        }

        return $booking;
    }

    /**
     * Parse date/time from various CSV formats.
     * Handles: "4/5/2026 13:00", "2026-04-05 13:00:00", "04/05/2026 1:00 PM", etc.
     * Also handles numeric Excel serial date values.
     */
    protected function parseDateTime($value): ?Carbon
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        // Handle numeric Excel serial dates (e.g., 46113.541666)
        if (is_numeric($value) && !is_string($value)) {
            try {
                $unixTimestamp = ((float) $value - 25569) * 86400;
                return Carbon::createFromTimestamp((int) $unixTimestamp);
            } catch (\Exception $e) {
                return null;
            }
        }

        $value = trim((string) $value);

        // Also handle string-numeric values from Excel
        if (is_numeric($value) && strpos($value, '/') === false && strpos($value, '-') === false) {
            try {
                $unixTimestamp = ((float) $value - 25569) * 86400;
                return Carbon::createFromTimestamp((int) $unixTimestamp);
            } catch (\Exception $e) {
                // Fall through to format parsing
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

        // Last resort: let Carbon try to parse it
        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse "Service" column that may contain add-ons in parentheses.
     * e.g. "Unlimited Activities + Arcade Party (2 × Additional 10-Slice Cheese Pizza, Cheesy Bread)"
     */
    protected function parseServiceAndAddons(string $service): array
    {
        $addons = [];
        $packageName = $service;

        // Match content in parentheses at the end
        if (preg_match('/^(.+?)\s*\((.+)\)\s*$/', $service, $matches)) {
            $packageName = trim($matches[1]);
            $addonString = $matches[2];

            // Split by comma
            $addonParts = preg_split('/\s*,\s*/', $addonString);

            foreach ($addonParts as $part) {
                $part = trim($part);
                if (empty($part)) continue;

                // Check for quantity prefix like "2 ×" or "2 x"
                $quantity = 1;
                if (preg_match('/^(\d+)\s*[×x]\s*(.+)$/i', $part, $qMatch)) {
                    $quantity = (int) $qMatch[1];
                    $part = trim($qMatch[2]);
                }

                // Decode HTML entities (e.g., &amp; → &)
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

    /**
     * Attach add-ons from CSV to the booking by matching names.
     * Returns array of unmatched/fuzzy-matched add-ons so they can be logged in internal_notes.
     */
    protected function attachAddons(Booking $booking, ?Package $package, array $addonList): array
    {
        $unmatchedAddons = [];

        // Get package add-ons if a package was matched
        $packageAddons = $package ? $package->addOns()->get() : collect();

        foreach ($addonList as $addonInfo) {
            $addonName = $addonInfo['name'];
            $quantity = $addonInfo['quantity'];
            $matchType = 'none';

            // Try to find add-on by name in package's add-ons first (case-insensitive)
            $addon = $packageAddons->first(function ($a) use ($addonName) {
                return Str::lower($a->name) === Str::lower($addonName);
            });
            if ($addon) {
                $matchType = 'exact';
            }

            // Fallback: search all add-ons by exact name (case-insensitive)
            if (!$addon) {
                $addon = \App\Models\AddOn::whereRaw('LOWER(name) = ?', [Str::lower($addonName)])->first();
                if ($addon) {
                    $matchType = 'exact';
                }
            }

            // Fuzzy fallback: LIKE match
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

                // Track fuzzy matches so they appear in internal notes
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

    /**
     * Parse payment string from Bookly.
     * e.g. "$50.00 of $567.93 Authorize.Net Completed"
     */
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

        // Pattern: "$50.00 of $567.93 Authorize.Net Completed"
        if (preg_match('/\$?([\d,.]+)\s+of\s+\$?([\d,.]+)\s*(.*)/i', $payment, $matches)) {
            $result['amount_paid'] = (float) str_replace(',', '', $matches[1]);
            $result['total_amount'] = (float) str_replace(',', '', $matches[2]);

            $remainder = trim($matches[3]);

            // Detect payment method — mapped to valid DB enum values
            // DB enum: 'card', 'in-store', 'paylater', 'authorize.net'
            if (stripos($remainder, 'Authorize') !== false) {
                $result['payment_method'] = 'authorize.net';
            } elseif (stripos($remainder, 'Stripe') !== false) {
                $result['payment_method'] = 'card';
            } elseif (stripos($remainder, 'PayPal') !== false) {
                $result['payment_method'] = 'card';
            } elseif (stripos($remainder, 'cash') !== false) {
                // 'cash' was renamed to 'in-store' in the DB enum
                $result['payment_method'] = 'in-store';
            } else {
                // Default to paylater for unknown payment gateway names
                $result['payment_method'] = 'paylater';
            }

            // Detect payment status
            if (stripos($remainder, 'Completed') !== false) {
                $result['payment_status_raw'] = 'completed';
            } elseif (stripos($remainder, 'Pending') !== false) {
                $result['payment_status_raw'] = 'pending';
            }
        }

        return $result;
    }

    /**
     * Split a full name into first and last name.
     */
    protected function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Parse address string from Bookly CSV.
     * Handles multiple formats:
     *   "47083 Lyndon Ave, MI, 48187, Canton, "
     *   "47083 Lyndon Ave, Canton, MI, 48187, "
     *   "704 N. Congress St. #1, Ypsilanti, MI, 48197, "
     */
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
            // First part is always the street address
            $result['address'] = $parts[0];

            // Identify state (2-letter), zip (5-digit), and city from remaining parts
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

    /**
     * Clean phone number - keep digits only.
     */
    protected function cleanPhone(string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        // Remove everything except digits
        $cleaned = preg_replace('/[^\d]/', '', $phone);

        return $cleaned;
    }

    /**
     * Sanitize row data for logging (include enough context to debug).
     */
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
