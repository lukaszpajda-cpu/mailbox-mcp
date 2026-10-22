<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use MailboxMcp\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase
{
    public function testToolResultArrayShape(): void
    {
        $result = ToolResult::text('hello');

        $this->assertSame(
            [
                'content' => [['type' => 'text', 'text' => 'hello']],
                'isError' => false,
            ],
            $result->toArray(),
        );
    }

    public function testToolResultArrayShapeWhenError(): void
    {
        $result = ToolResult::text('boom', true);

        $this->assertSame(
            [
                'content' => [['type' => 'text', 'text' => 'boom']],
                'isError' => true,
            ],
            $result->toArray(),
        );
        $this->assertSame('boom', $result->text);
        $this->assertTrue($result->isError);
    }

    public function testWithAuditIsImmutableAndKeepsContent(): void
    {
        $original = ToolResult::text('hello', true);
        $withAudit = $original->withAudit(['sent' => false]);

        $this->assertSame([], $original->audit);
        $this->assertSame(['sent' => false], $withAudit->audit);
        $this->assertSame('hello', $withAudit->text);
        $this->assertTrue($withAudit->isError);
        $this->assertSame($original->toArray(), $withAudit->toArray());
    }
}
