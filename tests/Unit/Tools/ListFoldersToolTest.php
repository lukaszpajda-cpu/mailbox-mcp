<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Mail\Imap\Folder;
use MailboxMcp\Mail\Imap\ImapException;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\ListFoldersTool;
use MailboxMcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class ListFoldersToolTest extends TestCase
{
    private function account(): Account
    {
        return new Account(
            'default',
            new Identity('default@example.com', 'Default'),
            [],
            [],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
    }

    private function context(Account $account, FakeSessionFactory $factory): ToolContext
    {
        $session = $factory->open($account, []);

        return new ToolContext($account, $session, [], new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    public function testListFoldersRolesAndCounts(): void
    {
        $factory = new FakeSessionFactory();
        $factory->imap->folders = [
            new Folder('INBOX', '/', ['\\inbox']),
            new Folder('Drafts', '/', ['\\drafts']),
            new Folder('Random', '/', []),
        ];
        $factory->imap->statuses = [
            'INBOX' => ['messages' => 10, 'unseen' => 2],
            'Drafts' => ['messages' => 1, 'unseen' => 0],
            'Random' => ['messages' => 0, 'unseen' => 0],
        ];

        $tool = new ListFoldersTool();
        $result = $tool->execute([], $this->context($this->account(), $factory));

        $expected = implode("\n", [
            'account: default',
            'folders: 3',
            '- INBOX  role=inbox  total=10  unseen=2',
            '- Drafts  role=drafts  total=1  unseen=0',
            '- Random  role=none  total=0  unseen=0',
        ]);

        $this->assertSame($expected, $result->text);
        $this->assertFalse($result->isError);
    }

    public function testListFoldersStatusFailureShowsQuestionMarks(): void
    {
        $factory = new FakeSessionFactory();
        $factory->imap->folders = [new Folder('INBOX', '/', ['\\inbox'])];
        $factory->imap->throwOn['status'] = new ImapException('NO folder unavailable');

        $tool = new ListFoldersTool();
        $result = $tool->execute([], $this->context($this->account(), $factory));

        $expected = implode("\n", [
            'account: default',
            'folders: 1',
            '- INBOX  role=inbox  total=?  unseen=?',
        ]);

        $this->assertSame($expected, $result->text);
    }

    public function testDefinitionSchemaAndAnnotations(): void
    {
        $tool = new ListFoldersTool();
        $definition = $tool->definition();

        $this->assertSame('list_folders', $definition['name']);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['account' => ['type' => 'string']],
            'required' => [],
            'additionalProperties' => false,
        ], $definition['inputSchema']);
        $this->assertSame([
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ], $definition['annotations']);
    }
}
