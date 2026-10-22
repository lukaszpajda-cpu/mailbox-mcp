<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Send;

use DateTimeImmutable;
use MailboxMcp\Mail\Send\DailyCap;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\TestCase;

final class DailyCapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mailbox-mcp-dailycap-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_file($this->dir)) {
            unlink($this->dir);

            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            chmod($file, 0600);
        }

        if (is_dir($this->dir)) {
            chmod($this->dir, 0700);
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testDailyCapBlocks(): void
    {
        $cap = new DailyCap($this->dir, new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00')), 2);

        $cap->assertAvailable('acc');
        $this->assertSame(1, $cap->increment('acc'));
        $cap->assertAvailable('acc');
        $this->assertSame(2, $cap->increment('acc'));

        // other accounts are independent
        $cap->assertAvailable('other');

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Daily send cap reached (2)');
        $cap->assertAvailable('acc');
    }

    public function testDailyCapResetsNextUtcDay(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-22T23:30:00+00:00'));
        $cap = new DailyCap($this->dir, $clock, 1);
        $cap->increment('acc');

        $clock->advance(3600);
        $cap->assertAvailable('acc');
        $this->addToAssertionCount(1);
    }

    public function testDailyCapFileFormat(): void
    {
        // 01:30 at +02:00 is still 2026-10-21 in UTC
        $cap = new DailyCap($this->dir, new FrozenClock(new DateTimeImmutable('2026-10-22T01:30:00+02:00')), 5);
        $cap->increment('acc');
        $cap->increment('acc');

        $path = $this->dir . '/send-acc-20261021.count';
        $this->assertFileExists($path);
        $this->assertSame(['count' => 2], json_decode((string) file_get_contents($path), true));
    }

    public function testStateDirThatIsAFileFailsClosed(): void
    {
        file_put_contents($this->dir, 'x');
        $cap = new DailyCap($this->dir, new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00')), 5);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Send counter storage is not writable; sending refused');
        $cap->assertAvailable('acc');
    }

    public function testReadOnlyStateDirFailsClosed(): void
    {
        if (0 === (function_exists('posix_geteuid') ? posix_geteuid() : 1)) {
            $this->markTestSkipped('root ignores directory permissions');
        }

        mkdir($this->dir, 0500);
        $cap = new DailyCap($this->dir, new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00')), 5);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Send counter storage is not writable; sending refused');
        $cap->assertAvailable('acc');
    }

    public function testIncrementOnUnwritableStorageThrows(): void
    {
        file_put_contents($this->dir, 'x');
        $cap = new DailyCap($this->dir, new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00')), 5);

        $this->expectException(ToolException::class);
        $cap->increment('acc');
    }

    public function testGcRemovesOldCounters(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00'));
        $cap = new DailyCap($this->dir, $clock, 5);
        $cap->increment('acc');

        file_put_contents($this->dir . '/send-acc-20261012.count', '{"count":1}'); // 10 days ago: kept
        file_put_contents($this->dir . '/send-acc-20261011.count', '{"count":1}'); // 11 days ago: removed
        file_put_contents($this->dir . '/send-other-acc-20250101.count', '{"count":3}'); // hyphenated id, removed
        file_put_contents($this->dir . '/unrelated.txt', 'x'); // not a counter: never touched

        $cap->gc(10);

        $this->assertFileExists($this->dir . '/send-acc-20261022.count');
        $this->assertFileExists($this->dir . '/send-acc-20261012.count');
        $this->assertFileDoesNotExist($this->dir . '/send-acc-20261011.count');
        $this->assertFileDoesNotExist($this->dir . '/send-other-acc-20250101.count');
        $this->assertFileExists($this->dir . '/unrelated.txt');

        // Today's counter survives and still counts.
        $this->assertSame(2, $cap->increment('acc'));
    }

    public function testGcOnMissingDirectoryIsNoop(): void
    {
        $cap = new DailyCap($this->dir, new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00')), 5);
        $cap->gc(30);

        $this->assertDirectoryDoesNotExist($this->dir);
    }
}
