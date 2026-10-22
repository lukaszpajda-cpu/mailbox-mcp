<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Accounts;

use MailboxMcp\Accounts\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validCfg(): array
    {
        return [
            'email' => 'contact@example.com',
            'name' => 'Example Co',
            'imap' => [
                'host' => 'mail.example.com',
                'port' => 993,
                'encryption' => 'tls',
                'username' => 'contact@example.com',
                'password' => 'secret',
                'verify_peer' => true,
            ],
            'smtp' => [
                'host' => 'mail.example.com',
                'port' => 587,
                'encryption' => 'starttls',
                'username' => 'contact@example.com',
                'password' => 'secret',
                'verify_peer' => true,
            ],
            'folders' => ['drafts' => 'Drafts', 'sent' => null, 'trash' => null, 'junk' => null],
            'signature' => "Best regards,\nExample Co",
            'guidelines_file' => '/etc/mailbox-mcp/guidelines/example.md',
            'processed_keyword' => '$ClaudeSeen',
            'send' => ['allowed_domains' => ['example.com'], 'denied_domains' => ['spam.example']],
        ];
    }

    public function testAccountFromConfigKeepsFields(): void
    {
        $cfg = $this->validCfg();
        $account = Account::fromConfig('example', $cfg);

        $this->assertSame('example', $account->id);
        $this->assertSame('contact@example.com', $account->identity->email);
        $this->assertSame('Example Co', $account->identity->name);
        $this->assertSame($cfg['imap'], $account->imap);
        $this->assertSame($cfg['smtp'], $account->smtp);
        $this->assertSame(
            ['drafts' => 'Drafts', 'sent' => null, 'trash' => null, 'junk' => null],
            $account->folders,
        );
        $this->assertSame("Best regards,\nExample Co", $account->signature);
        $this->assertSame('/etc/mailbox-mcp/guidelines/example.md', $account->guidelinesFile);
        $this->assertSame('$ClaudeSeen', $account->processedKeyword);
        $this->assertSame(
            ['allowed_domains' => ['example.com'], 'denied_domains' => ['spam.example']],
            $account->send,
        );
    }

    public function testFromConfigNormalisesMissingFoldersAndSendToNull(): void
    {
        $cfg = $this->validCfg();
        $cfg['folders'] = ['drafts' => 'Drafts'];
        $cfg['send'] = [];
        $cfg['guidelines_file'] = null;

        $account = Account::fromConfig('example', $cfg);

        $this->assertSame(
            ['drafts' => 'Drafts', 'sent' => null, 'trash' => null, 'junk' => null],
            $account->folders,
        );
        $this->assertSame(
            ['allowed_domains' => [], 'denied_domains' => []],
            $account->send,
        );
        $this->assertNull($account->guidelinesFile);
    }

    public function testIdentityHeaderIsPlainForSimpleAsciiName(): void
    {
        $account = Account::fromConfig('example', $this->validCfg());

        $this->assertSame('Example Co <contact@example.com>', $account->identity->header());
    }

    public function testIdentityHeaderQuotesAndEscapesSpecialName(): void
    {
        $cfg = $this->validCfg();
        $cfg['name'] = 'Doe, "Johnny" \\Smith';

        $account = Account::fromConfig('example', $cfg);

        $this->assertSame(
            '"Doe, \\"Johnny\\" \\\\Smith" <contact@example.com>',
            $account->identity->header(),
        );
    }

    public function testIdentityHeaderEncodesNonAsciiName(): void
    {
        $cfg = $this->validCfg();
        $cfg['name'] = 'Łukasz Kowalski';

        $account = Account::fromConfig('example', $cfg);

        $this->assertSame(
            '=?UTF-8?B?' . base64_encode('Łukasz Kowalski') . '?= <contact@example.com>',
            $account->identity->header(),
        );
    }

    public function testIdentityDomain(): void
    {
        $account = Account::fromConfig('example', $this->validCfg());

        $this->assertSame('example.com', $account->identity->domain());
    }
}
