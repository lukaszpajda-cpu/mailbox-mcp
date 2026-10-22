<?php

declare(strict_types=1);

namespace MailboxMcp\Mail;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Mail\Imap\Client as ImapClient;
use MailboxMcp\Mail\Smtp\Client as SmtpClient;

/**
 * Builds the real socket-based IMAP/SMTP clients from an account's
 * config. Neither client opens a socket here: {@see MailSession}
 * connects each lazily on first use.
 */
final class MailSessionFactory implements MailSessionFactoryInterface
{
    private const DEFAULT_IMAP_TIMEOUT = 15;

    private const DEFAULT_SMTP_TIMEOUT = 20;

    /**
     * @param array<string, mixed> $limits
     */
    public function open(Account $account, array $limits, ?float $deadlineNs = null): MailSession
    {
        $imap = new ImapClient(
            self::string($account->imap, 'host'),
            self::int($account->imap, 'port'),
            self::string($account->imap, 'encryption'),
            self::string($account->imap, 'username'),
            self::string($account->imap, 'password'),
            self::timeout($limits, 'imap_timeout', self::DEFAULT_IMAP_TIMEOUT),
            self::bool($account->imap, 'verify_peer'),
        );

        $smtp = new SmtpClient(
            self::string($account->smtp, 'host'),
            self::int($account->smtp, 'port'),
            self::string($account->smtp, 'encryption'),
            self::string($account->smtp, 'username'),
            self::string($account->smtp, 'password'),
            self::timeout($limits, 'smtp_timeout', self::DEFAULT_SMTP_TIMEOUT),
            self::bool($account->smtp, 'verify_peer'),
        );

        if (null !== $deadlineNs) {
            $imap->setDeadline($deadlineNs);
            $smtp->setDeadline($deadlineNs);
        }

        return new MailSession($account, $imap, $smtp, $account->folders);
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function string(array $section, string $key): string
    {
        $value = $section[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function int(array $section, string $key): int
    {
        $value = $section[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function bool(array $section, string $key): bool
    {
        return true === ($section[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $limits
     */
    private static function timeout(array $limits, string $key, int $default): int
    {
        $value = $limits[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
