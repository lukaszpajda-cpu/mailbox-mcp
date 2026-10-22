<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Audit;

use DateTimeImmutable;
use MailboxMcp\Audit\Logger;
use MailboxMcp\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mailbox-mcp-logger-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir) && !is_file($dir)) {
            return;
        }

        if (is_file($dir)) {
            unlink($dir);

            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->rmrf($dir . '/' . $entry);
        }

        rmdir($dir);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readLines(string $path): array
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $lines = array_values(array_filter(explode("\n", $contents), static fn (string $l): bool => '' !== $l));

        return array_map(
            static function (string $line): array {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);

                return $decoded;
            },
            $lines,
        );
    }

    public function testLogWritesJsonLineWithTimestamp(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false);

        $logger->log(['event' => 'auth_failed', 'ip' => '1.2.3.4']);

        $path = $this->tmpDir . '/2026-01-02.jsonl';
        $this->assertFileExists($path);

        $lines = $this->readLines($path);
        $this->assertCount(1, $lines);
        $this->assertSame('2026-01-02T03:04:05+00:00', $lines[0]['ts']);
        $this->assertSame('auth_failed', $lines[0]['event']);
        $this->assertSame('1.2.3.4', $lines[0]['ip']);
    }

    public function testLoggerHashesIp(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, true);

        $logger->log(['event' => 'auth_failed', 'ip' => '1.2.3.4']);

        $lines = $this->readLines($this->tmpDir . '/2026-01-02.jsonl');
        $this->assertSame(hash('sha256', '1.2.3.4'), $lines[0]['ip']);
    }

    public function testLogAppendsMultipleLinesToSameDateFile(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false);

        $logger->log(['event' => 'one']);
        $logger->log(['event' => 'two']);

        $lines = $this->readLines($this->tmpDir . '/2026-01-02.jsonl');
        $this->assertCount(2, $lines);
        $this->assertSame('one', $lines[0]['event']);
        $this->assertSame('two', $lines[1]['event']);
    }

    public function testLogCreatesDirectoryWithRestrictedMode(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false);

        $logger->log(['event' => 'one']);

        $this->assertDirectoryExists($this->tmpDir);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->tmpDir)), -4));
    }

    public function testDisabledLoggerIsNoop(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false, false);

        $logger->log(['event' => 'one']);

        $this->assertDirectoryDoesNotExist($this->tmpDir);
    }

    public function testLogNeverThrowsWhenDirectoryCannotBeCreated(): void
    {
        // Create a plain file where Logger expects to create a directory,
        // so mkdir() fails and is_dir() stays false.
        file_put_contents($this->tmpDir, 'not a directory');

        $clock = new FrozenClock(new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false);

        $logger->log(['event' => 'one']);

        // log() must not throw even when the directory cannot be created;
        // the pre-existing file at $this->tmpDir must also be untouched.
        $this->assertSame('not a directory', file_get_contents($this->tmpDir));
    }

    public function testLogDoesNotEscapeSlashes(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false);

        $logger->log(['event' => 'tool_call', 'tool' => 'a/b']);

        $contents = file_get_contents($this->tmpDir . '/2026-01-02.jsonl');
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('\\/', $contents);
    }

    public function testLoggerGcDeletesOldFiles(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-02-01T00:00:00+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false);

        mkdir($this->tmpDir, 0700, true);
        $oldPath = $this->tmpDir . '/2025-11-01.jsonl'; // ~92 days before clock date
        $recentPath = $this->tmpDir . '/2026-01-20.jsonl'; // 12 days before clock date
        file_put_contents($oldPath, '{"event":"old"}' . "\n");
        file_put_contents($recentPath, '{"event":"recent"}' . "\n");

        $logger->gc();

        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($recentPath);
    }

    public function testLoggerGcNeverThrowsOnGarbageFileNames(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-02-01T00:00:00+00:00'));
        $logger = new Logger($this->tmpDir, $clock, 30, false);

        mkdir($this->tmpDir, 0700, true);
        file_put_contents($this->tmpDir . '/not-a-date.jsonl', '{}');

        $logger->gc();

        $this->assertFileExists($this->tmpDir . '/not-a-date.jsonl');
    }
}
