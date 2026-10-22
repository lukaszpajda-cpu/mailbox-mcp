<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Support;

use MailboxMcp\Support\HttpFetcher;
use MailboxMcp\Support\HttpFetchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Only the checks that run before any I/O are unit-tested here; a real
 * fetch is covered by {@see testFetchesRealEndpoint()}, which needs
 * network access and an endpoint given by
 * MAILBOX_MCP_TEST_AVAILABILITY_URL (skipped otherwise).
 */
final class HttpFetcherTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function rejectedUrls(): array
    {
        $cases = [];

        foreach ([
            'http' => 'http://127.0.0.1:1/availability.php',
            'ftp' => 'ftp://127.0.0.1:1/availability.json',
            'file' => 'file:///etc/passwd',
            'userinfo' => 'https://user:pass@127.0.0.1:1/availability.php',
            'relative' => '/availability.php',
            'no host' => 'https:///availability.php',
        ] as $name => $url) {
            $cases["curl, {$name}"] = [$url, true];
            $cases["stream, {$name}"] = [$url, false];
        }

        return $cases;
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsNonHttpsUrl(string $url, bool $useCurl): void
    {
        $this->expectException(HttpFetchException::class);
        $this->expectExceptionMessage('fetch failed: only absolute https:// URLs without credentials are allowed');

        (new HttpFetcher($useCurl))->get($url, 1, 1024);
    }

    public function testRejectsBadLimits(): void
    {
        foreach ([[0, 1024], [1, 0]] as [$timeout, $maxBytes]) {
            try {
                (new HttpFetcher())->get('https://127.0.0.1:1/', $timeout, $maxBytes);
                $this->fail('accepted bad limits');
            } catch (HttpFetchException $e) {
                $this->assertSame('fetch failed: timeout and size limit must be positive', $e->getMessage());
            }
        }
    }

    public function testExceptionMessageNeverContainsTheUrl(): void
    {
        try {
            (new HttpFetcher())->get('http://127.0.0.1:1/secret-path?token=x', 1, 1024);
            $this->fail('expected HttpFetchException');
        } catch (HttpFetchException $e) {
            $this->assertStringNotContainsString('secret-path', $e->getMessage());
            $this->assertStringNotContainsString('token', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function transports(): array
    {
        return ['curl' => [true], 'stream' => [false]];
    }

    #[Group('network')]
    #[DataProvider('transports')]
    public function testFetchesRealEndpoint(bool $useCurl): void
    {
        $url = getenv('MAILBOX_MCP_TEST_AVAILABILITY_URL');

        if (!is_string($url) || '' === $url) {
            $this->markTestSkipped('set MAILBOX_MCP_TEST_AVAILABILITY_URL to run network tests');
        }

        if ($useCurl && !function_exists('curl_init')) {
            $this->markTestSkipped('curl extension is not available');
        }

        $fetcher = new HttpFetcher($useCurl);
        $body = $fetcher->get($url, 10, 262144);

        $this->assertNotSame('', $body);

        // A cap below the body size must fail rather than truncate.
        $this->expectException(HttpFetchException::class);
        $fetcher->get($url, 10, max(1, strlen($body) - 1));
    }
}
