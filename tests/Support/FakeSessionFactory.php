<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Support;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Mail\MailSession;
use MailboxMcp\Mail\MailSessionFactoryInterface;
use Throwable;

/**
 * Test double for {@see MailSessionFactoryInterface}: always returns a
 * {@see MailSession} wired to the same fake clients, so a test can set
 * up canned IMAP/SMTP responses before the code under test opens a
 * session.
 */
final class FakeSessionFactory implements MailSessionFactoryInterface
{
    /** @var array<int, string> account ids passed to open() */
    public array $opened = [];

    /** @var array<int, ?float> deadline passed to each open() call */
    public array $deadlines = [];

    /** When set, open() throws this instead of returning a session. */
    public ?Throwable $throwOnOpen = null;

    public function __construct(
        public readonly FakeImapClient $imap = new FakeImapClient(),
        public readonly FakeSmtpClient $smtp = new FakeSmtpClient(),
    ) {
    }

    /**
     * @param array<string, mixed> $limits
     */
    public function open(Account $account, array $limits, ?float $deadlineNs = null): MailSession
    {
        $this->deadlines[] = $deadlineNs;

        if (null !== $this->throwOnOpen) {
            throw $this->throwOnOpen;
        }

        $this->opened[] = $account->id;

        return new MailSession($account, $this->imap, $this->smtp, $account->folders);
    }
}
