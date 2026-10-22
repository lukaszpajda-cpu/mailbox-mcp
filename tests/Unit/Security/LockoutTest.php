<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Security;

use DateTimeImmutable;
use MailboxMcp\Security\Lockout;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Support\JsonFile;
use PHPUnit\Framework\TestCase;

final class LockoutTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mailbox-mcp-lockout-test-' . bin2hex(random_bytes(8));
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

    public function testLockoutAfterNFailuresWithinWindow(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $lockout = new Lockout($this->tmpDir, $clock, 3, 15, 10);

        $this->assertFalse($lockout->isLocked('1.2.3.4'));

        $lockout->recordFailure('1.2.3.4');
        $this->assertFalse($lockout->isLocked('1.2.3.4'));

        $lockout->recordFailure('1.2.3.4');
        $this->assertFalse($lockout->isLocked('1.2.3.4'));

        $lockout->recordFailure('1.2.3.4');
        $this->assertTrue($lockout->isLocked('1.2.3.4'));
    }

    public function testFailuresOutsideWindowAreForgotten(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $lockout = new Lockout($this->tmpDir, $clock, 3, 15, 10);

        $lockout->recordFailure('1.2.3.4');
        $lockout->recordFailure('1.2.3.4');

        // Well past the 15-minute window: the two earlier failures should
        // no longer count, so a third failure here must not lock the IP.
        $clock->advance(30 * 60);
        $lockout->recordFailure('1.2.3.4');

        $this->assertFalse($lockout->isLocked('1.2.3.4'));
    }

    public function testLockExpires(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $lockout = new Lockout($this->tmpDir, $clock, 1, 15, 10);

        $lockout->recordFailure('1.2.3.4');
        $this->assertTrue($lockout->isLocked('1.2.3.4'));

        $clock->advance(10 * 60);
        $this->assertFalse($lockout->isLocked('1.2.3.4'));
    }

    public function testClearRemovesLockoutFile(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $lockout = new Lockout($this->tmpDir, $clock, 1, 15, 10);

        $lockout->recordFailure('1.2.3.4');
        $this->assertTrue($lockout->isLocked('1.2.3.4'));

        $lockout->clear('1.2.3.4');

        $this->assertFalse($lockout->isLocked('1.2.3.4'));
        $this->assertFileDoesNotExist($this->tmpDir . '/' . hash('sha256', '1.2.3.4') . '.json');
    }

    public function testClearOnMissingFileDoesNotThrow(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $lockout = new Lockout($this->tmpDir, $clock, 1, 15, 10);

        $lockout->clear('1.2.3.4');

        $this->assertFalse($lockout->isLocked('1.2.3.4'));
    }

    public function testGcDeletesExpiredAndStaleFiles(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $lockout = new Lockout($this->tmpDir, $clock, 1, 15, 10);

        // Locked IP whose lock and failure window have both long expired.
        $lockout->recordFailure('1.2.3.4');
        $expiredPath = $this->tmpDir . '/' . hash('sha256', '1.2.3.4') . '.json';

        $clock->advance(60 * 60);

        // Still-active failure, recorded "now" (after the big jump), for an
        // IP that must survive gc().
        $lockout->recordFailure('5.6.7.8');
        $activePath = $this->tmpDir . '/' . hash('sha256', '5.6.7.8') . '.json';

        $lockout->gc();

        $this->assertFileDoesNotExist($expiredPath);
        $this->assertFileExists($activePath);
    }

    public function testGcKeepsFileWithFailuresStillInsideWindowButNoLock(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T10:00:00+00:00'));
        $lockout = new Lockout($this->tmpDir, $clock, 10, 15, 10);

        $lockout->recordFailure('1.2.3.4');
        $path = $this->tmpDir . '/' . hash('sha256', '1.2.3.4') . '.json';
        $this->assertFalse($lockout->isLocked('1.2.3.4'));

        $lockout->gc();

        $this->assertFileExists($path);

        $data = JsonFile::read($path);
        $this->assertNull($data['locked_until']);
    }
}
