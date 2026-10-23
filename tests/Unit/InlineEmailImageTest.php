<?php

namespace Tests\Unit;

use App\Services\GmailApiService;
use Tests\TestCase;

class InlineEmailImageTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private $server = null;

    private ?string $serverRoot = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        if ($this->serverRoot !== null) {
            array_map('unlink', glob($this->serverRoot . '/*') ?: []);
            @rmdir($this->serverRoot);
        }

        parent::tearDown();
    }

    public function test_only_images_inside_the_email_images_folder_are_read_from_disk(): void
    {
        $directory = storage_path('app/public/email-images');
        @mkdir($directory, 0775, true);
        $png = base64_decode(self::PNG);
        $outside = storage_path('app/public/inline-outside-test.png');
        file_put_contents($directory . '/inline-test.png', $png);
        file_put_contents($directory . '/not-an-image.txt', 'secret');
        file_put_contents($outside, $png);

        try {
            $image = GmailApiService::localEmailImage('https://book.test/storage/email-images/inline-test.png');
            $this->assertNotNull($image);
            $this->assertSame('image/png', $image['mime_type']);
            $this->assertSame($png, $image['data']);

            $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->file($outside));
            foreach ([
                '../inline-outside-test.png',
                '..%2finline-outside-test.png',
                '%2e%2e/inline-outside-test.png',
                '%2E%2E%2Finline-outside-test.png',
                '..%5cinline-outside-test.png',
                './../inline-outside-test.png',
                '%252e%252e/inline-outside-test.png',
            ] as $path) {
                $this->assertNull(GmailApiService::localEmailImage('https://book.test/storage/email-images/' . $path), $path);
            }
            $this->assertNull(GmailApiService::inlineImageData('https://book.test/storage/email-images/../inline-outside-test.png'));

            $this->assertNull(GmailApiService::localEmailImage('https://book.test/storage/email-images/../../../../.env'));
            $this->assertNull(GmailApiService::localEmailImage('https://book.test/storage/email-images/not-an-image.txt'));
        } finally {
            @unlink($directory . '/inline-test.png');
            @unlink($directory . '/not-an-image.txt');
            @unlink($outside);
        }
    }

    public function test_only_public_addresses_count_as_public(): void
    {
        $this->assertTrue(GmailApiService::isPublicHost('8.8.8.8'));
        $this->assertTrue(GmailApiService::isPublicHost('100.128.0.1'));
        $this->assertTrue(GmailApiService::isPublicHost('2001:4860:4860::8888'));
        $this->assertSame(['8.8.8.8'], GmailApiService::publicAddresses('8.8.8.8'));

        foreach ([
            '127.0.0.1', '169.254.169.254', '::1', 'localhost', '0.0.0.0', '10.1.2.3', '172.16.0.1',
            '100.64.0.1', '100.100.100.200', '224.0.0.1', '239.255.255.250', '198.18.0.1',
            '::ffff:127.0.0.1', 'fd00::1', 'fe80::1', 'ff02::1', '64:ff9b::7f00:1', '2002:7f00:1::1', '2130706433',
            'no-such-host.invalid',
        ] as $host) {
            $this->assertFalse(GmailApiService::isPublicHost($host), $host);
            $this->assertSame([], GmailApiService::publicAddresses($host), $host);
        }

        foreach (['file:///etc/passwd', 'php://filter/resource=/etc/passwd', 'ftp://example.com/x.png', 'gopher://example.com/'] as $url) {
            $this->assertNull(GmailApiService::remoteImage($url), $url);
        }
    }

    public function test_remote_images_come_only_from_the_checked_address_within_limits(): void
    {
        $port = $this->startImageServer();
        $png = base64_decode(self::PNG);
        $base = "http://127.0.0.1:{$port}";

        $this->assertSame($png, GmailApiService::fetchPinned("{$base}/image.png", '127.0.0.1'));
        $this->assertNull(GmailApiService::remoteImage("{$base}/image.png"));
        $this->assertNull(GmailApiService::inlineImageData("{$base}/image.png"));

        $this->assertSame($png, GmailApiService::fetchPinned("http://images.zapzone.invalid:{$port}/image.png", '127.0.0.1'));
        $this->assertNull(GmailApiService::fetchPinned("{$base}/redirect.png", '127.0.0.1'));
        $this->assertNull(GmailApiService::fetchPinned("{$base}/big.png", '127.0.0.1'));
        $this->assertNull(GmailApiService::fetchPinned("{$base}/missing.png", '127.0.0.1'));

        $started = microtime(true);
        $this->assertNull(GmailApiService::fetchPinned("{$base}/slow.png", '127.0.0.1', 1));
        $this->assertLessThan(3, microtime(true) - $started);
    }

    private function startImageServer(): int
    {
        $this->serverRoot = sys_get_temp_dir() . '/inline-image-' . bin2hex(random_bytes(4));
        mkdir($this->serverRoot);
        file_put_contents($this->serverRoot . '/image.png', base64_decode(self::PNG));
        file_put_contents($this->serverRoot . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect.png') {
    header('Location: /image.png', true, 302);
    return true;
}
if ($path === '/big.png') {
    header('Content-Type: image/png');
    echo str_repeat('a', 6 * 1024 * 1024);
    return true;
}
if ($path === '/slow.png') {
    sleep(4);
    header('Content-Type: image/png');
    readfile(__DIR__ . '/image.png');
    return true;
}
return false;
PHP);

        foreach (range(1, 5) as $attempt) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->serverRoot, $this->serverRoot . '/router.php'],
                [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
                $pipes
            );
            $deadline = microtime(true) + 5;

            while (microtime(true) < $deadline) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

                if ($socket) {
                    fclose($socket);
                    $this->server = $process;

                    return $port;
                }

                usleep(50000);
            }

            proc_terminate($process);
            proc_close($process);
        }

        $this->fail('The local image server did not start.');
    }
}
