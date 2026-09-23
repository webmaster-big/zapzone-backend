<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ApiErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('logs/testing-api.log');

        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        config([
            'logging.channels.api.driver' => 'single',
            'logging.channels.api.path' => $this->logPath,
        ]);

        Log::forgetChannel('api');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    private function logContents(): string
    {
        return file_exists($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    private function staff(): User
    {
        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '2485551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        return User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_a_refused_request_is_written_to_the_api_log_with_who_and_why(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/promos', ['name' => 'No code or dates'])
            ->assertStatus(422);

        $log = $this->logContents();

        $this->assertStringContainsString('API request refused', $log);
        $this->assertStringContainsString('"status":422', $log);
        $this->assertStringContainsString('api/promos', $log);
        $this->assertStringContainsString('"role":"location_manager"', $log);
        $this->assertStringContainsString('invalid_fields', $log);
    }

    public function test_a_successful_request_is_not_logged_as_a_failure(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')->getJson('/api/promos')->assertOk();

        $this->assertStringNotContainsString('API request refused', $this->logContents());
    }

    public function test_secrets_never_reach_the_log(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@zapzone.test',
            'password' => 'sup3r-s3cret-value',
        ]);

        $log = $this->logContents();

        $this->assertStringNotContainsString('sup3r-s3cret-value', $log);
        $this->assertStringNotContainsString('"password"', $log);
    }

    public function test_every_response_carries_a_request_id_the_browser_can_quote(): void
    {
        $response = $this->getJson('/api/promos');

        $response->assertHeader('X-Request-Id');
        $this->assertStringContainsString($response->headers->get('X-Request-Id'), $this->logContents());
    }

    public function test_the_browser_can_report_an_error_it_hit(): void
    {
        $this->postJson('/api/client-errors', [
            'message' => 'TypeError: cannot read properties of undefined',
            'kind' => 'render',
            'page' => '/packages/promos',
            'action' => 'create promo',
        ])->assertStatus(202);

        $log = $this->logContents();

        $this->assertStringContainsString('Frontend error', $log);
        $this->assertStringContainsString('"source":"frontend"', $log);
        $this->assertStringContainsString('/packages/promos', $log);
    }

    public function test_a_junk_error_report_is_refused(): void
    {
        $this->postJson('/api/client-errors', ['kind' => 'render'])->assertStatus(422);
        $this->postJson('/api/client-errors', ['message' => str_repeat('x', 5000)])->assertStatus(422);
    }
}
