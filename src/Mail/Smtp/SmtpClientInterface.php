<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Smtp;

/**
 * Everything a mail tool needs to send a message over SMTP. The real
 * implementation (a plain socket client, Task 16) and
 * {@see \MailboxMcp\Tests\Support\FakeSmtpClient} both implement this.
 */
interface SmtpClientInterface
{
    /**
     * Opens the socket, negotiates TLS/STARTTLS per the account config,
     * and authenticates. Called at most once per instance, lazily, by
     * {@see \MailboxMcp\Mail\MailSession::smtp()}.
     *
     * @throws SmtpException
     */
    public function connect(): void;

    /**
     * @param array<int, string> $recipients envelope recipients (to + cc + bcc)
     *
     * @throws SmtpException
     */
    public function send(string $from, array $recipients, string $raw): void;

    public function close(): void;
}
