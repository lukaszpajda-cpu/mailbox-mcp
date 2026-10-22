<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

/**
 * An immutable, already-validated JSON-RPC message (request or notification).
 */
final class Message
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly bool $isNotification,
        public readonly string|int|null $id,
        public readonly string $method,
        public readonly array $params,
    ) {
    }
}
