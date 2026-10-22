<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Support;

use MailboxMcp\Support\JsonFile;
use PHPUnit\Framework\TestCase;

final class JsonFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mailbox-mcp-jsonfile-test-' . bin2hex(random_bytes(8));
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

    public function testReadReturnsEmptyArrayForMissingFile(): void
    {
        $this->assertSame([], JsonFile::read($this->tmpDir . '/missing.json'));
    }

    public function testReadReturnsEmptyArrayForInvalidJson(): void
    {
        mkdir($this->tmpDir, 0700, true);
        $path = $this->tmpDir . '/invalid.json';
        file_put_contents($path, 'not json {{{');

        $this->assertSame([], JsonFile::read($path));
    }

    public function testReadReturnsEmptyArrayForEmptyFile(): void
    {
        mkdir($this->tmpDir, 0700, true);
        $path = $this->tmpDir . '/empty.json';
        file_put_contents($path, '');

        $this->assertSame([], JsonFile::read($path));
    }

    public function testReadReturnsDecodedData(): void
    {
        mkdir($this->tmpDir, 0700, true);
        $path = $this->tmpDir . '/data.json';
        file_put_contents($path, json_encode(['a' => 1], JSON_THROW_ON_ERROR));

        $this->assertSame(['a' => 1], JsonFile::read($path));
    }

    public function testUpdateCreatesMissingParentDirectory(): void
    {
        $path = $this->tmpDir . '/file.json';

        $this->assertDirectoryDoesNotExist($this->tmpDir);

        JsonFile::update($path, static fn (array $data): array => ['x' => 1]);

        $this->assertDirectoryExists($this->tmpDir);
        $this->assertSame(['x' => 1], JsonFile::read($path));
    }

    public function testUpdatePassesCurrentDataToCallback(): void
    {
        $path = $this->tmpDir . '/file.json';
        JsonFile::update($path, static fn (array $data): array => ['count' => 1]);

        $seen = null;
        JsonFile::update($path, function (array $data) use (&$seen): array {
            $seen = $data;

            return $data;
        });

        $this->assertSame(['count' => 1], $seen);
    }

    public function testUpdateReturnsNewData(): void
    {
        $path = $this->tmpDir . '/file.json';

        $result = JsonFile::update($path, static fn (array $data): array => ['count' => 1]);

        $this->assertSame(['count' => 1], $result);
    }

    public function testJsonFileConcurrentUpdateKeepsCount(): void
    {
        $path = $this->tmpDir . '/counter.json';

        $increment = static function (array $data): array {
            $count = $data['count'] ?? 0;

            return ['count' => (is_int($count) ? $count : 0) + 1];
        };

        JsonFile::update($path, $increment);
        $result = JsonFile::update($path, $increment);

        $this->assertSame(2, $result['count']);

        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $this->assertSame(['count' => 2], json_decode($raw, true));
    }
}
