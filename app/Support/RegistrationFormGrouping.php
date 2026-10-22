<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegistrationFormGrouping
{
    public const SAME_REQUEST_SECONDS = 5;

    public const BATCH_SECONDS = 120;

    public const BATCH_DATE_SPREAD_DAYS = 31;

    public static function nameKey(?string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $name)));
    }

    public static function groupUngroupedForms(): int
    {
        $forms = DB::table('registration_events')
            ->whereNull('group_id')
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id', 'company_id', 'location_id', 'name', 'event_date', 'description', 'confirmation_message',
                'header_image_path', 'header_image_width', 'header_image_height', 'previous_edition_id',
                'created_by', 'created_at', 'deleted_at',
            ]);

        if ($forms->isEmpty()) {
            return 0;
        }

        $clusters = self::clusters($forms);
        $groupIds = [];

        foreach ($clusters as $index => $cluster) {
            $template = $cluster['forms'][0];
            $allDeleted = collect($cluster['forms'])->every(fn ($form) => $form->deleted_at !== null);

            $groupId = DB::table('registration_event_groups')->insertGetId([
                'company_id' => $template->company_id,
                'name' => mb_substr((string) $template->name, 0, 120),
                'event_date' => $template->event_date,
                'description' => $template->description,
                'confirmation_message' => $template->confirmation_message,
                'header_image_path' => $template->header_image_path,
                'header_image_width' => $template->header_image_width,
                'header_image_height' => $template->header_image_height,
                'created_by' => $template->created_by,
                'created_at' => $template->created_at,
                'updated_at' => $template->created_at,
                'deleted_at' => $allDeleted ? collect($cluster['forms'])->max('deleted_at') : null,
            ]);

            DB::table('registration_events')
                ->whereIn('id', array_map(fn ($form) => (int) $form->id, $cluster['forms']))
                ->update(['group_id' => $groupId]);

            $groupIds[$index] = (int) $groupId;
        }

        foreach ($clusters as $index => $cluster) {
            self::linkPreviousGroup($groupIds[$index], $cluster['forms']);
        }

        return count($clusters);
    }

    private static function clusters(Collection $forms): array
    {
        $clusters = [];
        $byKey = [];

        foreach ($forms as $form) {
            $key = implode('|', [$form->company_id, self::nameKey($form->name), (string) $form->created_by]);
            $location = (int) $form->location_id;
            $date = substr((string) $form->event_date, 0, 10);
            $time = $form->created_at !== null ? strtotime((string) $form->created_at) : false;

            $placed = null;
            foreach ($byKey[$key] ?? [] as $index) {
                if (in_array($location, $clusters[$index]['locations'], true)) {
                    continue;
                }

                if (in_array($date, $clusters[$index]['dates'], true) || self::sameBatch($time, $date, $clusters[$index])) {
                    $placed = $index;
                    break;
                }
            }

            if ($placed === null) {
                $placed = count($clusters);
                $clusters[] = ['locations' => [], 'dates' => [], 'times' => [], 'forms' => []];
                $byKey[$key][] = $placed;
            }

            $clusters[$placed]['locations'][] = $location;
            $clusters[$placed]['dates'][] = $date;
            if ($time !== false) {
                $clusters[$placed]['times'][] = $time;
            }
            $clusters[$placed]['forms'][] = $form;
        }

        return $clusters;
    }

    private static function sameBatch(int|false $time, string $date, array $cluster): bool
    {
        if ($time === false || $cluster['times'] === []) {
            return false;
        }

        $closest = min(array_map(fn (int $other) => abs($other - $time), $cluster['times']));
        if ($closest <= self::SAME_REQUEST_SECONDS) {
            return true;
        }

        if ($closest > self::BATCH_SECONDS) {
            return false;
        }

        $day = self::dayNumber($date);

        return collect($cluster['dates'])->contains(fn (string $other) => abs(self::dayNumber($other) - $day) <= self::BATCH_DATE_SPREAD_DAYS);
    }

    private static function dayNumber(string $date): int
    {
        return intdiv((new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    private static function linkPreviousGroup(int $groupId, array $forms): void
    {
        $date = substr((string) $forms[0]->event_date, 0, 10);

        foreach ($forms as $form) {
            if ($form->previous_edition_id === null) {
                continue;
            }

            $previousGroupId = DB::table('registration_events')
                ->where('id', $form->previous_edition_id)
                ->whereNull('deleted_at')
                ->value('group_id');

            if ($previousGroupId === null || (int) $previousGroupId === $groupId) {
                continue;
            }

            $previousDate = DB::table('registration_event_groups')
                ->where('id', $previousGroupId)
                ->whereNull('deleted_at')
                ->value('event_date');

            if ($previousDate === null || substr((string) $previousDate, 0, 10) >= $date) {
                continue;
            }

            DB::table('registration_event_groups')->where('id', $groupId)->update(['previous_group_id' => (int) $previousGroupId]);

            return;
        }
    }
}
