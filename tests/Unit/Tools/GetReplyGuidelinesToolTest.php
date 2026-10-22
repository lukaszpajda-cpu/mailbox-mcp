<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\GetReplyGuidelinesTool;
use MailboxMcp\Tools\ToolContext;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\TestCase;

final class GetReplyGuidelinesToolTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mailbox-mcp-guidelines-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);
    }

    private function account(?string $guidelinesFile): Account
    {
        return new Account(
            'default',
            new Identity('default@example.com', 'Default'),
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

    public function testGuidelinesMissingFileIsToolError(): void
    {
        $tool = new GetReplyGuidelinesTool();

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Account 'default' has no guidelines file configured");

        $tool->execute([], $this->context($this->account(null)));
    }

    public function testGuidelinesUnreadableFileIsToolError(): void
    {
        $tool = new GetReplyGuidelinesTool();
        $account = $this->account($this->tmpDir . '/does-not-exist.md');

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Guidelines file for account 'default' is not readable");

        $tool->execute([], $this->context($account));
    }

    public function testGuidelinesReturnsFileContentUnwrapped(): void
    {
        $path = $this->tmpDir . '/guidelines.md';
        file_put_contents($path, "Be concise.\nSign with regards.");

        $tool = new GetReplyGuidelinesTool();
        $result = $tool->execute([], $this->context($this->account($path)));

        $this->assertSame("Be concise.\nSign with regards.", $result->text);
        $this->assertFalse($result->isError);
        $this->assertStringNotContainsString('<email_content', $result->text);
    }

    public function testGuidelinesTruncatesLongFiles(): void
    {
        $path = $this->tmpDir . '/guidelines.md';
        file_put_contents($path, str_repeat('a', 40000));

        $tool = new GetReplyGuidelinesTool();
        $result = $tool->execute([], $this->context($this->account($path)));

        $this->assertStringEndsWith("\n[truncated]", $result->text);
        $this->assertSame(32768, strlen($result->text) - strlen("\n[truncated]"));
    }

    public function testDefinitionSchemaAndAnnotations(): void
    {
        $tool = new GetReplyGuidelinesTool();
        $definition = $tool->definition();

        $this->assertSame('get_reply_guidelines', $definition['name']);
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
