<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Mail\Imap\Folder;
use MailboxMcp\Mail\Mime\Parser;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\ProtocolException;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\CreateDraftTool;
use MailboxMcp\Tools\Registry;
use MailboxMcp\Tools\ToolContext;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\TestCase;

final class CreateDraftToolTest extends TestCase
{
    private function account(string $signature = ''): Account
    {
        return new Account(
            'default',
            new Identity('me@example.com', 'Me'),
            [],
            [],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            $signature,
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function context(Account $account, FakeSessionFactory $factory, array $limits = ['max_recipients' => 10]): ToolContext
    {
        $session = $factory->open($account, $limits);

        return new ToolContext($account, $session, $limits, new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+02:00')));
    }

    private function withFolders(FakeSessionFactory $factory): void
    {
        $factory->imap->folders = [
            new Folder('INBOX', '/', ['\\inbox']),
            new Folder('Drafts', '/', ['\\drafts']),
        ];
    }

    public function testCreateDraftAppendsWithDraftFlag(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);

        $tool = new CreateDraftTool();
        $result = $tool->execute(
            ['to' => ['a@b.com'], 'subject' => 'Hi', 'body' => 'Hello', 'html' => false, 'cc' => []],
            $this->context($this->account(), $factory),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $factory->imap->appended);
        [$folder, $raw, $flags] = $factory->imap->appended[0];

        $this->assertSame('Drafts', $folder);
        $this->assertContains('\\Draft', $flags);
        $this->assertContains('\\Seen', $flags);
        $this->assertStringContainsString('To: a@b.com', $raw);
    }

    public function testCreateDraftTooManyRecipients(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);

        $tool = new CreateDraftTool();

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Too many recipients (11 > 10)');

        $to = [];

        for ($i = 0; $i < 10; $i++) {
            $to[] = "user{$i}@example.com";
        }

        $tool->execute(
            ['to' => $to, 'cc' => ['extra@example.com'], 'subject' => 'Hi', 'body' => 'Hello'],
            $this->context($this->account(), $factory),
        );
    }

    public function testCreateDraftRejectsInvalidAddress(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);

        $tool = new CreateDraftTool();

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Invalid address: not-an-address');

        $tool->execute(
            ['to' => ['not-an-address'], 'subject' => 'Hi', 'body' => 'Hello'],
            $this->context($this->account(), $factory),
        );
    }

    public function testCreateDraftReplyFetchesHeaders(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);

        $factory->imap->headers[7] = "Message-ID: <orig@x.com>\r\n"
            . "References: <ref1@x.com>\r\n"
            . 'Subject: Hello';

        $tool = new CreateDraftTool();
        $result = $tool->execute(
            ['to' => ['a@b.com'], 'subject' => 'Hello', 'body' => 'Reply body', 'in_reply_to_uid' => 7],
            $this->context($this->account(), $factory),
        );

        $this->assertFalse($result->isError);

        [, $raw] = $factory->imap->appended[0];
        $this->assertStringContainsString('In-Reply-To: <orig@x.com>', $raw);
        $this->assertStringContainsString('References: <ref1@x.com> <orig@x.com>', $raw);
        $this->assertStringContainsString('Subject: Re: Hello', $raw);

