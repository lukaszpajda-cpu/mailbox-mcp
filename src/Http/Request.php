<?php

declare(strict_types=1);

namespace MailboxMcp\Http;

final class Request
{
    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers arbitrary-case header names; normalised to lowercase
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $headers,
        public readonly string $body,
        public readonly string $clientIp,
        public readonly bool $isHttps,
    ) {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $this->headers = $normalized;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<int, string>   $trustedProxies
     */
    public static function fromGlobals(array $server, string $body, array $trustedProxies): self
    {
        $headers = self::headersFromServer($server);

        $method = strtoupper(self::stringOrDefault($server['REQUEST_METHOD'] ?? null, 'GET'));
        $path = self::pathFromRequestUri(self::stringOrDefault($server['REQUEST_URI'] ?? null, '/'));
        $clientIp = ClientIp::resolve($server, $trustedProxies);
        $isHttps = self::isHttps($server, $trustedProxies);

        return new self($method, $path, $headers, $body, $clientIp, $isHttps);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_') && 'HTTP_AUTHORIZATION' !== $key) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }

        if (isset($server['CONTENT_LENGTH']) && is_string($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $server['CONTENT_LENGTH'];
        }

        $authorization = self::authorizationFromServer($server);

        if (null !== $authorization) {
            $headers['authorization'] = $authorization;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function authorizationFromServer(array $server): ?string
    {
        if (isset($server['HTTP_AUTHORIZATION']) && is_string($server['HTTP_AUTHORIZATION'])) {
            return $server['HTTP_AUTHORIZATION'];
        }

        if (isset($server['REDIRECT_HTTP_AUTHORIZATION']) && is_string($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return null;
    }

    private static function pathFromRequestUri(string $requestUri): string
    {
        $path = explode('?', $requestUri, 2)[0];

        return '' === $path ? '/' : $path;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<int, string>   $trustedProxies
     */
    private static function isHttps(array $server, array $trustedProxies): bool
    {
        $https = $server['HTTPS'] ?? null;

        if (is_string($https) && '' !== $https && 'off' !== strtolower($https)) {
            return true;
        }

        $forwardedProto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        $remoteAddr = self::stringOrDefault($server['REMOTE_ADDR'] ?? null, '');

        return is_string($forwardedProto)
            && 'https' === $forwardedProto
            && in_array($remoteAddr, $trustedProxies, true);
    }

    private static function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && '' !== $value ? $value : $default;
    }
}
