<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SUBJECT = 'Your photos from {{event_name}} at {{location_name}}';

    private const BODY = '<p>Hi {{first_name}},</p><p>Thanks for joining us for {{event_name}} at {{location_name}}. Your photos are ready to view and download.</p><p><a href="{{photo_link}}">View your photos</a></p><p>This link works until {{expires_on}}.</p><p>{{links}}</p><p>{{business_name}}<br>{{location_phone}}</p>';

    private const SMS = 'Your photos from {{event_name}} at {{location_name}} are ready: {{photo_link}} (available until {{expires_on}})';

    public function up(): void
    {
        if (!Schema::hasTable('email_templates')) {
            return;
        }

        if (!Schema::hasColumn('email_templates', 'location_ids')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->json('location_ids')->nullable()->after('location_id');
            });
        }

        if (!Schema::hasColumn('email_templates', 'attach_photos')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->boolean('attach_photos')->default(false)->after('category');
            });

            DB::table('email_templates')->where('category', 'event_photos')->update(['attach_photos' => true]);
        }

        if (!Schema::hasColumn('email_templates', 'sms_body')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->text('sms_body')->nullable()->after('attach_photos');
            });
        }

        DB::table('email_templates')
            ->whereNotNull('location_id')
            ->whereNull('location_ids')
            ->select(['id', 'location_id'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('email_templates')->where('id', $row->id)->update(['location_ids' => json_encode([(int) $row->location_id])]);
                }
            });

        $this->copyEventPhotoEmails();
    }

    public function down(): void
    {
        if (!Schema::hasTable('email_templates')) {
            return;
        }

        if (Schema::hasColumn('email_templates', 'location_ids')) {
            DB::table('email_templates')
                ->whereNull('location_id')
                ->whereNotNull('location_ids')
                ->select(['id', 'location_ids', 'category'])
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $ids = json_decode((string) $row->location_ids, true);

                        if (is_array($ids) && count($ids) === 1 && DB::table('locations')->where('id', (int) $ids[0])->exists()) {
                            DB::table('email_templates')->where('id', $row->id)->update(['location_id' => (int) $ids[0]]);
                        } elseif (is_array($ids) && count($ids) > 1 && $row->category === 'event_photos') {
                            DB::table('email_templates')->where('id', $row->id)->update(['status' => 'draft']);
                        } elseif (is_array($ids) && count($ids) > 1) {
                            $first = DB::table('locations')->whereIn('id', array_map('intval', $ids))->orderBy('id')->value('id');

                            if ($first) {
                                DB::table('email_templates')->where('id', $row->id)->update(['location_id' => (int) $first]);
                            }
                        }
                    }
                });
        }

        foreach (['sms_body', 'attach_photos', 'location_ids'] as $column) {
            if (Schema::hasColumn('email_templates', $column)) {
                Schema::table('email_templates', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function copyEventPhotoEmails(): void
    {
        if (!Schema::hasTable('photo_message_templates') || !Schema::hasTable('users')) {
            return;
        }

        $hasAttach = Schema::hasColumn('photo_message_templates', 'attach_photos');
        $companyIds = DB::table('photo_message_templates')->whereNotNull('company_id')->distinct()->pluck('company_id');

        if (Schema::hasTable('registration_events')) {
            $companyIds = $companyIds->merge(DB::table('registration_events')->whereNotNull('company_id')->distinct()->pluck('company_id'));
        }

        foreach ($companyIds->map(fn ($id) => (int) $id)->unique()->sort()->values() as $companyId) {
            $alreadyMoved = DB::table('email_templates')
                ->where('company_id', $companyId)
                ->where('category', 'event_photos')
                ->exists();

            if ($alreadyMoved) {
                continue;
            }

            $creator = DB::table('users')->where('company_id', $companyId)->whereIn('role', ['company_admin', 'owner', 'admin'])->orderBy('id')->value('id')
                ?? DB::table('users')->where('company_id', $companyId)->orderBy('id')->value('id');

            if (!$creator) {
                Log::warning('Event photo email not moved: the company has no staff user to own it', ['company_id' => $companyId]);

                continue;
            }

            $row = DB::table('photo_message_templates')->where('company_id', $companyId)->where('kind', 'registration')->first();
            $body = $row?->email_body ?: self::BODY;

            DB::table('email_templates')->insert([
                'company_id' => $companyId,
                'location_id' => null,
                'location_ids' => null,
                'created_by' => $creator,
                'name' => 'Event registration photos',
                'subject' => $row?->email_subject ?: self::SUBJECT,
                'body' => (string) preg_replace('/>[ \t]*\r?\n\s*</', '><', $body),
                'sms_body' => $row?->sms_body ?: self::SMS,
                'attach_photos' => $row && $hasAttach ? (bool) $row->attach_photos : true,
                'status' => $row === null || $row->is_active ? 'active' : 'draft',
                'category' => 'event_photos',
                'available_variables' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