        $this->assertContains(['select', 'INBOX', true], $factory->imap->calls);
        $this->assertContains(['fetchHeaders', 7], $factory->imap->calls);
    }

    public function testReplyToMissingMessageIsToolError(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);
        // $factory->imap->headers has no entry for uid 404: fetchHeaders()
        // returns '' (see FakeImapClient), exactly like a real server's
        // FETCH response for a uid that no longer exists.

        $tool = new CreateDraftTool();

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Message uid 404 not found in folder 'INBOX'");

        try {
            $tool->execute(
                ['to' => ['a@b.com'], 'subject' => 'Hello', 'body' => 'Reply body', 'in_reply_to_uid' => 404],
                $this->context($this->account(), $factory),
            );
        } finally {
            $this->assertSame([], $factory->imap->appended, 'must not store a draft for an unresolved reply target');
        }
    }

    public function testCreateDraftReplyUsesInReplyToFolder(): void
    {
        $factory = new FakeSessionFactory();
        $factory->imap->folders = [
            new Folder('INBOX', '/', ['\\inbox']),
            new Folder('Drafts', '/', ['\\drafts']),
            new Folder('Archive', '/', []),
        ];
        $factory->imap->headers[9] = "Message-ID: <archived@x.com>\r\n" . 'Subject: Archived thread';

        $tool = new CreateDraftTool();
        $tool->execute(
            ['to' => ['a@b.com'], 'subject' => 'Hello', 'body' => 'Body', 'in_reply_to_uid' => 9, 'in_reply_to_folder' => 'Archive'],
            $this->context($this->account(), $factory),
        );

        $this->assertContains(['select', 'Archive', true], $factory->imap->calls);
        $this->assertNotContains(['select', 'INBOX', true], $factory->imap->calls);
    }

    public function testCreateDraftUidFallbackSearch(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);
        $factory->imap->appendUid = null;
        // The APPENDUID response is missing; the fallback search result
        // carries several candidate uids on purpose, to also pin down
        // that the HIGHEST one is picked.
        $factory->imap->searchResultsFallback = [10, 42, 7];

        $tool = new CreateDraftTool();
        $result = $tool->execute(
            ['to' => ['a@b.com'], 'subject' => 'Hi', 'body' => 'Hello'],
            $this->context($this->account(), $factory),
        );

        $this->assertFalse($result->isError);

        $this->assertContains(['select', 'Drafts', true], $factory->imap->calls);

        $searchCalls = array_values(array_filter(
            $factory->imap->calls,
            static fn (array $call): bool => 'search' === $call[0],
        ));
        $this->assertCount(1, $searchCalls);
        $this->assertStringStartsWith('HEADER Message-ID "<', $searchCalls[0][1]);
        $this->assertNull($searchCalls[0][2]);

        $this->assertStringContainsString('draft_uid: 42', $result->text);
    }

    public function testCreateDraftUidFallbackSearchFailsLoudly(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);
        $factory->imap->appendUid = null;
        // No searchResults configured: search() returns [].

        $tool = new CreateDraftTool();

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Draft stored but its uid could not be determined');

        $tool->execute(
            ['to' => ['a@b.com'], 'subject' => 'Hi', 'body' => 'Hello'],
            $this->context($this->account(), $factory),
        );
    }

    public function testCreateDraftOutputLayout(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);
        $factory->imap->appendUid = 55;

        $tool = new CreateDraftTool();
        $result = $tool->execute(
            ['to' => ['a@b.com'], 'cc' => ['c@d.com'], 'subject' => 'Hi there', 'body' => 'Hello body'],
            $this->context($this->account(), $factory),
        );

        [, $raw] = $factory->imap->appended[0];
        $messageId = null;

        foreach (explode("\r\n", $raw) as $line) {
            if (str_starts_with($line, 'Message-ID:')) {
                $messageId = trim(substr($line, strlen('Message-ID:')));
            }
        }

        $this->assertNotNull($messageId);

        $expected = implode("\n", [
            'draft_uid: 55',
            'folder: Drafts',
            "message_id: {$messageId}",
            '<email_content untrusted="true" account="default" folder="Drafts" uid="55">',
            'To: a@b.com',
            'Cc: c@d.com',
            'Subject: Hi there',
            '---',
            'Hello body',
            '</email_content>',
        ]);

        $this->assertSame($expected, $result->text);
    }

    public function testSignatureAppendedWithSeparator(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);

        $tool = new CreateDraftTool();
        $result = $tool->execute(
            ['to' => ['a@b.com'], 'subject' => 'Hi', 'body' => 'Hello body'],
            $this->context($this->account("Best,\nMe"), $factory),
        );

        $this->assertStringContainsString("Hello body\n\n-- \nBest,\nMe", $result->text);
        $this->assertStringEndsWith("Best,\nMe\n</email_content>", $result->text);
    }

    public function testHtmlDraftHasTwoPartsAndSanitisedHtml(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);

        $tool = new CreateDraftTool();
        $result = $tool->execute(
            [
                'to' => ['a@b.com'],
                'subject' => 'Hi',
                'body' => '<p>Hello</p><script>evil()</script><a href="javascript:evil()">bad</a>',
                'html' => true,
            ],
            $this->context($this->account(), $factory),
        );

        $this->assertFalse($result->isError);

        [, $raw] = $factory->imap->appended[0];

        $this->assertStringNotContainsString('<script', $raw);
        $this->assertStringNotContainsString('evil()', $raw);
        $this->assertStringNotContainsString('javascript:', $raw);

        preg_match('/boundary="([^"]+)"/', $raw, $m);
        $boundary = $m[1] ?? '';
        $this->assertNotSame('', $boundary);
        $this->assertSame(3, substr_count($raw, '--' . $boundary));

        $this->assertStringContainsString('Hello', $result->text);
        $this->assertStringNotContainsString('<p>', $result->text);
        $this->assertStringNotContainsString('<script', $result->text);
    }

    public function testReplyReferencesDedupAndCap(): void
    {
        $factory = new FakeSessionFactory();
        $this->withFolders($factory);

        $refs = [];

        for ($i = 1; $i <= 25; $i++) {
            $refs[] = "<ref{$i}@x.com>";
        }

        $factory->imap->headers[3] = 'Message-ID: <orig@x.com>' . "\r\n"
            . 'References: ' . implode(' ', $refs) . "\r\n"
            . 'Subject: Old thread';

        $tool = new CreateDraftTool();
        $tool->execute(
            ['to' => ['a@b.com'], 'subject' => 'Old thread', 'body' => 'Body', 'in_reply_to_uid' => 3],
            $this->context($this->account(), $factory),
        );

        [, $raw] = $factory->imap->appended[0];

        // A References header this long is folded across several
        // physical lines (see HeaderEncoder::fold()); Parser::parse()
        // reassembles it the same way a real client would.
        $ids = Parser::parse($raw)->references();

        $this->assertCount(20, $ids);
        $this->assertSame('<orig@x.com>', $ids[count($ids) - 1]);
    }

    public function testCreateDraftRejectsBadArguments(): void
    {
        $sessions = new FakeSessionFactory();
        $this->withFolders($sessions);
        $accounts = new AccountResolver(['default' => $this->account()]);
        $registry = new Registry(
            [new CreateDraftTool()],
            $accounts,
            $sessions,
            ['max_recipients' => 10],
            new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+02:00')),
        );

        $invalid = [
            ['to' => [], 'subject' => 'Hi', 'body' => 'Hello'],
            ['to' => ['not-an-address'], 'subject' => 'Hi', 'body' => 'Hello'],
            ['to' => ['a@b.com'], 'body' => 'Hello'],
            ['to' => ['a@b.com'], 'subject' => 'Hi', 'body' => 'Hello', 'in_reply_to_uid' => 0],
        ];

        foreach ($invalid as $args) {
            try {
                $registry->call('create_draft', $args);
                $this->fail('Expected ProtocolException for args: ' . json_encode($args));
            } catch (ProtocolException $e) {
                $this->assertSame(McpError::INVALID_PARAMS, $e->getCode());
            }
        }

        $this->assertSame([], $sessions->imap->calls);
    }

    public function testDefinitionSchemaAndAnnotations(): void
    {
        $tool = new CreateDraftTool();
        $definition = $tool->definition();

        $this->assertSame('create_draft', $definition['name']);
        $this->assertSame('Create draft', $definition['title']);
        $this->assertSame([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ], $definition['annotations']);
        $this->assertSame(['to', 'subject', 'body'], $definition['inputSchema']['required']);
        $this->assertFalse($definition['inputSchema']['additionalProperties']);
    }
}
