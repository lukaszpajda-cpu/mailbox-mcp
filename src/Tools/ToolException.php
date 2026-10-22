<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use RuntimeException;

/**
 * Thrown by a tool's execute() on any execution failure. The message is
 * shown directly to the model, so it must never contain secrets (passwords,
 * tokens, raw IMAP/SMTP responses that could leak credentials).
 */
final class ToolException extends RuntimeException
{
}
