<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Mail\Imap\Folder;
use MailboxMcp\Mail\MailSession;
use MailboxMcp\Tests\Support\FakeImapClient;
use MailboxMcp\Tests\Support\FakeSmtpClient;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailSessionTest extends TestCase
{
    /**
     * @param array<string, ?string> $folders
     */
    private function account(string $id, array $folders): Account
    {
        return new Account(
            $id,
            new Identity("{$id}@example.com", ucfirst($id)),
            [],
            [],
            $folders,
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
    }

    /**
     * @return array<string, ?string>
     */
    private function emptyFolders(): array
    {
        return ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null];
    }

    public function testSessionConnectsImapLazily(): void
    {
        $imap = new FakeImapClient();
        $smtp = new FakeSmtpClient();
        $account = $this->account('example', $this->emptyFolders());
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $this->assertSame([], $imap->calls);

        $session->imap();
        $session->imap();

        $this->assertSame([['connect']], $imap->calls);
    }

    public function testSessionSmtpLazy(): void
    {
        $imap = new FakeImapClient();
        $smtp = new FakeSmtpClient();
        $account = $this->account('example', $this->emptyFolders());
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $this->assertFalse($smtp->connected);
        $this->assertSame(0, $smtp->connectCalls);

        $session->smtp();
        $session->smtp();

        $this->assertTrue($smtp->connected);
        $this->assertSame(1, $smtp->connectCalls);
        $this->assertSame([], $imap->calls);
    }

    public function testFolderForRoleUsesOverride(): void
    {
        $imap = new FakeImapClient();
        $imap->folders = [
            new Folder('INBOX', '/', []),
            new Folder('My Drafts', '/', ['\\drafts']),
        ];
        $smtp = new FakeSmtpClient();
        $folders = ['drafts' => 'My Drafts', 'sent' => null, 'trash' => null, 'junk' => null];
        $account = $this->account('example', $folders);
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $drafts = $session->folderForRole('drafts');

        $this->assertSame('My Drafts', $drafts->name);
        $this->assertSame('drafts', $drafts->role);
    }

    public function testFolderForRoleWithoutOverrideOrInboxThrows(): void
    {
        $imap = new FakeImapClient();
        $imap->folders = [new Folder('INBOX', '/', [])];
        $smtp = new FakeSmtpClient();
        $account = $this->account('example', $this->emptyFolders());
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No sent folder detected; set accounts.example.folders.sent in the config');

        $session->folderForRole('sent');
    }

    public function testFolderByNameInboxCaseInsensitive(): void
    {
        $imap = new FakeImapClient();
        $imap->folders = [new Folder('INBOX', '/', [])];
        $smtp = new FakeSmtpClient();
        $account = $this->account('example', $this->emptyFolders());
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $folder = $session->folderByName('inbox');

        $this->assertSame('INBOX', $folder->name);
    }

    public function testFolderByNameRejectsUnknown(): void
    {
        $imap = new FakeImapClient();
        $imap->folders = [new Folder('INBOX', '/', [])];
        $smtp = new FakeSmtpClient();
        $account = $this->account('example', $this->emptyFolders());
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Unknown folder 'Nonexistent'");

        $session->folderByName('Nonexistent');
    }

    public function testFoldersUseFolderResolver(): void
    {
        $imap = new FakeImapClient();
        $imap->folders = [
            new Folder('INBOX', '/', ['\\inbox']),
            new Folder('Sent Messages', '/', ['\\sent']),
        ];
        $smtp = new FakeSmtpClient();
        $account = $this->account('example', $this->emptyFolders());
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $folders = $session->folders();

        $sent = $session->folderByName('Sent Messages');
        $this->assertSame('sent', $sent->role);
        $this->assertCount(2, $folders);
    }

    public function testCloseIsIdempotentAndSwallows(): void
    {
        $imap = new FakeImapClient();
        $imap->throwOn['close'] = new RuntimeException('boom');
        $smtp = new FakeSmtpClient();
        $account = $this->account('example', $this->emptyFolders());
        $session = new MailSession($account, $imap, $smtp, $account->folders);

        $session->imap();

        $session->close();
        $session->close();

        $this->assertSame([['connect'], ['close']], $imap->calls);
        $this->assertFalse($imap->closed);
    }
}
