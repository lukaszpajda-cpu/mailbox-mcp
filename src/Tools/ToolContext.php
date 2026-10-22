<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Mail\MailSession;
use MailboxMcp\Support\Clock;

/**
 * Everything a tool's execute() needs beyond its own arguments: the
 * resolved account, the open mail session, the effective numeric limits,
 * the clock to use instead of calling time()/new DateTimeImmutable()
 * directly, and the request's wall-clock deadline (an `hrtime(true)`
 * value in ns, or null when none is set) for tools that do their own I/O
 * outside the mail session.
 */
final class ToolContext
{
    /**
     * @param array<string, mixed> $limits
     */
    public function __construct(
        public readonly Account $account,
        public readonly MailSession $session,
        public readonly array $limits,
        public readonly Clock $clock,
        public readonly ?float $deadlineNs = null,
    ) {
    }
}
