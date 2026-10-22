<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

use RuntimeException;

/**
 * Thrown by {@see ImapClientInterface} implementations (the real socket
 * client from Task 15, and any fake that opts in) on a connection or
 * protocol failure.
 */
final class ImapException extends RuntimeException
{
}
