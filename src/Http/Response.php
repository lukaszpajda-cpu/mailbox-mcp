<?php

declare(strict_types=1);

namespace MailboxMcp\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers additional headers; override the json defaults on name collision
     */
    public static function json(int $status, array $payload, array $headers = []): self
    {
        $defaults = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return new self($status, array_merge($defaults, $headers), $body);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function empty(int $status, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    public static function notFound(): self
    {
        return new self(404, ['Content-Type' => 'text/plain; charset=utf-8'], "Not Found\n");
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
