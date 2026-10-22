<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Http;

use MailboxMcp\Http\ClientIp;
use MailboxMcp\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new Request(
            'GET',
            '/',
            ['Content-Type' => 'application/json', 'X-Foo' => 'bar'],
            '',
            '127.0.0.1',
            false,
        );

        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertSame('application/json', $request->header('CONTENT-TYPE'));
        $this->assertSame('bar', $request->header('x-foo'));
        $this->assertNull($request->header('missing'));
    }

    public function testAuthorizationFromRedirectVar(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected-token',
        ], '', []);

        $this->assertSame('Bearer redirected-token', $request->header('authorization'));
    }

    public function testAuthorizationPrefersHttpAuthorizationOverRedirectVar(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_AUTHORIZATION' => 'Bearer direct-token',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected-token',
        ], '', []);

        $this->assertSame('Bearer direct-token', $request->header('authorization'));
    }

    public function testFromGlobalsConvertsHttpHeadersAndMethodAndPath(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/mailbox/inbox?page=2&limit=10',
            'HTTP_X_CUSTOM_HEADER' => 'value',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
        ], '{"ok":true}', []);

        $this->assertSame('POST', $request->method);
        $this->assertSame('/mailbox/inbox', $request->path);
        $this->assertSame('{"ok":true}', $request->body);
        $this->assertSame('value', $request->header('x-custom-header'));
        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertSame('42', $request->header('content-length'));
    }

    public function testFromGlobalsDefaultsPathToSlash(): void
    {
        $request = Request::fromGlobals(['REQUEST_METHOD' => 'GET'], '', []);

        $this->assertSame('/', $request->path);
    }

    public function testFromGlobalsIsHttpsWhenHttpsOn(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTPS' => 'on',
        ], '', []);

        $this->assertTrue($request->isHttps);
    }

    public function testFromGlobalsIsNotHttpsWhenHttpsOff(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTPS' => 'off',
        ], '', []);

        $this->assertFalse($request->isHttps);
    }

    public function testFromGlobalsIsHttpsViaTrustedForwardedProto(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '', ['10.0.0.1']);

        $this->assertTrue($request->isHttps);
    }

    public function testFromGlobalsIgnoresForwardedProtoWhenProxyUntrusted(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '', ['10.0.0.1']);

        $this->assertFalse($request->isHttps);
    }

    public function testFromGlobalsSetsClientIp(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '203.0.113.9',
        ], '', []);

        $this->assertSame('203.0.113.9', $request->clientIp);
    }

    public function testClientIpIgnoresForwardedForWhenProxyUntrusted(): void
    {
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ], []);

        $this->assertSame('198.51.100.7', $ip);
    }

    public function testClientIpUsesForwardedForWhenProxyTrusted(): void
    {
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.1',
        ], ['10.0.0.1']);

        $this->assertSame('203.0.113.5', $ip);
    }

    public function testClientIpDefaultsWhenRemoteAddrMissing(): void
    {
        $this->assertSame('0.0.0.0', ClientIp::resolve([], []));
    }

    public function testClientIpFallsBackWhenForwardedForEntryIsInvalid(): void
    {
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
        ], ['10.0.0.1']);

        $this->assertSame('10.0.0.1', $ip);
    }
}
