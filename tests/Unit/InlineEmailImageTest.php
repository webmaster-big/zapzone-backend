<?php

namespace Tests\Unit;

use App\Services\GmailApiService;
use Tests\TestCase;

class InlineEmailImageTest extends TestCase
{
    public function test_only_images_inside_the_email_images_folder_are_read_from_disk(): void
    {
        $directory = storage_path('app/public/email-images');
        @mkdir($directory, 0775, true);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        file_put_contents($directory . '/inline-test.png', $png);
        file_put_contents($directory . '/not-an-image.txt', 'secret');

        try {
            $image = GmailApiService::localEmailImage('https://book.test/storage/email-images/inline-test.png');
            $this->assertNotNull($image);
            $this->assertSame('image/png', $image['mime_type']);

            $this->assertNull(GmailApiService::localEmailImage('https://book.test/storage/email-images/../../../../.env'));
            $this->assertNull(GmailApiService::localEmailImage('https://book.test/storage/email-images/%2e%2e/%2e%2e/%2e%2e/%2e%2e/.env'));
            $this->assertNull(GmailApiService::localEmailImage('https://book.test/storage/email-images/not-an-image.txt'));
            $this->assertNull(GmailApiService::inlineImageData('https://book.test/storage/email-images/../../../../.env'));
        } finally {
            @unlink($directory . '/inline-test.png');
            @unlink($directory . '/not-an-image.txt');
        }
    }

    public function test_only_public_web_addresses_are_fetched(): void
    {
        foreach ([
            'file:///etc/passwd',
            'php://filter/resource=/etc/passwd',
            'ftp://example.com/x.png',
            'http://127.0.0.1/x.png',
            'http://localhost/x.png',
            'http://10.0.0.5/x.png',
            'http://192.168.1.10/x.png',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/x.png',
            'gopher://example.com/',
        ] as $url) {
            $this->assertNull(GmailApiService::remoteImage($url), $url);
        }

        $this->assertTrue(GmailApiService::isPublicHost('8.8.8.8'));
        $this->assertTrue(GmailApiService::isPublicHost('100.128.0.1'));
        $this->assertTrue(GmailApiService::isPublicHost('2001:4860:4860::8888'));

        foreach ([
            '127.0.0.1', '169.254.169.254', '::1', 'localhost', '0.0.0.0', '10.1.2.3', '172.16.0.1',
            '100.64.0.1', '100.100.100.200', '224.0.0.1', '239.255.255.250', '198.18.0.1',
            '::ffff:127.0.0.1', 'fd00::1', 'fe80::1', 'ff02::1', '64:ff9b::7f00:1', '2002:7f00:1::1', '2130706433',
        ] as $host) {
            $this->assertFalse(GmailApiService::isPublicHost($host), $host);
        }
    }
}
