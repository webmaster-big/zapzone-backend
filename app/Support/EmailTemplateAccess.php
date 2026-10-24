<?php

namespace App\Support;

use App\Http\Middleware\EnsureStaff;
use App\Models\EmailTemplate;

class EmailTemplateAccess
{
    public const COMPANY_ROLES = ['company_admin', 'admin', 'owner'];

    public const EVENT_PHOTO_ROLES = ['company_admin', 'admin', 'owner', 'location_manager'];

    public static function isCompanyLevel($user): bool
    {
        return $user !== null && in_array(EnsureStaff::effectiveRole($user), self::COMPANY_ROLES, true);
    }

    public static function canManageEventPhotos($user): bool
    {
        return $user !== null && in_array(EnsureStaff::effectiveRole($user), self::EVENT_PHOTO_ROLES, true);
    }

    public static function canManage(EmailTemplate $template, $user, ?int $ownLocationId): bool
    {
        if ($template->isEventPhotos() && !self::canManageEventPhotos($user)) {
            return false;
        }

        if (self::isCompanyLevel($user)) {
            return true;
        }

        return $ownLocationId !== null && $template->targetLocationIds() === [$ownLocationId];
    }
}
