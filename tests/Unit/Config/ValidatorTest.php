<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Config;

use MailboxMcp\Config\ConfigException;
use MailboxMcp\Config\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../Fixtures/config/valid.php';

        return $config;
    }

    public function testDefaultsAppliedForMissingOptionalKeys(): void
    {
        $result = Validator::validate($this->validConfig());

        $this->assertSame(50, $result['limits']['max_results']);
        $this->assertSame(60, $result['security']['rate_limit_per_minute']);
    }

    public function testShortTokenRejected(): void
    {
        $config = $this->validConfig();
        $config['auth']['token'] = str_repeat('A', 42);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('auth.token: must be at least 43 characters of [A-Za-z0-9_-]');

        Validator::validate($config);
    }

    public function testPlaintextImapOnlyOnLocalhost(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['imap']['encryption'] = 'none';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.imap.encryption');

        Validator::validate($config);
    }

    public function testPlaintextImapAllowedOnLocalhost(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['imap']['host'] = '127.0.0.1';
        $config['accounts']['example']['imap']['encryption'] = 'none';

        $result = Validator::validate($config);

        $this->assertSame('none', $result['accounts']['example']['imap']['encryption']);
    }

    public function testUnknownEncryptionRejected(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['smtp']['encryption'] = 'ssl';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.smtp.encryption');

        Validator::validate($config);
    }

    public function testFirstAccountIsFirstKey(): void
    {
        $config = $this->validConfig();
        $account = $config['accounts']['example'];
        unset($config['accounts']['example']);

        // Insert in a deliberately non-alphabetical order so an accidental
        // ksort/reorder in the validator would be caught.
        $config['accounts'] = [
            'zzz-account' => $account,
            'aaa-account' => $account,
        ];
        $config['accounts']['zzz-account']['email'] = 'zzz@example.com';
        $config['accounts']['aaa-account']['email'] = 'aaa@example.com';

        $result = Validator::validate($config);

        $this->assertSame('zzz-account', array_key_first($result['accounts']));
        $this->assertSame(['zzz-account', 'aaa-account'], array_keys($result['accounts']));
    }

    public function testUnknownTopLevelKeyRejected(): void
    {
        $config = $this->validConfig();
        $config['bogus'] = [];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown key: bogus');

        Validator::validate($config);
    }

    public function testAccountsMustBeNonEmpty(): void
    {
        $config = $this->validConfig();
        $config['accounts'] = [];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts');

        Validator::validate($config);
    }

    public function testInvalidAccountIdRejected(): void
    {
        $config = $this->validConfig();
        $account = $config['accounts']['example'];
        unset($config['accounts']['example']);
        $config['accounts']['Not Valid'] = $account;

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.Not Valid');

        Validator::validate($config);
    }

    public function testInvalidEmailRejected(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['email'] = 'not-an-email';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.email');

        Validator::validate($config);
    }

    public function testLocalhostEmailAllowedForTestMailboxes(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['email'] = 'test@localhost';

        $result = Validator::validate($config);

        $this->assertSame('test@localhost', $result['accounts']['example']['email']);
    }

    public function testOtherSingleLabelDomainsStillRejected(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['email'] = 'test@notlocalhost';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.email');

        Validator::validate($config);
    }

    public function testAccountFieldDefaults(): void
    {
        $result = Validator::validate($this->validConfig());
        $account = $result['accounts']['example'];

        $this->assertSame('', $account['signature']);
        $this->assertNull($account['guidelines_file']);
        $this->assertSame('$ClaudeSeen', $account['processed_keyword']);
        $this->assertSame(
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            $account['folders'],
        );
        $this->assertSame(
            ['allowed_domains' => [], 'denied_domains' => []],
            $account['send'],
        );
        $this->assertTrue($account['imap']['verify_peer']);
        $this->assertTrue($account['smtp']['verify_peer']);
    }

    public function testUnknownFolderKeyRejected(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['folders'] = ['archive' => 'Archive'];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.folders');

        Validator::validate($config);
    }

    public function testInvalidProcessedKeywordRejected(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['processed_keyword'] = 'has space';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.processed_keyword');

        Validator::validate($config);
    }

    public function testInvalidImapPortRejected(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['imap']['port'] = 70000;

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.imap.port');

        Validator::validate($config);
    }

    public function testNonPositiveLimitRejected(): void
    {
        $config = $this->validConfig();
        $config['limits'] = ['max_results' => 0];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('limits.max_results');

        Validator::validate($config);
    }

    public function testMissingPathsVarRejected(): void
    {
        $config = $this->validConfig();
        unset($config['paths']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('paths.var');

        Validator::validate($config);
    }

    public function testInvalidAllowedOriginRejected(): void
    {
        $config = $this->validConfig();
        $config['security'] = ['allowed_origins' => ['not-a-url']];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('security.allowed_origins');

        Validator::validate($config);
    }

    public function testNonLowercaseSendDomainRejected(): void
    {
        $config = $this->validConfig();
        $config['accounts']['example']['send'] = ['allowed_domains' => ['Example.com']];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('accounts.example.send.allowed_domains');

        Validator::validate($config);
    }
}
