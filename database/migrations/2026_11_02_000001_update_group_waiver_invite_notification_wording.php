<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHAPERONE = 'waiver_bulk_chaperone';
    private const GROUP_MEMBER = 'waiver_parent_invite';

    private const OLD_CHAPERONE_PARAGRAPH = "Your group needs each participant (or a parent/guardian for minors) to complete a waiver before visiting {{location_name}}. Use the link below to add your group's contacts and send them their waiver invites, and to track who has completed theirs.";
    private const NEW_CHAPERONE_PARAGRAPH = 'Everyone in your group needs a signed waiver before visiting {{location_name}}: each adult signs their own, and a parent or guardian signs for each child. Use the link below to add the people in your group. Each person gets their invite as soon as you add them, and you can see who has signed.';

    private const OLD_CHAPERONE_TEXT = '{{company_name}}: your group needs waivers completed. Add contacts and track completion here: {{waiver_link}}';
    private const NEW_CHAPERONE_TEXT = '{{company_name}}: everyone in your group needs a signed waiver. Add the people in your group and see who has signed here: {{waiver_link}}';

    public function up(): void
    {
        foreach ($this->exactChanges() as [$table, $key, $column, $old, $new]) {
            $this->replaceExact($table, $key, $column, $old, $new);
        }

        $this->replaceInside('email_notifications', self::OLD_CHAPERONE_PARAGRAPH, self::NEW_CHAPERONE_PARAGRAPH);
    }

    public function down(): void
    {
        foreach ($this->exactChanges() as [$table, $key, $column, $old, $new]) {
            $this->replaceExact($table, $key, $column, $new, $old);
        }

        $this->replaceInside('email_notifications', self::NEW_CHAPERONE_PARAGRAPH, self::OLD_CHAPERONE_PARAGRAPH);
    }

    private function exactChanges(): array
    {
        $changes = [];

        foreach (['email_notifications', 'sms_notifications'] as $table) {
            $changes[] = [$table, self::CHAPERONE, 'name', 'Bulk Waiver Invite (Chaperone)', 'Group Waiver Invite (Chaperone)'];
            $changes[] = [$table, self::GROUP_MEMBER, 'name', 'Waiver Invite (Parent/Guardian)', 'Group Waiver Invite (Group Member)'];
        }

        $changes[] = ['email_notifications', self::CHAPERONE, 'description', 'Sent to a group organizer/chaperone so they can invite parents/guardians to complete waivers for their group.', 'Sent to the chaperone (group leader) with their link to add the people in their group, send each one a waiver invite and see who has signed.'];
        $changes[] = ['email_notifications', self::GROUP_MEMBER, 'description', 'Sent to a parent/guardian by a chaperone to complete a waiver for their minor(s).', 'Sent to each person a chaperone adds to a group, so they can sign for themselves and any children in their care.'];
        $changes[] = ['sms_notifications', self::CHAPERONE, 'description', 'Sent to a group organizer/chaperone to manage and send group waivers.', 'Sent to the chaperone (group leader) with their link to add their group and see who has signed.'];
        $changes[] = ['sms_notifications', self::GROUP_MEMBER, 'description', 'Sent to a parent/guardian to complete a waiver for their minor(s).', 'Sent to each person a chaperone adds to a group, so they can sign for themselves and any children in their care.'];

        foreach (['body', 'default_body'] as $column) {
            $changes[] = ['sms_notifications', self::CHAPERONE, $column, self::OLD_CHAPERONE_TEXT, self::NEW_CHAPERONE_TEXT];
        }

        return $changes;
    }

    private function replaceExact(string $table, string $key, string $column, string $from, string $to): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->where('default_key', $key)
            ->where($column, $from)
            ->update([$column => $to]);
    }

    private function replaceInside(string $table, string $from, string $to): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach (['body', 'default_body'] as $column) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->where('default_key', self::CHAPERONE)
                ->where($column, 'like', '%' . addcslashes($from, '%_\\') . '%')
                ->orderBy('id')
                ->get(['id', $column])
                ->each(fn ($row) => DB::table($table)
                    ->where('id', $row->id)
                    ->update([$column => str_replace($from, $to, $row->{$column})]));
        }
    }
};
