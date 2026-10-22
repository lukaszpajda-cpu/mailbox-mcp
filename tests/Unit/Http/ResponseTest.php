<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Http;

use MailboxMcp\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJsonResponseHeaders(): void
    {
        $response = Response::json(200, ['ok' => true]);

        $this->assertSame(200, $response->status);
        $this->assertSame([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $response->headers);
        $this->assertSame('{"ok":true}', $response->body);
    }

    public function testJsonResponseCallerHeadersOverrideDefaultsOnCollision(): void
    {
        $response = Response::json(200, [], ['Cache-Control' => 'max-age=60']);

        $this->assertSame('max-age=60', $response->headers['Cache-Control']);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    public function testJsonResponseCallerCanAddExtraHeaders(): void
    {
        $response = Response::json(200, [], ['X-Request-Id' => 'abc-123']);

        $this->assertSame('abc-123', $response->headers['X-Request-Id']);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testJsonSubstitutesInvalidUtf8(): void
    {
        $response = Response::json(200, ['text' => "bad: \xFF byte"]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString("bad: \u{FFFD} byte", $response->body);
    }

    public function testEmptyResponseHasNoBody(): void
    {
        $response = Response::empty(204);

        $this->assertSame(204, $response->status);
        $this->assertSame([], $response->headers);
        $this->assertSame('', $response->body);
    }

    public function testEmptyResponsePassesThroughHeaders(): void
    {
        $response = Response::empty(204, ['X-Foo' => 'bar']);

        $this->assertSame(['X-Foo' => 'bar'], $response->headers);
    }

    public function testNotFoundBody(): void
    {
        $response = Response::notFound();

        $this->assertSame(404, $response->status);
        $this->assertSame(['Content-Type' => 'text/plain; charset=utf-8'], $response->headers);
        $this->assertSame("Not Found\n", $response->body);
    }
}
