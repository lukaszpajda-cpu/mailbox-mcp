<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Mail\Imap\Folder;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\ProtocolException;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\GetThreadTool;
use MailboxMcp\Tools\Registry;
use MailboxMcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class GetThreadToolTest extends TestCase
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

    /**
     * @param array<string, mixed> $limits
     */
    private function context(Account $account, FakeSessionFactory $factory, array $limits = ['max_chars' => 50000]): ToolContext
    {
        $session = $factory->open($account, $limits);

        return new ToolContext($account, $session, $limits, new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    /**
     * @param array<int, string> $references
     *
     * @return array{flags: array<int, string>, size: int, internaldate: ?string, headers: string, bodystructure: string}
     */
    private function summary(
        string $subject,
        string $date,
        string $messageId,
        ?string $inReplyTo = null,
        array $references = [],
    ): array {
        $headers = "From: a@x\r\nTo: b@x\r\nSubject: {$subject}\r\nDate: {$date}\r\nMessage-ID: {$messageId}";

        if (null !== $inReplyTo) {
            $headers .= "\r\nIn-Reply-To: {$inReplyTo}";
        }

        if ([] !== $references) {
            $headers .= "\r\nReferences: " . implode(' ', $references);
        }

        return [
            'flags' => [],
            'size' => 10,
            'internaldate' => '20-Oct-2026 08:00:00 +0000',
            'headers' => $headers,
            'bodystructure' => '("text" "plain" ("charset" "UTF-8") NIL NIL "7bit" 1 1 NIL NIL NIL NIL)',
        ];
    }

    public function testThreadSortedByDate(): void
    {
        $factory = new FakeSessionFactory();
        $factory->imap->folders = [new Folder('INBOX', '/', ['\\inbox'])];

        $factory->imap->summaries[1] = $this->summary(
            'Start',
            'Tue, 20 Oct 2026 10:00:00 +0000',
            '<start@x>',
        );
        $factory->imap->summaries[2] = $this->summary(
            'Re: Start',
            'Wed, 21 Oct 2026 10:00:00 +0000',
            '<reply@x>',
            '<start@x>',
        );
        $factory->imap->summaries[3] = $this->summary(
            'My reply',
            'Tue, 20 Oct 2026 12:00:00 +0000',
            '<myreply@x>',
            '<start@x>',
        );
        $factory->imap->sections['1:1'] = 'Original body';
        $factory->imap->sections['2:1'] = 'Reply body';
        $factory->imap->sections['3:1'] = 'My reply body';

        $factory->imap->searchResults['HEADER In-Reply-To "<start@x>"'] = [2, 3];

        $tool = new GetThreadTool();
        $result = $tool->execute(
            ['folder' => 'INBOX', 'uid' => 1],
            $this->context($this->account(), $factory),
        );

        $this->assertFalse($result->isError);
        $this->assertStringStartsWith("thread: 3 messages\n\n", $result->text);

        $startPos = strpos($result->text, 'uid: 1');
        $myReplyPos = strpos($result->text, 'uid: 3');
        $replyPos = strpos($result->text, 'uid: 2');

        $this->assertIsInt($startPos);
        $this->assertIsInt($myReplyPos);
        $this->assertIsInt($replyPos);
        $this->assertLessThan($myReplyPos, $startPos);
        $this->assertLessThan($replyPos, $myReplyPos);

        $this->assertStringContainsString("folder: INBOX\n", $result->text);
    }

    public function testThreadSkipsUndetectedRoles(): void
    {
        // Only INBOX exists; no sent/drafts/etc. folder is detected.
        $factory = new FakeSessionFactory();
        $factory->imap->folders = [new Folder('INBOX', '/', ['\\inbox'])];

        $factory->imap->summaries[1] = $this->summary('Start', 'Tue, 20 Oct 2026 10:00:00 +0000', '<start@x>');
        $factory->imap->sections['1:1'] = 'Original body';

        $tool = new GetThreadTool();
        $result = $tool->execute(
            ['folder' => 'INBOX', 'uid' => 1],
            $this->context($this->account(), $factory),
        );

        $this->assertFalse($result->isError);
        $this->assertStringStartsWith("thread: 1 messages\n\n", $result->text);
    }

    public function testThreadRejectsBadArguments(): void
    {
        $sessions = new FakeSessionFactory();
        $accounts = new AccountResolver(['default' => $this->account()]);
        $registry = new Registry(
            [new GetThreadTool()],
            $accounts,
            $sessions,
            ['max_chars' => 50000],
            new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        );

        $invalid = [
            ['folder' => 'INBOX', 'uid' => 0],
            ['folder' => 'INBOX', 'uid' => 1, 'max_messages' => 0],
            ['folder' => 'INBOX', 'uid' => 1, 'max_messages' => 51],
            ['folder' => 'INBOX', 'uid' => 1, 'max_chars_per_message' => 100],
            ['folder' => "INBOX\r\nLOGOUT", 'uid' => 1],
        ];

        foreach ($invalid as $args) {
            try {
                $registry->call('get_thread', $args);
                $this->fail('Expected ProtocolException for args: ' . json_encode($args));
            } catch (ProtocolException $e) {
                $this->assertSame(McpError::INVALID_PARAMS, $e->getCode());
            }
        }

        $this->assertSame([], $sessions->imap->calls);
    }

    public function testDefinitionSchemaAndAnnotations(): void
    {
        $tool = new GetThreadTool();
        $definition = $tool->definition();

        $this->assertSame('get_thread', $definition['name']);
        $this->assertSame('Get thread', $definition['title']);
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string'],
                'folder' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'pattern' => '^[^\r\n\0]+$',
                ],
                'uid' => ['type' => 'integer', 'minimum' => 1],
                'max_messages' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                'max_chars_per_message' => ['type' => 'integer', 'minimum' => 200, 'maximum' => 20000, 'default' => 4000],
            ],
            'required' => ['folder', 'uid'],
            'additionalProperties' => false,
        ], $definition['inputSchema']);
        $this->assertSame([
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ], $definition['annotations']);
    }

    public function testThreadOutputIsCappedAtTwiceMaxChars(): void
    {
        $factory = new FakeSessionFactory();
        $factory->imap->folders = [new Folder('INBOX', '/', ['\\inbox'])];

        $factory->imap->summaries[1] = $this->summary('Start', 'Tue, 20 Oct 2026 10:00:00 +0000', '<start@x>');
        $factory->imap->summaries[2] = $this->summary('Re: Start', 'Wed, 21 Oct 2026 10:00:00 +0000', '<reply@x>', '<start@x>');
        $factory->imap->sections['1:1'] = str_repeat('a', 900);
        $factory->imap->sections['2:1'] = str_repeat('b', 900);
        $factory->imap->searchResults['HEADER In-Reply-To "<start@x>"'] = [2];

        $tool = new GetThreadTool();
        $uncapped = $tool->execute(['folder' => 'INBOX', 'uid' => 1], $this->context($this->account(), $factory));
        $this->assertStringNotContainsString('[thread output truncated]', $uncapped->text);

        $result = $tool->execute(
            ['folder' => 'INBOX', 'uid' => 1],
            $this->context($this->account(), $factory, ['max_chars' => 700]),
        );

        $this->assertFalse($result->isError);
        $this->assertStringEndsWith("\n[thread output truncated]", $result->text);
        $this->assertStringStartsWith("thread: 2 messages\n\n", $result->text);
        $this->assertStringNotContainsString('bbbb', $result->text);

        $body = substr($result->text, 0, -strlen("\n[thread output truncated]"));
        // at most the 1400-char cap plus the re-closed envelope tag
        $this->assertLessThanOrEqual(1400 + strlen("\n</email_content>"), mb_strlen($body));
        $this->assertSame(substr_count($body, '<email_content '), substr_count($body, '</email_content>'));
        $this->assertLessThan(mb_strlen($uncapped->text), mb_strlen($result->text));

        // A cut inside the first message's body re-closes its envelope.
        $inside = $tool->execute(
            ['folder' => 'INBOX', 'uid' => 1],
            $this->context($this->account(), $factory, ['max_chars' => 200]),
        );
        $this->assertStringEndsWith("aaa\n</email_content>\n[thread output truncated]", $inside->text);
        $this->assertSame(1, substr_count($inside->text, '<email_content '));
        $this->assertSame(1, substr_count($inside->text, '</email_content>'));
    }
}
