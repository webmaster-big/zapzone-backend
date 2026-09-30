<?php

namespace Tests\Feature;

use App\Services\GmailApiService;
use Google\Service\Gmail\Message;
use Tests\TestCase;

class GmailMessageTest extends TestCase
{
    private function send(string $subject, string $fromName, string $html, array $attachments): string
    {
        config(['gmail.sender_email' => 'bookings@zap-zone.com']);
        $sent = new \ArrayObject();
        $gmail = new \stdClass();
        $gmail->users_messages = new class($sent) {
            public function __construct(private \ArrayObject $sent)
            {
            }

            public function send(string $user, Message $message): Message
            {
                $this->sent[] = $message;
                $result = new Message();
                $result->setId('sent-1');

                return $result;
            }
        };

        $service = (new \ReflectionClass(GmailApiService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(GmailApiService::class, 'service'))->setValue($service, $gmail);

        $this->assertTrue($service->sendEmail('guest@example.test', $subject, $html, $fromName, $attachments));
        $this->assertCount(1, $sent);

        return (string) base64_decode(strtr($sent[0]->getRaw(), '-_', '+/'));
    }

    public function test_the_group_photo_goes_inline_once_and_the_other_photos_are_attached(): void
    {
        $raw = $this->send(
            'Thanks for playing Kids’ Night & Pizza',
            'Zap Zone, Waterford',
            '<p><img src="cid:group-photo-1-abc@zapzone" alt="Your group photo"></p>',
            [
                ['data' => base64_encode('photo-one'), 'filename' => 'room-photo-1.jpg', 'mime_type' => 'image/jpeg', 'content_id' => 'group-photo-1-abc@zapzone'],
                ['data' => base64_encode('photo-two'), 'filename' => 'room-photo-2.jpg', 'mime_type' => 'image/jpeg'],
            ]
        );

        $this->assertStringContainsString('From: "Zap Zone, Waterford" <bookings@zap-zone.com>', $raw);
        $this->assertMatchesRegularExpression('/^Subject: Thanks for playing =\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=\r$/m', $raw);
        $this->assertStringContainsString('Content-ID: <group-photo-1-abc@zapzone>', $raw);
        $this->assertStringContainsString('Content-Disposition: inline; filename="room-photo-1.jpg"', $raw);
        $this->assertSame(1, substr_count($raw, 'Content-Disposition: attachment'));
        $this->assertStringContainsString('Content-Disposition: attachment; filename="room-photo-2.jpg"', $raw);
        $this->assertStringContainsString('multipart/related', $raw);
    }

    public function test_a_sender_name_with_a_comma_and_an_accent_stays_one_display_name(): void
    {
        $raw = $this->send('Thanks for playing', 'Escape Room Zone, Brighton – Kids', '<p>Hi</p>', []);

        $this->assertStringNotContainsString('From: Escape Room Zone, Brighton', $raw);
        $this->assertMatchesRegularExpression('/^From: [^\r\n]*=\?utf-8\?/mi', $raw);
        $this->assertStringContainsString('<bookings@zap-zone.com>', $raw);
    }
}
