<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Security;

use DateTimeImmutable;
use MailboxMcp\Security\RateLimiter;
use MailboxMcp\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mailbox-mcp-ratelimiter-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }

        $files = glob($this->tmpDir . '/*') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);
    }

    public function testAllowsUpToLimitThenDenies(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:10+00:00'));
        $limiter = new RateLimiter($this->tmpDir, $clock, 3, 100);

        $this->assertTrue($limiter->hit('1.2.3.4')->allowed);
        $this->assertTrue($limiter->hit('1.2.3.4')->allowed);
        $this->assertTrue($limiter->hit('1.2.3.4')->allowed);

        $fourth = $limiter->hit('1.2.3.4');

        $this->assertFalse($fourth->allowed);
        $this->assertGreaterThanOrEqual(1, $fourth->retryAfterSeconds);
        $this->assertLessThanOrEqual(60, $fourth->retryAfterSeconds);
    }

    public function testWindowResetsNextMinute(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:50+00:00'));
        $limiter = new RateLimiter($this->tmpDir, $clock, 1, 100);

        $this->assertTrue($limiter->hit('1.2.3.4')->allowed);
        $this->assertFalse($limiter->hit('1.2.3.4')->allowed);

        $clock->advance(15); // now 10:01:05 — crosses the minute boundary

        $this->assertTrue($limiter->hit('1.2.3.4')->allowed);
    }

    public function testGlobalLimitAppliesAcrossIps(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $limiter = new RateLimiter($this->tmpDir, $clock, 10, 2);

        $this->assertTrue($limiter->hit('1.1.1.1')->allowed);
        $this->assertTrue($limiter->hit('2.2.2.2')->allowed);
        $this->assertFalse($limiter->hit('3.3.3.3')->allowed);
    }

    public function testDeniedPerIpHitDoesNotConsumeGlobalBudget(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $limiter = new RateLimiter($this->tmpDir, $clock, 1, 2);

        $this->assertTrue($limiter->hit('1.1.1.1')->allowed); // global count -> 1
        $this->assertFalse($limiter->hit('1.1.1.1')->allowed); // ip-denied, global untouched

        // If the denied hit above had also incremented the global counter,
        // it would now be at 3 and this would be denied too.
        $this->assertTrue($limiter->hit('2.2.2.2')->allowed); // global count -> 2
    }

    public function testFirstDenialFlagOnlyOnce(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $limiter = new RateLimiter($this->tmpDir, $clock, 2, 100);

        $first = $limiter->hit('1.2.3.4');
        $second = $limiter->hit('1.2.3.4');
        $third = $limiter->hit('1.2.3.4');
        $fourth = $limiter->hit('1.2.3.4');

        $this->assertTrue($first->allowed);
        $this->assertFalse($first->firstDenial);

        $this->assertTrue($second->allowed);
        $this->assertFalse($second->firstDenial);

        $this->assertFalse($third->allowed);
        $this->assertTrue($third->firstDenial);

        $this->assertFalse($fourth->allowed);
        $this->assertFalse($fourth->firstDenial);
    }

    public function testGcRemovesStaleFiles(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:10+00:00'));
        $limiter = new RateLimiter($this->tmpDir, $clock, 10, 100);

        $limiter->hit('1.1.1.1'); // window 10:00 (per-IP + global)
        $clock->advance(60);      // 10:01:10
        $limiter->hit('2.2.2.2'); // window 10:01 (per-IP; global moves to 10:01)

        $old = $this->tmpDir . '/' . hash('sha256', '1.1.1.1') . '.json';
        $current = $this->tmpDir . '/' . hash('sha256', '2.2.2.2') . '.json';
        $global = $this->tmpDir . '/_global.json';

        // An unreadable/corrupt file is only removed once its mtime is > 24 h old.
        $corruptFresh = $this->tmpDir . '/corrupt-fresh.json';
        $corruptOld = $this->tmpDir . '/corrupt-old.json';
        file_put_contents($corruptFresh, 'not json');
        file_put_contents($corruptOld, 'not json');
        touch($corruptFresh, $clock->now()->getTimestamp() - 3600);
        touch($corruptOld, $clock->now()->getTimestamp() - 86401);

        $limiter->gc();

        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($corruptOld);
        $this->assertFileExists($current);
        $this->assertFileExists($global);
        $this->assertFileExists($corruptFresh);

        // A GC'd IP simply starts a fresh window.
        $this->assertTrue($limiter->hit('1.1.1.1')->allowed);
    }
}
