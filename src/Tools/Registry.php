<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Mail\MailSessionFactoryInterface;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\ProtocolException;
use MailboxMcp\Support\Clock;
use Throwable;

/**
 * Validates tool arguments against each tool's schema, resolves the target
 * account, opens a mail session, and runs the tool. Stateless across calls
 * except for the three public audit fields, which are reset at the start
 * of every {@see call()}.
 */
final class Registry
{
    /** @var array<string, ToolInterface> name => tool, in constructor order */
    private readonly array $tools;

    private readonly SchemaValidator $validator;

    /**
     * Set when account resolution or a tool's execute() throws (a
     * {@see ToolException}, whose message is contractually content-free,
     * or anything else); reset to null at the start of every call(). The
     * Kernel copies it into the `tool_call` audit entry as `error`.
     */
    public ?Throwable $lastError = null;

    /** Set once the account argument has successfully resolved; reset to null at the start of every call(). */
    public ?string $lastAccountId = null;

    /**
     * The {@see ToolResult::$audit} fields of the last successfully
     * returned result; reset to [] at the start of every call().
     *
     * @var array<string, mixed>
     */
    public array $lastAudit = [];

    /** Wall-clock request deadline (`hrtime(true)` ns) handed to every session; see {@see self::setDeadline()}. */
    private ?float $deadlineNs = null;

    /**
     * @param array<int, ToolInterface> $tools  in deterministic (constructor) order
     * @param array<string, mixed>      $limits effective numeric limits passed through to every session and ToolContext
     */
    public function __construct(
        array $tools,
        private readonly AccountResolver $accounts,
        private readonly MailSessionFactoryInterface $sessions,
        private readonly array $limits,
        private readonly Clock $clock,
    ) {
        $indexed = [];

        foreach ($tools as $tool) {
            $indexed[$tool->name()] = $tool;
        }

        $this->tools = $indexed;
        $this->validator = new SchemaValidator();
    }

    /**
     * Sets the wall-clock deadline (an `hrtime(true)` value in ns) for the
     * current request; every session opened by {@see self::call()} passes
     * it to its IMAP and SMTP clients. Null means no deadline.
     */
    public function setDeadline(?float $deadlineNs): void
    {
        $this->deadlineNs = $deadlineNs;
    }

    /**
     * @return list<array<string, mixed>> tool definitions, in constructor order
     */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (ToolInterface $tool): array => $tool->definition(),
            $this->tools,
        ));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function call(string $name, array $args): ToolResult
    {
        $this->lastError = null;
        $this->lastAccountId = null;
        $this->lastAudit = [];

        $tool = $this->tools[$name] ?? null;

        if (null === $tool) {
            throw new ProtocolException(McpError::INVALID_PARAMS, "Unknown tool: {$name}", 400);
        }

        /** @var array<string, mixed> $schema */
        $schema = $tool->definition()['inputSchema'];
        $args = $this->validator->applyDefaults($schema, $args);
        $errors = $this->validator->validate($schema, $args);

        if ([] !== $errors) {
            throw new ProtocolException(
                McpError::INVALID_PARAMS,
                'Invalid arguments: ' . implode('; ', $errors),
                400,
            );
        }

        $rawAccountId = $args['account'] ?? null;
        $accountId = is_string($rawAccountId) ? $rawAccountId : null;

        try {
            $account = $this->accounts->resolve($accountId);
        } catch (ToolException $e) {
            $this->lastError = $e;

            return ToolResult::text($e->getMessage(), true);
        }

        $this->lastAccountId = $account->id;

        $session = $this->sessions->open($account, $this->limits, $this->deadlineNs);

        try {
            $result = $tool->execute($args, new ToolContext($account, $session, $this->limits, $this->clock, $this->deadlineNs));
            $this->lastAudit = $result->audit;

            return $result;
        } catch (ToolException $e) {
            $this->lastError = $e;

            return ToolResult::text($e->getMessage(), true);
        } catch (Throwable $e) {
            $this->lastError = $e;

            return ToolResult::text('Internal error (see audit log)', true);
        } finally {
            $session->close();
        }
    }
}
