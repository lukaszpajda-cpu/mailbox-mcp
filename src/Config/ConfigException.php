<?php

declare(strict_types=1);

namespace MailboxMcp\Config;

use RuntimeException;

/**
 * Thrown by {@see Validator} and {@see Loader} on any configuration
 * problem. The message always names the offending key path, e.g.
 * "auth.token: must be at least 43 characters of [A-Za-z0-9_-]".
 */
final class ConfigException extends RuntimeException
{
}
