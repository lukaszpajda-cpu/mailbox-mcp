<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Imap;

use MailboxMcp\Mail\Imap\Folder;
use MailboxMcp\Mail\Imap\FolderResolver;
use PHPUnit\Framework\TestCase;

final class FolderResolverTest extends TestCase
{
    /**
     * @return array<string, ?string>
     */
    private function noOverrides(): array
    {
        return ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null];
    }

    public function testSpecialUseWins(): void
    {
        // Both folders would satisfy role "sent" (one by SPECIAL-USE
        // attribute, the other by name heuristic); SPECIAL-USE outranks
        // the name heuristic, so only the attribute-carrying folder gets
        // the role.
        $specialUse = new Folder('Wysłane', '/', ['\\sent']);
        $heuristic = new Folder('Sent Items', '/', []);

        (new FolderResolver($this->noOverrides()))->assignRoles([$specialUse, $heuristic]);

        $this->assertSame('sent', $specialUse->role);
        $this->assertNull($heuristic->role);
    }

    public function testOverrideWinsOverSpecialUse(): void
    {
        $overridden = new Folder('Custom Sent', '/', []);
        $specialUse = new Folder('Wysłane', '/', ['\\sent']);

        $overrides = ['drafts' => null, 'sent' => 'Custom Sent', 'trash' => null, 'junk' => null];
        (new FolderResolver($overrides))->assignRoles([$overridden, $specialUse]);

        $this->assertSame('sent', $overridden->role);
        $this->assertNull($specialUse->role);
    }

    public function testHeuristicSentMessagesWithInboxPrefix(): void
    {
        $inbox = new Folder('INBOX', '.', ['\\inbox']);
        $sent = new Folder('INBOX.Sent Messages', '.', []);

        (new FolderResolver($this->noOverrides()))->assignRoles([$inbox, $sent]);

        $this->assertSame('inbox', $inbox->role);
        $this->assertSame('sent', $sent->role);
    }

    public function testOnlyOneFolderPerRole(): void
    {
        $folders = [
            new Folder('INBOX', '/', ['\\inbox']),
            new Folder('Drafts', '/', ['\\drafts']),
            new Folder('Sent', '/', ['\\sent']),
            new Folder('Trash', '/', ['\\trash']),
            new Folder('Junk', '/', ['\\junk']),
            new Folder('Archive', '/', ['\\archive']),
            new Folder('Random Stuff', '/', []),
        ];

        (new FolderResolver($this->noOverrides()))->assignRoles($folders);

        $roles = array_values(array_filter(array_map(static fn (Folder $f): ?string => $f->role, $folders)));

        $this->assertSame(['inbox', 'drafts', 'sent', 'trash', 'junk', 'archive'], $roles);
        $this->assertSame(6, count(array_unique($roles)));
    }

    public function testFirstFolderWinsWithinPriority(): void
    {
        $first = new Folder('Junk', '/', ['\\junk']);
        $second = new Folder('Spam Duplicate', '/', ['\\junk']);

        (new FolderResolver($this->noOverrides()))->assignRoles([$first, $second]);

        $this->assertSame('junk', $first->role);
        $this->assertNull($second->role);
    }

    public function testNoselectNeverGetsRole(): void
    {
        $noselectInbox = new Folder('INBOX', '/', ['\\noselect', '\\inbox']);
        $noselectOverridden = new Folder('Archive Folder', '/', ['\\noselect']);

        $overrides = ['drafts' => 'Archive Folder', 'sent' => null, 'trash' => null, 'junk' => null];
        (new FolderResolver($overrides))->assignRoles([$noselectInbox, $noselectOverridden]);

        $this->assertNull($noselectInbox->role);
        $this->assertNull($noselectOverridden->role);
    }

    public function testOverrideMissingFolderIgnored(): void
    {
        $inbox = new Folder('INBOX', '/', ['\\inbox']);

        $overrides = ['drafts' => 'Nonexistent Folder', 'sent' => null, 'trash' => null, 'junk' => null];
        (new FolderResolver($overrides))->assignRoles([$inbox]);

        $this->assertSame('inbox', $inbox->role);
    }
}
