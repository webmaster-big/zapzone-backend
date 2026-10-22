<?php

namespace App\Console\Commands;

use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Support\RegistrationFormGrouping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GroupRegistrationForms extends Command
{
    protected $signature = 'registration-events:group-orphans';

    protected $description = 'Put every registration form that has no event into one, and retire events that have no forms left.';

    public function handle(): int
    {
        if (!RegistrationEventGroup::isAvailable()) {
            $this->info('Registration events are not set up yet.');

            return self::SUCCESS;
        }

        $retired = 0;
        if (RegistrationEventGroup::query()->whereDoesntHave('forms')->exists()) {
            $retired = DB::transaction(function () {
                $empty = RegistrationEventGroup::query()->whereDoesntHave('forms')->orderBy('id')->lockForUpdate()->get();
                $count = 0;
                foreach ($empty as $group) {
                    if (RegistrationEvent::query()->where('group_id', $group->id)->lockForUpdate()->pluck('id')->isNotEmpty()) {
                        continue;
                    }
                    $group->delete();
                    $count++;
                }

                return $count;
            });
        }

        $created = 0;
        if (DB::table('registration_events')->whereNull('group_id')->exists()) {
            $created = DB::transaction(fn () => RegistrationFormGrouping::groupUngroupedForms());
        }

        $this->info(sprintf(
            'Created %d event%s for forms that had none and retired %d event%s with no forms.',
            $created,
            $created === 1 ? '' : 's',
            $retired,
            $retired === 1 ? '' : 's'
        ));

        return self::SUCCESS;
    }
}
