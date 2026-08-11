<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhotoMessageTemplate extends Model
{
    use HasFactory;

    public const KIND_IMMEDIATE = 'immediate';
    public const KIND_NEXT_DAY = 'next_day';
    public const KIND_KIOSK = 'kiosk';

    public const KINDS = [self::KIND_IMMEDIATE, self::KIND_NEXT_DAY, self::KIND_KIOSK];

    public const VARIABLES = [
        'first_name',
        'location_name',
        'photo_date',
        'photo_link',
        'expires_on',
        'business_name',
        'support_contact',
        'photo_count',
    ];

    protected $fillable = [
        'company_id',
        'kind',
        'email_subject',
        'email_body',
        'sms_body',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function defaults(): array
    {
        return [
            self::KIND_IMMEDIATE => [
                'email_subject' => 'Your photos from {{location_name}} are ready',
                'email_body' => "<p>Hi {{first_name}},</p>\n<p>Thanks for visiting {{location_name}} on {{photo_date}}. Your photos are ready to view and download.</p>\n<p><a href=\"{{photo_link}}\">View your photos</a></p>\n<p>This link works until {{expires_on}}.</p>\n<p>{{business_name}}<br>{{support_contact}}</p>",
                'sms_body' => "Hi {{first_name}}, your {{location_name}} photos from {{photo_date}} are ready: {{photo_link}} (available until {{expires_on}})",
            ],
            self::KIND_NEXT_DAY => [
                'email_subject' => 'Your photos from {{location_name}}',
                'email_body' => "<p>Good morning {{first_name}},</p>\n<p>Here are your photos from {{location_name}} on {{photo_date}}.</p>\n<p><a href=\"{{photo_link}}\">View your photos</a></p>\n<p>This link works until {{expires_on}}.</p>\n<p>{{business_name}}<br>{{support_contact}}</p>",
                'sms_body' => "Good morning {{first_name}} — your {{location_name}} photos from {{photo_date}} are ready: {{photo_link}} (available until {{expires_on}})",
            ],
            self::KIND_KIOSK => [
                'email_subject' => 'Your {{location_name}} photo',
                'email_body' => "<p>Hi {{first_name}},</p>\n<p>Here is the photo you took at {{location_name}} on {{photo_date}}.</p>\n<p><a href=\"{{photo_link}}\">View your photo</a></p>\n<p>This link works until {{expires_on}}.</p>\n<p>{{business_name}}<br>{{support_contact}}</p>",
                'sms_body' => "Here is your {{location_name}} photo: {{photo_link}} (available until {{expires_on}})",
            ],
        ];
    }

    public static function forCompany(?int $companyId, string $kind): self
    {
        $template = self::where('company_id', $companyId)->where('kind', $kind)->first();

        if ($template) {
            return $template;
        }

        $defaults = self::defaults()[$kind] ?? self::defaults()[self::KIND_IMMEDIATE];

        return self::create(array_merge($defaults, [
            'company_id' => $companyId,
            'kind' => $kind,
        ]));
    }

    public static function allForCompany(?int $companyId): Collection
    {
        foreach (self::KINDS as $kind) {
            self::forCompany($companyId, $kind);
        }

        return self::where('company_id', $companyId)->orderBy('kind')->get();
    }

    public function render(string $field, array $variables): string
    {
        $content = (string) $this->{$field};

        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) ($value ?? ''), $content);
            $content = str_replace('{{ ' . $key . ' }}', (string) ($value ?? ''), $content);
        }

        return $content;
    }
}
