<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Support;

use Closure;
use MailboxMcp\Mail\Smtp\SmtpClientInterface;
use Throwable;

/**
 * In-memory stand-in for {@see SmtpClientInterface}.
 */
final class FakeSmtpClient implements SmtpClientInterface
{
    /** @var array<int, array{0: string, 1: array<int, string>, 2: string}> list of [from, recipients, raw] */
    public array $sent = [];

    public bool $connected = false;

    public bool $closed = false;

    /** Number of times connect() was called; lets tests verify lazy, connect-once behaviour. */
    public int $connectCalls = 0;

    public ?Throwable $throwOnSend = null;

    /** Called after a successful send() is recorded; lets a test change the world mid-call. */
    public ?Closure $onSend = null;

    public function connect(): void
    {
        ++$this->connectCalls;
        $this->connected = true;
    }

    public function send(string $from, array $recipients, string $raw): void
    {
        if (null !== $this->throwOnSend) {
            throw $this->throwOnSend;
        }

        $this->sent[] = [$from, $recipients, $raw];

        if (null !== $this->onSend) {
            ($this->onSend)();
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
