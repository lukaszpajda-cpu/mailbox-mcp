<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Mail\Imap\Client as ImapClient;
use MailboxMcp\Mail\MailSessionFactory;
use MailboxMcp\Mail\Smtp\Client as SmtpClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MailSessionFactoryTest extends TestCase
{
    public function testOpenBuildsRealClientsWithDistinctConfigAndNoEagerConnect(): void
    {
        $account = new Account(
            'example',
            new Identity('example@example.com', 'Example'),
            [
                'host' => '10.0.0.1',
                'port' => 143,
                'encryption' => 'tls',
                'username' => 'imapuser',
                'password' => 'imappass',
                'verify_peer' => true,
            ],
            [
                'host' => '10.0.0.2',
                'port' => 587,
                'encryption' => 'starttls',
                'username' => 'smtpuser',
                'password' => 'smtppass',
                'verify_peer' => false,
            ],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );

        $factory = new MailSessionFactory();
        $session = $factory->open($account, ['imap_timeout' => 7, 'smtp_timeout' => 9]);

        $imap = (new ReflectionProperty($session, 'imap'))->getValue($session);
        $smtp = (new ReflectionProperty($session, 'smtp'))->getValue($session);

        $this->assertInstanceOf(ImapClient::class, $imap);
        $this->assertInstanceOf(SmtpClient::class, $smtp);

        $this->assertSame([
            'host' => '10.0.0.1',
            'port' => 143,
            'encryption' => 'tls',
            'username' => 'imapuser',
            'password' => 'imappass',
            'timeoutSeconds' => 7,
            'verifyPeer' => true,
        ], self::clientFields($imap));
        $this->assertNull(self::property($imap, 'socket'));

        $this->assertSame([
            'host' => '10.0.0.2',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => 'smtpuser',
            'password' => 'smtppass',
            'timeoutSeconds' => 9,
            'verifyPeer' => false,
        ], self::clientFields($smtp));
        $this->assertNull(self::property($smtp, 'socket'));
    }

    public function testOpenAppliesDefaultTimeoutsWhenLimitsMissing(): void
    {
        $account = new Account(
            'example',
            new Identity('example@example.com', 'Example'),
            [
                'host' => '127.0.0.1',
                'port' => 143,
                'encryption' => 'none',
                'username' => 'user',
                'password' => 'secret',
                'verify_peer' => false,
            ],
            [
                'host' => '127.0.0.1',
                'port' => 587,
                'encryption' => 'none',
                'username' => 'user',
                'password' => 'secret',
                'verify_peer' => false,
            ],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );

        $factory = new MailSessionFactory();
        $session = $factory->open($account, []);

        $imap = (new ReflectionProperty($session, 'imap'))->getValue($session);
        $smtp = (new ReflectionProperty($session, 'smtp'))->getValue($session);

        $this->assertInstanceOf(ImapClient::class, $imap);
        $this->assertInstanceOf(SmtpClient::class, $smtp);

        $this->assertSame(15, self::property($imap, 'timeoutSeconds'));
        $this->assertNull(self::property($imap, 'socket'));

        $this->assertSame(20, self::property($smtp, 'timeoutSeconds'));
        $this->assertNull(self::property($smtp, 'socket'));
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: string, timeoutSeconds: int, verifyPeer: bool}
     */
    private static function clientFields(object $client): array
    {
        return [
            'host' => self::property($client, 'host'),
            'port' => self::property($client, 'port'),
            'encryption' => self::property($client, 'encryption'),
            'username' => self::property($client, 'username'),
            'password' => self::property($client, 'password'),
            'timeoutSeconds' => self::property($client, 'timeoutSeconds'),
            'verifyPeer' => self::property($client, 'verifyPeer'),
        ];
    }

    private static function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    public function testOpenPassesDeadlineToBothClients(): void
    {
        $account = new Account(
            'example',
            new Identity('example@example.com', 'Example'),
            ['host' => '127.0.0.1', 'port' => 143, 'encryption' => 'none', 'username' => 'u', 'password' => 'p', 'verify_peer' => false],
            ['host' => '127.0.0.1', 'port' => 587, 'encryption' => 'none', 'username' => 'u', 'password' => 'p', 'verify_peer' => false],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
        $factory = new MailSessionFactory();

        $session = $factory->open($account, [], 123456789.0);
        $imap = (new ReflectionProperty($session, 'imap'))->getValue($session);
        $smtp = (new ReflectionProperty($session, 'smtp'))->getValue($session);
        $this->assertIsObject($imap);
        $this->assertIsObject($smtp);
        $this->assertSame(123456789.0, self::property($imap, 'deadlineNs'));
        $this->assertSame(123456789.0, self::property($smtp, 'deadlineNs'));

        $session = $factory->open($account, []);
        $imap = (new ReflectionProperty($session, 'imap'))->getValue($session);
        $this->assertIsObject($imap);
        $this->assertNull(self::property($imap, 'deadlineNs'));
    }
}
