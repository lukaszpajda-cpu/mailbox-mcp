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
use MailboxMcp\Tools\MarkProcessedTool;
use MailboxMcp\Tools\Registry;
use MailboxMcp\Tools\ToolContext;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkProcessedToolTest extends TestCase
{
    private function account(): Account
    {
        return new Account(
            'default',
            new Identity('me@example.com', 'Me'),
            [],
            [],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
    }

    private function factory(): FakeSessionFactory
    {
        $factory = new FakeSessionFactory();
        $factory->imap->folders = [
            new Folder('INBOX', '/', ['\\inbox']),
            new Folder('Archive', '/', []),
        ];

        return $factory;
    }

    private function context(FakeSessionFactory $factory): ToolContext
    {
        $account = $this->account();

        return new ToolContext(
            $account,
            $factory->open($account, []),
            [],
            new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00')),
        );
    }

    public function testMarkProcessedStoresKeyword(): void
    {
        $factory = $this->factory();

        $result = (new MarkProcessedTool())->execute(
            ['folder' => 'INBOX', 'uids' => [3, 5], 'processed' => true],
            $this->context($factory),
        );

        $this->assertFalse($result->isError);
        $this->assertContains(['select', 'INBOX', false], $factory->imap->calls);
        $this->assertSame([[[3, 5], ['$ClaudeSeen'], true]], $factory->imap->storedFlags);
        $this->assertSame(
            "account: default\nfolder: INBOX\nkeyword: \$ClaudeSeen\nprocessed: true\nchanged: 2",
            $result->text,
        );
    }

    public function testUnmark(): void
    {
        $factory = $this->factory();

        $result = (new MarkProcessedTool())->execute(
            ['folder' => 'Archive', 'uids' => [9], 'processed' => false],
            $this->context($factory),
        );

        $this->assertSame([[[9], ['$ClaudeSeen'], false]], $factory->imap->storedFlags);
        $this->assertStringContainsString("processed: false\n", $result->text);
        $this->assertStringContainsString('changed: 1', $result->text);
    }

    public function testMarkProcessedUnknownFolder(): void
    {
        $factory = $this->factory();

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Unknown folder 'Nope'");

        (new MarkProcessedTool())->execute(['folder' => 'Nope', 'uids' => [1]], $this->context($factory));
    }

    public function testMarkProcessedDefaultsToProcessedViaRegistry(): void
    {
        $factory = $this->factory();
        $registry = $this->registry($factory);

        $result = $registry->call('mark_processed', ['folder' => 'INBOX', 'uids' => [1]]);

        $this->assertFalse($result->isError);
        $this->assertSame([[[1], ['$ClaudeSeen'], true]], $factory->imap->storedFlags);
    }

    public function testMarkProcessedDefinition(): void
    {
        $def = (new MarkProcessedTool())->definition();

        $this->assertSame('mark_processed', $def['name']);
        $this->assertSame('Mark processed', $def['title']);
        $this->assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            $def['annotations'],
        );
        $this->assertSame(['folder', 'uids'], $def['inputSchema']['required']);
        $this->assertFalse($def['inputSchema']['additionalProperties']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function badArguments(): iterable
    {
        yield 'empty uids' => [['folder' => 'INBOX', 'uids' => []]];
        yield 'zero uid' => [['folder' => 'INBOX', 'uids' => [0]]];
        yield 'too many uids' => [['folder' => 'INBOX', 'uids' => range(1, 101)]];
        yield 'string uid' => [['folder' => 'INBOX', 'uids' => ['1']]];
        yield 'missing folder' => [['uids' => [1]]];
        yield 'newline in folder' => [['folder' => "IN\nBOX", 'uids' => [1]]];
        yield 'extra argument' => [['folder' => 'INBOX', 'uids' => [1], 'keyword' => '\\Deleted']];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('badArguments')]
    public function testMarkProcessedRejectsBadArguments(array $args): void
    {
        $factory = $this->factory();
        $registry = $this->registry($factory);

        try {
            $registry->call('mark_processed', $args);
            $this->fail('expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_PARAMS, $e->getCode());
        }

        $this->assertSame([], $factory->imap->calls);
    }

    private function registry(FakeSessionFactory $factory): Registry
    {
        return new Registry(
            [new MarkProcessedTool()],
            new AccountResolver(['default' => $this->account()]),
            $factory,
            [],
            new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00')),
        );
    }
}
