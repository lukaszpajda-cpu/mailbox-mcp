<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit;

use MailboxMcp\Version;
use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function testAutoloaderLoadsVersion(): void
    {
        $this->assertSame('0.2.0', Version::STRING);
    }
}
