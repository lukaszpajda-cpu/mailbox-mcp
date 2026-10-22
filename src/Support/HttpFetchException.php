<?php

declare(strict_types=1);

namespace MailboxMcp\Support;

use RuntimeException;

/**
 * Thrown by {@see HttpFetcherInterface::get()}. The message is always
 * `fetch failed: <reason>` and never contains the URL, a response body
 * or response headers.
 */
final class HttpFetchException extends RuntimeException
{
}
