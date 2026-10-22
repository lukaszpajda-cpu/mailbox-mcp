<?php

declare(strict_types=1);

namespace MailboxMcp\Support;

use MailboxMcp\Version;

/**
 * HTTPS-only GET with certificate verification, no redirects, a total
 * timeout and a hard response-size cap. Uses ext-curl when it is loaded
 * and falls back to PHP's https:// stream wrapper (ext-openssl) otherwise,
 * so it adds no runtime dependency.
 */
final class HttpFetcher implements HttpFetcherInterface
{
    /**
     * @param bool $preferCurl false forces the stream-wrapper path even when ext-curl is loaded
     */
    public function __construct(private readonly bool $preferCurl = true)
    {
    }

    public function get(string $url, int $timeoutSeconds, int $maxBytes): string
    {
        if (!Url::isPlainHttps($url)) {
            throw new HttpFetchException('fetch failed: only absolute https:// URLs without credentials are allowed');
        }

        if ($timeoutSeconds < 1 || $maxBytes < 1) {
            throw new HttpFetchException('fetch failed: timeout and size limit must be positive');
        }

        if ($this->preferCurl && function_exists('curl_init')) {
            return $this->viaCurl($url, $timeoutSeconds, $maxBytes);
        }

        return $this->viaStream($url, $timeoutSeconds, $maxBytes);
    }

    private static function userAgent(): string
    {
        return 'mailbox-mcp/' . Version::STRING;
    }

    private function viaCurl(string $url, int $timeoutSeconds, int $maxBytes): string
    {
        $ch = curl_init();

        if (false === $ch) {
            throw new HttpFetchException('fetch failed: curl initialisation failed');
        }

        $body = '';
        $tooLarge = false;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (200 !== curl_getinfo($handle, CURLINFO_RESPONSE_CODE)) {
                    return 0; // non-200: no need to download the error body
                }

                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0; // aborts the transfer
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_PROTOCOLS_STR')] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if (!curl_setopt_array($ch, $options)) {
            throw new HttpFetchException('fetch failed: curl option rejected');
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if (0 !== $status && 200 !== $status) {
            throw new HttpFetchException("fetch failed: HTTP status {$status}");
        }

        if ($tooLarge || CURLE_FILESIZE_EXCEEDED === $errno) {
            throw new HttpFetchException("fetch failed: response exceeds {$maxBytes} bytes");
        }

        if (false === $ok || 0 !== $errno) {
            throw new HttpFetchException('fetch failed: curl error ' . $errno . ' (' . (curl_strerror($errno) ?? 'unknown') . ')');
        }

        if (200 !== $status) {
            throw new HttpFetchException('fetch failed: no HTTP status received');
        }

        return $body;
    }

    private function viaStream(string $url, int $timeoutSeconds, int $maxBytes): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => (float) $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
                'user_agent' => self::userAgent(),
                'header' => "Accept: application/json\r\nConnection: close\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $deadline = microtime(true) + $timeoutSeconds;
        $stream = @fopen($url, 'rb', false, $context);

        if (false === $stream) {
            throw new HttpFetchException('fetch failed: connection or TLS error');
        }

        try {
            $meta = stream_get_meta_data($stream);
            $status = self::statusFromHeaders($meta['wrapper_data'] ?? null);

            if (200 !== $status) {
                throw new HttpFetchException("fetch failed: HTTP status {$status}");
            }

            stream_set_timeout($stream, $timeoutSeconds);
            $body = '';

            while (!feof($stream)) {
                $chunk = fread($stream, 8192);

                if (false === $chunk) {
                    throw new HttpFetchException('fetch failed: read error');
                }

                $body .= $chunk;

                if (strlen($body) > $maxBytes) {
                    throw new HttpFetchException("fetch failed: response exceeds {$maxBytes} bytes");
                }

                if (true === stream_get_meta_data($stream)['timed_out'] || microtime(true) > $deadline) {
                    throw new HttpFetchException('fetch failed: timed out');
                }
            }

            return $body;
        } finally {
            fclose($stream);
        }
    }

    /**
     * The status code of the last `HTTP/x.y NNN` line in the wrapper's
     * response headers (there is only one: redirects are not followed),
     * or 0 when none can be found.
     */
    private static function statusFromHeaders(mixed $headers): int
    {
        $status = 0;

        if (!is_array($headers)) {
            return $status;
        }

        foreach ($headers as $line) {
            if (is_string($line) && 1 === preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
