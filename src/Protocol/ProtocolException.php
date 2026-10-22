<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

use RuntimeException;

/**
 * Thrown by the protocol layer for any JSON-RPC / MCP-level failure. Carries
 * the JSON-RPC error code (via the exception's built-in code), the HTTP
 * status to answer with, and optional structured error data.
 */
final class ProtocolException extends RuntimeException
{
    private readonly int $httpStatus;

    private readonly mixed $data;

    public function __construct(int $code, string $message, int $httpStatus, mixed $data = null)
    {
        parent::__construct($message, $code);

        $this->httpStatus = $httpStatus;
        $this->data = $data;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonRpc(string|int|null $id): array
    {
        return JsonRpc::error($id, $this->getCode(), $this->getMessage(), $this->data);
    }
}
