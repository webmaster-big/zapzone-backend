<?php

namespace App\Support;

/**
 * Canonical list of all trampoline park locations.
 * Used by the membership system to display valid locations to customers
 * without requiring a database query when the plan covers all locations.
 */
class CompanyLocations
{
    /**
     * All location names in alphabetical order.
     */
    public const NAMES = [
        'Battle Creek',
        'Brighton',
        'Canton',
        'Farmington',
        'Lansing',
        'Portage',
        'Sterling Heights',
        'Taylor',
        'Warren',
        'Waterford',
        'Ypsilanti',
    ];
}
