<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Security;

use MailboxMcp\Http\Request;
use MailboxMcp\Security\BearerTokenAuthenticator;
use PHPUnit\Framework\TestCase;

final class BearerTokenAuthenticatorTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDE';

    private function request(?string $authorization): Request
    {
        $headers = null === $authorization ? [] : ['Authorization' => $authorization];

        return new Request('GET', '/', $headers, '', '127.0.0.1', true);
    }

    public function testHeaderTokenAccepted(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, false);

        $result = $authenticator->authenticate($this->request('Bearer ' . self::TOKEN), null);

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
    }

    public function testHeaderTokenCaseOfSchemeIgnored(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, false);

        $result = $authenticator->authenticate($this->request('bearer ' . self::TOKEN), null);

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
    }

    public function testWrongHeaderTokenRejected(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, true);

        $result = $authenticator->authenticate($this->request('Bearer ' . self::TOKEN . 'x'), 'irrelevant-path-token');

        $this->assertFalse($result->ok);
        $this->assertSame('bad_header_token', $result->reason);
    }

    public function testPathTokenRejectedWhenDisabled(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, false);

        $result = $authenticator->authenticate($this->request(null), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame('path_token_disabled', $result->reason);
    }

    public function testPathTokenAcceptedWhenEnabled(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, true);

        $result = $authenticator->authenticate($this->request(null), self::TOKEN);

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
    }

    public function testWrongPathTokenRejected(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, true);

        $result = $authenticator->authenticate($this->request(null), 'wrong-token-value');

        $this->assertFalse($result->ok);
        $this->assertSame('bad_path_token', $result->reason);
    }

    public function testNoTokenRejected(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, true);

        $result = $authenticator->authenticate($this->request(null), null);

        $this->assertFalse($result->ok);
        $this->assertSame('no_token', $result->reason);
    }

    public function testTimingSafeCompareUsedForDifferentLengths(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, false);

        $result = $authenticator->authenticate($this->request('Bearer x'), null);

        $this->assertFalse($result->ok);
        $this->assertSame('bad_header_token', $result->reason);
    }

    public function testMalformedBearerHeaderDoesNotFallThrough(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, true);

        $result = $authenticator->authenticate($this->request('Bearer'), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame('bad_header_token', $result->reason);
    }

    public function testNonBearerSchemeDoesNotFallThrough(): void
    {
        $authenticator = new BearerTokenAuthenticator(self::TOKEN, true);

        $result = $authenticator->authenticate($this->request('Basic xxx'), self::TOKEN);

        $this->assertFalse($result->ok);
        $this->assertSame('bad_header_token', $result->reason);
    }
}
