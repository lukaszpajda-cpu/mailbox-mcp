<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

/**
 * The outcome of a tool's execute(), rendered as MCP tool-call content.
 *
 * $audit is a side channel for the audit log only: it is never part of
 * {@see toArray()} (so never shown to the model), and the Kernel copies
 * only an allow-listed set of its keys into the `tool_call` entry.
 */
final class ToolResult
{
    /**
     * @param array<string, mixed> $audit
     */
    private function __construct(
        public readonly string $text,
        public readonly bool $isError,
        public readonly array $audit = [],
    ) {
    }

    public static function text(string $text, bool $isError = false): self
    {
        return new self($text, $isError);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function withAudit(array $fields): self
    {
        return new self($this->text, $this->isError, $fields);
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function toArray(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $this->text]],
            'isError' => $this->isError,
        ];
    }
}
