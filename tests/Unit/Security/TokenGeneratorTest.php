<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Security;

use MailboxMcp\Security\TokenGenerator;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43,}$/';

    public function testGeneratedTokenMatchesConfigRegex(): void
    {
        $token = TokenGenerator::generate();

        $this->assertSame(43, strlen($token));
        $this->assertSame(1, preg_match(self::TOKEN_PATTERN, $token));
    }

    public function testGeneratedTokensAreUnique(): void
    {
        $this->assertNotSame(TokenGenerator::generate(), TokenGenerator::generate());
    }
}
