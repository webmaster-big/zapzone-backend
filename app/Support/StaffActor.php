<?php

namespace App\Support;

use App\Models\User;
use App\Services\AddOnRuleService;

class StaffActor
{
    public static function current(): ?User
    {
        $actor = request()->user('sanctum');

        return app(AddOnRuleService::class)->isStaff($actor) ? $actor : null;
    }

    public static function id(): ?int
    {
        return self::current()?->id;
    }
}
