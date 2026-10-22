<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Smtp;

use RuntimeException;

/**
 * Thrown by {@see SmtpClientInterface} implementations (the real socket
 * client from Task 16) on a connection or protocol failure.
 */
final class SmtpException extends RuntimeException
{
}
