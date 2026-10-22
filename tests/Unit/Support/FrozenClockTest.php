<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Support;

use DateTimeImmutable;
use MailboxMcp\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    public function testAdvanceMovesTime(): void
    {
        $c = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $c->advance(90);
        $this->assertSame('2026-10-21T10:01:30+00:00', $c->now()->format(DATE_ATOM));
    }
}
