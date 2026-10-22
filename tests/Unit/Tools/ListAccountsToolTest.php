<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\ListAccountsTool;
use MailboxMcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class ListAccountsToolTest extends TestCase
{
    private function account(string $id, string $email, string $name, ?string $guidelinesFile): Account
    {
        return new Account(
            $id,
            new Identity($email, $name),
            [],
            [],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            $guidelinesFile,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
    }

    private function context(Account $account): ToolContext
    {
        $factory = new FakeSessionFactory();
        $session = $factory->open($account, []);

        return new ToolContext($account, $session, [], new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    public function testListAccountsMarksDefault(): void
    {
        $default = $this->account('default', 'default@example.com', 'Default', null);
        $other = $this->account('other', 'other@example.com', 'Other', '/guidelines/other.md');

        $resolver = new AccountResolver(['default' => $default, 'other' => $other]);
        $tool = new ListAccountsTool($resolver);

        $result = $tool->execute([], $this->context($default));

        $expected = implode("\n", [
            'accounts: 2',
            '- id: default (default)',
            '  email: default@example.com',
            '  name: Default',
            '  guidelines: no',
            '- id: other',
            '  email: other@example.com',
            '  name: Other',
            '  guidelines: yes',
        ]);

        $this->assertSame($expected, $result->text);
        $this->assertFalse($result->isError);
    }

    public function testListAccountsSingleAccountIsDefault(): void
    {
        $account = $this->account('solo', 'solo@example.com', 'Solo', null);
        $resolver = new AccountResolver(['solo' => $account]);
        $tool = new ListAccountsTool($resolver);

        $result = $tool->execute([], $this->context($account));

        $this->assertStringStartsWith("accounts: 1\n- id: solo (default)\n", $result->text);
    }

    public function testDefinitionHasNoProperties(): void
    {
        $resolver = new AccountResolver(['default' => $this->account('default', 'a@b.com', 'A', null)]);
        $tool = new ListAccountsTool($resolver);

        $definition = $tool->definition();

        $this->assertSame('list_accounts', $definition['name']);
        $this->assertSame('List accounts', $definition['title']);
        $this->assertSame(['type' => 'object', 'additionalProperties' => false], $definition['inputSchema']);
        $this->assertSame([
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ], $definition['annotations']);
    }
}
