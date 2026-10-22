<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Mail\MailSession;
use MailboxMcp\Support\Clock;

/**
 * Everything a tool's execute() needs beyond its own arguments: the
 * resolved account, the open mail session, the effective numeric limits,
 * and the clock to use instead of calling time()/new DateTimeImmutable()
 * directly.
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
    ) {
    }
}
