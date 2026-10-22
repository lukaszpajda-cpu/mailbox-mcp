<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

use JsonException;

/**
 * JSON-RPC 2.0 envelope parsing and response shaping.
 */
final class JsonRpc
{
    public static function parse(string $body): Message
    {
        try {
            $decoded = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProtocolException(McpError::PARSE, 'Parse error', 400);
        }

        if (!is_array($decoded)) {
            throw new ProtocolException(McpError::INVALID_REQUEST, 'Invalid Request: expected a JSON object', 400);
        }

        if ([] !== $decoded && array_is_list($decoded)) {
            throw new ProtocolException(McpError::INVALID_REQUEST, 'Invalid Request: batch requests are not supported', 400);
        }

        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            throw new ProtocolException(McpError::INVALID_REQUEST, 'Invalid Request: jsonrpc must be "2.0"', 400);
        }

        $method = $decoded['method'] ?? null;

        if (!is_string($method) || '' === $method) {
            throw new ProtocolException(McpError::INVALID_REQUEST, 'Invalid Request: method must be a non-empty string', 400);
        }

        $isNotification = !array_key_exists('id', $decoded);
        $id = null;

        if (!$isNotification) {
            $rawId = $decoded['id'];

            if (!is_string($rawId) && !is_int($rawId)) {
                throw new ProtocolException(McpError::INVALID_REQUEST, 'Invalid Request: id must be a string or integer', 400);
            }

            $id = $rawId;
        }

        $params = [];

        if (array_key_exists('params', $decoded)) {
            $rawParams = $decoded['params'];

            if (!is_array($rawParams) || ([] !== $rawParams && array_is_list($rawParams))) {
                throw new ProtocolException(McpError::INVALID_REQUEST, 'Invalid Request: params must be an object', 400);
            }

            /** @var array<string, mixed> $rawParams */
            $params = $rawParams;
        }

        return new Message($isNotification, $id, $method, $params);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function result(string|int $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(string|int|null $id, int $code, string $message, mixed $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if (null !== $data) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
