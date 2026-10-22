<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Security;

use MailboxMcp\Security\OriginGuard;
use PHPUnit\Framework\TestCase;

final class OriginGuardTest extends TestCase
{
    public function testOriginNullAllowed(): void
    {
        $guard = new OriginGuard([]);

        $this->assertTrue($guard->allows(null));
    }

    public function testOriginExactMatchOnly(): void
    {
        $guard = new OriginGuard(['https://claude.ai']);

        $this->assertTrue($guard->allows('https://claude.ai'));
        $this->assertFalse($guard->allows('https://claude.ai.evil.com'));
    }

    public function testEmptyAllowListRejectsEveryNonNullOrigin(): void
    {
        $guard = new OriginGuard([]);

        $this->assertFalse($guard->allows('https://claude.ai'));
    }

    public function testSchemeAndHostCaseIgnored(): void
    {
        $guard = new OriginGuard(['https://claude.ai']);

        $this->assertTrue($guard->allows('HTTPS://CLAUDE.AI'));
    }

    public function testPortIsSignificant(): void
    {
        $guard = new OriginGuard(['https://claude.ai']);

        $this->assertFalse($guard->allows('https://claude.ai:8443'));
    }

    public function testUnparsableOriginRejected(): void
    {
        $guard = new OriginGuard(['https://claude.ai']);

        $this->assertFalse($guard->allows('not a url'));
    }
}
