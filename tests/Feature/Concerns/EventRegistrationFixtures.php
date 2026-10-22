<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

trait EventRegistrationFixtures
{
    private int $submissionSequence = 0;

    protected function makeCompany(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => strtolower((string) preg_replace('/\W+/', '', $name)) . '@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Test Way',
        ]);
    }

    protected function makeVenue(Company $company, string $city): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($city) . '.' . $company->id . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    protected function makeStaff(Company $company, ?Location $location, string $handle, string $role): User
    {
        return User::create([
            'company_id' => $company->id,
            'location_id' => $location?->id,
            'first_name' => ucfirst($handle),
            'last_name' => 'Tester',
            'email' => "{$handle}@zapzone.test",
            'password' => 'Password123!',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    protected function makeCustomer(): Customer
    {
        return Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat.' . uniqid() . '@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
    }

    protected function makeForm(Location $location, string $name, string $date, array $attributes = [], bool $grouped = true): RegistrationEvent
    {
        $event = new RegistrationEvent(array_merge([
            'location_id' => $location->id,
            'name' => $name,
            'event_date' => $date,
            'is_open' => true,
        ], $attributes));
        $event->company_id = $location->company_id;

        if ($grouped && RegistrationEventGroup::isAvailable()) {
            $group = new RegistrationEventGroup([
                'name' => $event->name,
                'event_date' => $event->event_date,
                'description' => $event->description,
                'confirmation_message' => $event->confirmation_message,
                'header_image_path' => $event->header_image_path,
                'header_image_width' => $event->header_image_width,
                'header_image_height' => $event->header_image_height,
            ]);
            $group->company_id = $location->company_id;
            $group->save();
            $event->group_id = $group->id;
        }

        $event->save();

        return $event->fresh();
    }

    protected function actAs(?object $who, array $abilities = ['*']): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $headers = ['Accept' => 'application/json'];
        if ($who !== null) {
            $headers['Authorization'] = 'Bearer ' . $who->createToken('test', $abilities)->plainTextToken;
        }

        $this->withHeaders($headers);
    }

    protected function at(string $michiganTime): void
    {
        $this->travelTo(Carbon::parse($michiganTime, 'America/Detroit'));
    }

    protected function nextSubmissionKey(): string
    {
        $this->submissionSequence++;

        return 'test-submission-' . str_pad((string) $this->submissionSequence, 6, '0', STR_PAD_LEFT);
    }

    protected function signUp(RegistrationEvent $event, string $name, string $email, string $phone, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/registration-forms/' . $event->public_code, [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'submission_key' => $key ?? $this->nextSubmissionKey(),
        ]);
    }
}
