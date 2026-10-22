<?php

declare(strict_types=1);

namespace MailboxMcp\Support;

/**
 * Minimal outbound HTTPS GET, for operator-configured URLs only (never a
 * URL taken from a tool argument or from mail content).
 */
interface HttpFetcherInterface
{
    /**
     * @return string the response body of a `200` response, at most $maxBytes long
     *
     * @throws HttpFetchException on a non-https URL, a network/TLS error,
     *                            a non-200 status, a timeout, or a body over $maxBytes
     */
    public function get(string $url, int $timeoutSeconds, int $maxBytes): string;
}
