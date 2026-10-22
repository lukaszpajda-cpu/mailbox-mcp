<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Config;

use MailboxMcp\Config\ConfigException;
use MailboxMcp\Config\Loader;
use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase
{
    private const EXAMPLE_TOKEN_PLACEHOLDER = 'REPLACE_WITH_43_PLUS_CHAR_BASE64URL';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mailbox-mcp-loader-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);
    }

    public function testLoaderRejectsMissingFile(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('config file not found: ' . $this->tmpDir . '/missing.php');

        Loader::load($this->tmpDir . '/missing.php');
    }

    public function testLoaderRejectsNonArrayFile(): void
    {
        $path = $this->tmpDir . '/not-array.php';
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\nreturn 'nope';\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('config file must return an array');

        Loader::load($path);
    }

    public function testLoaderValidatesFixture(): void
    {
        $result = Loader::load(__DIR__ . '/../../Fixtures/config/valid.php');

        $this->assertSame(50, $result['limits']['max_results']);
        $this->assertArrayHasKey('example', $result['accounts']);
    }

    public function testExampleConfigValidatesAfterTokenReplaced(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../config/config.example.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(self::EXAMPLE_TOKEN_PLACEHOLDER, $source);

        $replaced = str_replace(
            self::EXAMPLE_TOKEN_PLACEHOLDER,
            str_repeat('A', 43),
            $source,
        );

        $path = $this->tmpDir . '/config.example.php';
        file_put_contents($path, $replaced);

        $result = Loader::load($path);

        $this->assertSame('contact@example.com', $result['accounts']['example']['email']);
        $this->assertSame('$ClaudeSeen', $result['accounts']['example']['processed_keyword']);
    }
}
