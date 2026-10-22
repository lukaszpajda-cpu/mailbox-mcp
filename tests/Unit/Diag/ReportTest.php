<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Diag;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Config\Validator;
use MailboxMcp\Diag\Report;
use MailboxMcp\Mail\MailSession;
use MailboxMcp\Mail\MailSessionFactoryInterface;
use MailboxMcp\Security\TokenGenerator;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mailbox-mcp-report-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->tmpDir);
    }

    public function testReportNeverContainsSecrets(): void
    {
        $token = TokenGenerator::generate();
        $config = self::buildConfig($this->tmpDir . '/var', [
            'a' => self::accountFixture('a'),
            'b' => self::accountFixture('b'),
        ], $token);

        $working = new FakeSessionFactory();
        $failing = new FakeSessionFactory();
        $failing->imap->throwOn['listFolders'] = new RuntimeException('boom: leaking nothing');

        $factory = new class ($working, $failing) implements MailSessionFactoryInterface {
            public function __construct(
                private readonly FakeSessionFactory $working,
                private readonly FakeSessionFactory $failing,
            ) {
            }

            public function open(Account $account, array $limits, ?float $deadlineNs = null): MailSession
            {
                return 'b' === $account->id
                    ? $this->failing->open($account, $limits)
                    : $this->working->open($account, $limits);
            }
        };

        $report = Report::run($config, $factory, true);

        self::assertSame('ok', $report['accounts']['a']['imap']);
        self::assertSame(RuntimeException::class, $report['accounts']['b']['imap']);

        $json = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('s3cret-pass', $json);
        self::assertStringNotContainsString('u-secret', $json);
        self::assertStringNotContainsString($token, $json);
        self::assertStringNotContainsString('boom', $json);
    }

    public function testReportFlagsVarInsideDocroot(): void
    {
        $docroot = $this->tmpDir . '/public';
        $varDir = $docroot . '/var';
        mkdir($varDir, 0700, true);

        $config = self::buildConfig($varDir, ['a' => self::accountFixture('a')], TokenGenerator::generate());

        $report = Report::run($config, new FakeSessionFactory(), false, $docroot);

        self::assertFalse($report['var_outside_docroot']);
    }

    public function testReportFlagsVarOutsideDocroot(): void
    {
        $docroot = $this->tmpDir . '/public';
        $varDir = $this->tmpDir . '/var';
        mkdir($docroot, 0700, true);
        mkdir($varDir, 0700, true);

        $config = self::buildConfig($varDir, ['a' => self::accountFixture('a')], TokenGenerator::generate());

        $report = Report::run($config, new FakeSessionFactory(), false, $docroot);

        self::assertTrue($report['var_outside_docroot']);
    }

    public function testConfigModeIsComputedFromPassedPath(): void
    {
        $config = self::buildConfig($this->tmpDir . '/var', ['a' => self::accountFixture('a')], TokenGenerator::generate());
        $configFile = $this->tmpDir . '/config.php';
        file_put_contents($configFile, '<?php return [];');

        chmod($configFile, 0600);
        $report = Report::run($config, new FakeSessionFactory(), false, null, $configFile);

        if ('Windows' === PHP_OS_FAMILY) {
            self::assertNull($report['config_mode_ok']);

            return;
        }

        self::assertTrue($report['config_mode_ok']);

        chmod($configFile, 0644);
        $report = Report::run($config, new FakeSessionFactory(), false, null, $configFile);
        self::assertFalse($report['config_mode_ok']);

        $docroot = $this->tmpDir . '/public';
        mkdir($docroot, 0700, true);
        $report = Report::run($config, new FakeSessionFactory(), false, $docroot, $configFile);
        self::assertTrue($report['config_outside_docroot']);
    }

    public function testReportIncludesOpenBasedir(): void
    {
        $config = self::buildConfig($this->tmpDir . '/var', ['a' => self::accountFixture('a')], TokenGenerator::generate());

        $report = Report::run($config, new FakeSessionFactory(), false);

        self::assertArrayHasKey('open_basedir', $report);
        $expected = ini_get('open_basedir');
        self::assertSame(is_string($expected) && '' !== $expected ? $expected : null, $report['open_basedir']);
    }

    public function testExtensionsAreBooleans(): void
    {
        $config = self::buildConfig($this->tmpDir . '/var', ['a' => self::accountFixture('a')], TokenGenerator::generate());

        $report = Report::run($config, new FakeSessionFactory(), false);

        foreach (['json', 'openssl', 'mbstring', 'iconv', 'intl'] as $extension) {
            self::assertIsBool($report['extensions'][$extension]);
        }
    }

    public function testExtensionsOkIgnoresOptional(): void
    {
        $allPresent = Report::extensionsSummary([
            'json' => true,
            'openssl' => true,
            'mbstring' => true,
            'iconv' => true,
            'intl' => true,
        ]);

        self::assertTrue($allPresent['extensions_ok']);
        self::assertSame([], $allPresent['optional_extensions_missing']);

        $missingOptionalOnly = Report::extensionsSummary([
            'json' => true,
            'openssl' => true,
            'mbstring' => true,
            'iconv' => false,
            'intl' => false,
        ]);

        self::assertTrue($missingOptionalOnly['extensions_ok']);
        self::assertSame(['iconv', 'intl'], $missingOptionalOnly['optional_extensions_missing']);

        $missingRequired = Report::extensionsSummary([
            'json' => true,
            'openssl' => false,
            'mbstring' => true,
            'iconv' => true,
            'intl' => true,
        ]);

        self::assertFalse($missingRequired['extensions_ok']);
        self::assertSame([], $missingRequired['optional_extensions_missing']);
    }

    public function testNoMailProbeOmitsImapAndSmtpKeys(): void
    {
        $config = self::buildConfig($this->tmpDir . '/var', ['a' => self::accountFixture('a')], TokenGenerator::generate());

        $report = Report::run($config, new FakeSessionFactory(), false);

        self::assertArrayNotHasKey('imap', $report['accounts']['a']);
        self::assertArrayNotHasKey('smtp', $report['accounts']['a']);
        self::assertSame('a@example.com', $report['accounts']['a']['email']);
    }

    /**
     * @param array<string, mixed> $accounts
     *
     * @return array<string, mixed>
     */
    private static function buildConfig(string $varDir, array $accounts, string $token): array
    {
        return Validator::validate([
            'auth' => ['token' => $token],
            'paths' => ['var' => $varDir],
            'accounts' => $accounts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function accountFixture(string $id): array
    {
        return [
            'email' => "{$id}@example.com",
            'name' => ucfirst($id),
            'imap' => [
                'host' => 'imap.example.com',
                'port' => 993,
                'encryption' => 'tls',
                'username' => 'u-secret',
                'password' => 's3cret-pass',
            ],
            'smtp' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'starttls',
                'username' => 'u-secret',
                'password' => 's3cret-pass',
            ],
        ];
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
