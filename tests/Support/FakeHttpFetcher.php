<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Support;

use MailboxMcp\Support\HttpFetcherInterface;
use Throwable;

/**
 * Test double for {@see HttpFetcherInterface}: returns a canned body, or
 * throws a canned exception, and records every call.
 */
final class FakeHttpFetcher implements HttpFetcherInterface
{
    /** @var list<array{url: string, timeout: int, maxBytes: int}> */
    public array $calls = [];

    public function __construct(
        public string $body = '',
        public ?Throwable $throw = null,
    ) {
    }

    public function get(string $url, int $timeoutSeconds, int $maxBytes): string
    {
        $this->calls[] = ['url' => $url, 'timeout' => $timeoutSeconds, 'maxBytes' => $maxBytes];

        if (null !== $this->throw) {
            throw $this->throw;
        }

        return $this->body;
    }
}
