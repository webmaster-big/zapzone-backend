<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds three example membership plans for testing:
 *   1. Single-location  — "Local Explorer"      ($29.99 / month, Battle Creek only)
 *   2. Multi-location   — "Multi-Park Flex"     ($49.99 / month, 5 specific locations)
 *   3. All-locations    — "Unlimited All Parks" ($79.99 / month, every location)
 *
 * Run with:
 *   php artisan db:seed --class=MembershipPlanSeeder
 */
class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Use the first company in the database
        $companyId = DB::table('companies')->value('id');
        if (! $companyId) {
            $this->command->warn('No company found. Skipping membership plan seeder.');
            return;
        }

        // Look up locations by name (created during the normal location seeder)
        $locationByName = Location::whereIn('name', [
            'Battle Creek', 'Brighton', 'Canton', 'Farmington', 'Lansing',
            'Portage', 'Sterling Heights', 'Taylor', 'Warren', 'Waterford', 'Ypsilanti',
        ])->pluck('id', 'name');

        // ---------------------------------------------------------------
        // 1. SINGLE-LOCATION PLAN
        // ---------------------------------------------------------------
        $singlePlan = MembershipPlan::updateOrCreate(
            ['company_id' => $companyId, 'slug' => 'local-explorer-brighton'],
            [
                'name'                   => 'Local Explorer',
                'description'            => 'Perfect for Brighton regulars. Unlimited visits at Brighton with member-only savings.',
                'benefits'               => [
                    'Unlimited visits at Brighton',
                    '10% off bookings & add-ons',
                    'Priority booking window (3 days early)',
                    'Free socks on first visit',
                ],
                'tier'                   => 'basic',
                'price'                  => 29.99,
                'billing_cycle'          => 'monthly',
                'term_length_months'     => 1,
                'trial_days'             => 7,          // 7-day free trial
                'usage_type'             => 'unlimited',
                'unlimited_visits_per_term' => true,
                'unlimited_uses_per_term'   => true,
                'location_access_mode'   => 'single',
                'location_id'            => $locationByName->get('Brighton'),
                'grace_period_days'      => 7,
                'failed_payment_retry_days' => 3,
                'cancellation_mode'      => 'end_of_term',
                'discount_percent'       => 10.00,
                'requires_photo'         => false,
                'is_family_or_group'     => false,
                'is_active'              => true,
            ]
        );

        // ---------------------------------------------------------------
        // 2. MULTI-LOCATION PLAN
        // ---------------------------------------------------------------
        $multiPlan = MembershipPlan::updateOrCreate(
            ['company_id' => $companyId, 'slug' => 'multi-park-flex'],
            [
                'name'                   => 'Multi-Park Flex',
                'description'            => 'Visit any of 5 parks at your convenience. Great for families spread across the metro area.',
                'benefits'               => [
                    'Unlimited visits at 5 locations',
                    '15% off bookings & add-ons',
                    'Priority booking window (5 days early)',
                    'Free guest pass every 3 months',
                    'Exclusive member events access',
                ],
                'tier'                   => 'premium',
                'price'                  => 49.99,
                'billing_cycle'          => 'monthly',
                'term_length_months'     => 1,
                'trial_days'             => 0,
                'usage_type'             => 'unlimited',
                'unlimited_visits_per_term' => true,
                'unlimited_uses_per_term'   => true,
                'location_access_mode'   => 'multi',
                'grace_period_days'      => 7,
                'failed_payment_retry_days' => 3,
                'cancellation_mode'      => 'end_of_term',
                'discount_percent'       => 15.00,
                'requires_photo'         => false,
                'is_family_or_group'     => false,
                'is_active'              => true,
            ]
        );

        // Attach 5 approved locations for the multi plan
        $multiLocations = ['Battle Creek', 'Brighton', 'Canton', 'Farmington', 'Lansing'];
        $multiLocationIds = collect($multiLocations)
            ->map(fn($name) => $locationByName->get($name))
            ->filter()
            ->values()
            ->toArray();

        if (! empty($multiLocationIds)) {
            $multiPlan->approvedLocations()->sync($multiLocationIds);
        } else {
            $this->command->warn('Multi-park locations not found in DB. The plan was created but has no approved locations.');
        }

        // ---------------------------------------------------------------
        // 3. ALL-LOCATIONS PLAN
        // ---------------------------------------------------------------
        MembershipPlan::updateOrCreate(
            ['company_id' => $companyId, 'slug' => 'unlimited-all-parks'],
            [
                'name'                   => 'Unlimited All Parks',
                'description'            => 'The ultimate membership — visit every single location whenever you want, with the best savings across the board.',
                'benefits'               => [
                    'Unlimited visits at all 11 locations',
                    '20% off bookings, add-ons & retail',
                    'Priority booking window (7 days early)',
                    'Free guest pass every month',
                    'Exclusive VIP events access',
                    'Free socks on every visit',
                    'Complimentary birthday party upgrade',
                ],
                'tier'                   => 'unlimited',
                'price'                  => 79.99,
                'billing_cycle'          => 'monthly',
                'term_length_months'     => 1,
                'trial_days'             => 0,
                'usage_type'             => 'unlimited',
                'unlimited_visits_per_term' => true,
                'unlimited_uses_per_term'   => true,
                'location_access_mode'   => 'all',
                'grace_period_days'      => 7,
                'failed_payment_retry_days' => 3,
                'cancellation_mode'      => 'end_of_term',
                'discount_percent'       => 20.00,
                'requires_photo'         => false,
                'is_family_or_group'     => false,
                'is_active'              => true,
            ]
        );

        $this->command->info('✓ Membership plan seeder complete:');
        $this->command->info("  1. Local Explorer          — \$29.99/mo  (single: Brighton, 7-day trial)");
        $this->command->info("  2. Multi-Park Flex         — \$49.99/mo  (multi: 5 locations)");
        $this->command->info("  3. Unlimited All Parks     — \$79.99/mo  (all 11 locations)");
    }
}
