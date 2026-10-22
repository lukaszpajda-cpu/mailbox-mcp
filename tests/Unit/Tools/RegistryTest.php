<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\ProtocolException;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\EchoTool;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\Registry;
use MailboxMcp\Tools\ToolException;
use MailboxMcp\Tools\ToolContext;
use MailboxMcp\Tools\ToolInterface;
use MailboxMcp\Tools\ToolResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistryTest extends TestCase
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

    private function accounts(): AccountResolver
    {
        return new AccountResolver([
            'default' => $this->account('default'),
            'other' => $this->account('other'),
        ]);
    }

    private function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    /**
     * @param array<int, ToolInterface> $tools
     */
    private function registry(array $tools, FakeSessionFactory $sessions): Registry
    {
        return new Registry($tools, $this->accounts(), $sessions, [], $this->clock());
    }

    /**
     * A tool whose schema accepts an optional `account` string, so tests
     * can exercise account resolution (unlike EchoTool, whose schema has
     * no `account` property).
     */
    private function accountAwareTool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function name(): string
            {
                return 'whoami';
            }

            public function definition(): array
            {
                return [
                    'name' => 'whoami',
                    'title' => 'Who Am I',
                    'description' => 'Returns the resolved account id',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['account' => ['type' => 'string']],
                        'required' => [],
                        'additionalProperties' => false,
                    ],
                    'annotations' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                ];
            }

            public function execute(array $args, ToolContext $ctx): ToolResult
            {
                return ToolResult::text($ctx->account->id);
            }
        };
    }

    /**
     * A tool that connects the IMAP session before crashing, so closing
     * the session has something to actually close.
     */
    private function sessionTouchingCrashTool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function name(): string
            {
                return 'crasher';
            }

            public function definition(): array
            {
                return [
                    'name' => 'crasher',
                    'title' => 'Crasher',
                    'description' => 'Connects IMAP then crashes',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [],
                        'required' => [],
                        'additionalProperties' => false,
                    ],
                    'annotations' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                ];
            }

            public function execute(array $args, ToolContext $ctx): ToolResult
            {
                $ctx->session->imap();

                throw new RuntimeException('boom');
            }
        };
    }

    public function testDefinitionsReturnInConstructorOrder(): void
    {
        $whoami = $this->accountAwareTool();
        $echo = new EchoTool();
        $registry = $this->registry([$whoami, $echo], new FakeSessionFactory());

        $names = array_map(static fn (array $d): string => $d['name'], $registry->definitions());

        $this->assertSame(['whoami', 'echo'], $names);
    }

    public function testCallUnknownToolIsInvalidParams(): void
    {
        $registry = $this->registry([new EchoTool()], new FakeSessionFactory());

        try {
            $registry->call('bogus', []);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_PARAMS, $e->getCode());
            $this->assertSame('Unknown tool: bogus', $e->getMessage());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testRegistrySchemaRejection(): void
    {
        $registry = $this->registry([new EchoTool()], new FakeSessionFactory());

        try {
            $registry->call('echo', []);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_PARAMS, $e->getCode());
            $this->assertSame('Invalid arguments: text: required', $e->getMessage());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testCallResolvesDefaultAccountAndRuns(): void
    {
        $sessions = new FakeSessionFactory();
        $registry = $this->registry([new EchoTool()], $sessions);

        $result = $registry->call('echo', ['text' => 'hi']);

        $this->assertSame(['content' => [['type' => 'text', 'text' => 'hi']], 'isError' => false], $result->toArray());
        $this->assertSame('default', $registry->lastAccountId);
        $this->assertSame(['default'], $sessions->opened);
        $this->assertNull($registry->lastError);
    }

    public function testCallResolvesNamedAccount(): void
    {
        $sessions = new FakeSessionFactory();
        $registry = $this->registry([$this->accountAwareTool()], $sessions);

        $result = $registry->call('whoami', ['account' => 'other']);

        $this->assertSame('other', $result->text);
        $this->assertFalse($result->isError);
        $this->assertSame('other', $registry->lastAccountId);
        $this->assertSame(['other'], $sessions->opened);
    }

    public function testUnknownAccountIsErrorResultNotProtocolException(): void
    {
        $sessions = new FakeSessionFactory();
        $registry = $this->registry([$this->accountAwareTool()], $sessions);

        $result = $registry->call('whoami', ['account' => 'nonexistent']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString("Unknown account 'nonexistent'", $result->text);
        $this->assertNull($registry->lastAccountId);
        $this->assertSame([], $sessions->opened);
    }

    public function testToolExceptionDuringExecuteIsErrorResult(): void
    {
        $registry = $this->registry([new EchoTool()], new FakeSessionFactory());

        $result = $registry->call('echo', ['text' => 'fail']);

        $this->assertTrue($result->isError);
        $this->assertSame('echo failed', $result->text);
        // recorded for the audit log too (a ToolException message is content-free)
        $this->assertInstanceOf(ToolException::class, $registry->lastError);
        $this->assertSame('echo failed', $registry->lastError->getMessage());
    }

    public function testRegistryClosesSessionOnCrash(): void
    {
        $sessions = new FakeSessionFactory();
        $registry = $this->registry([$this->sessionTouchingCrashTool()], $sessions);

        $result = $registry->call('crasher', []);

        $this->assertTrue($result->isError);
        $this->assertSame('Internal error (see audit log)', $result->text);
        $this->assertInstanceOf(RuntimeException::class, $registry->lastError);
        $this->assertSame('boom', $registry->lastError->getMessage());
        $this->assertTrue($sessions->imap->closed);
    }

    public function testLastErrorAndAccountResetBetweenCalls(): void
    {
        $sessions = new FakeSessionFactory();
        $registry = $this->registry([$this->sessionTouchingCrashTool(), new EchoTool()], $sessions);

        $registry->call('crasher', []);
        $this->assertNotNull($registry->lastError);

        $registry->call('echo', ['text' => 'hi']);

        $this->assertNull($registry->lastError);
        $this->assertSame('default', $registry->lastAccountId);
    }

    public function testLastAccountIdResetWhenToolUnknown(): void
    {
        $sessions = new FakeSessionFactory();
        $registry = $this->registry([$this->accountAwareTool()], $sessions);

        $registry->call('whoami', ['account' => 'other']);
        $this->assertSame('other', $registry->lastAccountId);

        try {
            $registry->call('bogus', []);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException) {
            // expected
        }

        $this->assertNull($registry->lastAccountId);
    }
}
