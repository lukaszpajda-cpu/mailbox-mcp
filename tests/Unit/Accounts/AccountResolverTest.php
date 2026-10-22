<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Accounts;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\TestCase;

final class AccountResolverTest extends TestCase
{
    private function account(string $id): Account
    {
        return new Account(
            $id,
            new Identity("{$id}@example.com", ucfirst($id)),
            [],
            [],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
    }

    public function testDefaultIsFirst(): void
    {
        $resolver = new AccountResolver([
            'alpha' => $this->account('alpha'),
            'beta' => $this->account('beta'),
        ]);

        $this->assertSame('alpha', $resolver->default()->id);
        $this->assertSame('alpha', $resolver->resolve(null)->id);
    }

    public function testResolveKnownId(): void
    {
        $resolver = new AccountResolver([
            'alpha' => $this->account('alpha'),
            'beta' => $this->account('beta'),
        ]);

        $this->assertSame('beta', $resolver->resolve('beta')->id);
    }

    public function testResolveUnknownListsIds(): void
    {
        $resolver = new AccountResolver([
            'alpha' => $this->account('alpha'),
            'beta' => $this->account('beta'),
        ]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Unknown account 'gamma'. Valid accounts: alpha, beta");

        $resolver->resolve('gamma');
    }

    public function testAllReturnsEveryAccount(): void
    {
        $accounts = ['alpha' => $this->account('alpha'), 'beta' => $this->account('beta')];
        $resolver = new AccountResolver($accounts);

        $this->assertSame($accounts, $resolver->all());
    }

    public function testFromConfigBuildsAccountsKeepingOrder(): void
    {
        $accountCfg = [
            'email' => 'alpha@example.com',
            'name' => 'Alpha',
            'imap' => [],
            'smtp' => [],
            'folders' => [],
            'signature' => '',
            'guidelines_file' => null,
            'processed_keyword' => '$ClaudeSeen',
            'send' => [],
        ];

        $config = [
            'accounts' => [
                'alpha' => $accountCfg,
                'beta' => ['email' => 'beta@example.com', 'name' => 'Beta'] + $accountCfg,
            ],
        ];

        $resolver = AccountResolver::fromConfig($config);

        $this->assertSame('alpha', $resolver->default()->id);
        $this->assertSame(['alpha', 'beta'], array_keys($resolver->all()));
        $this->assertSame('beta@example.com', $resolver->resolve('beta')->identity->email);
    }
}
