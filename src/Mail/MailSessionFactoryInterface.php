<?php

declare(strict_types=1);

namespace MailboxMcp\Mail;

use MailboxMcp\Accounts\Account;

interface MailSessionFactoryInterface
{
    /**
     * Builds the real (not-yet-connected) IMAP/SMTP clients for $account
     * and wraps them in a {@see MailSession}.
     *
     * @param array<string, mixed> $limits     effective numeric limits (timeouts, etc.)
     * @param ?float               $deadlineNs wall-clock end of the request as an
     *                                         `hrtime(true)` value (ns); passed to both
     *                                         clients so no socket wait outlives it
     */
    public function open(Account $account, array $limits, ?float $deadlineNs = null): MailSession;
}
