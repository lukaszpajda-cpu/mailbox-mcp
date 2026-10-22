<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

use MailboxMcp\Http\Request;
use MailboxMcp\Http\Response;
use MailboxMcp\Tools\Registry;
use stdClass;

/**
 * Turns one already-parsed JSON-RPC {@see Message} plus the originating
 * HTTP {@see Request} into the JSON-RPC {@see Response}, for both the
 * modern (header-mirrored) and legacy (initialize-based) protocol eras.
 * Never throws for protocol-level problems: a {@see ProtocolException}
 * raised anywhere below is turned into its JSON-RPC error response here.
 */
final class Dispatcher
{
    /**
     * Set by {@see self::toolsCallResult()} to the tool result array
     * (`content`/`isError`), before the modern envelope is merged in and
     * before the response is JSON-encoded. Reset to null at the start of
     * every {@see self::dispatch()}. Lets callers (the Kernel's audit
     * log) inspect whether the call succeeded without re-decoding the
     * response body.
     *
     * @var array{content: list<array{type: string, text: string}>, isError: bool}|null
     */
    public ?array $lastResult = null;

    public function __construct(
        private readonly ServerInfo $info,
        private readonly Registry $registry,
    ) {
    }

    public function dispatch(Request $request, Message $message): Response
    {
        $this->lastResult = null;

        try {
            $era = Era::detect($message->params, $request->header('MCP-Protocol-Version'));

            return Era::Modern === $era
                ? $this->dispatchModern($request, $message)
                : $this->dispatchLegacy($request, $message);
        } catch (ProtocolException $e) {
            return Response::json($e->httpStatus(), $e->toJsonRpc($message->id));
        }
    }

    private function dispatchModern(Request $request, Message $message): Response
    {
        HeaderValidation::validateModern($request, $message);

        if ($message->isNotification) {
            return Response::empty(202);
        }

        $id = $message->id;
        assert(null !== $id);

        return match ($message->method) {
            'server/discover' => Response::json(200, JsonRpc::result($id, $this->modernDiscoverResult())),
            'tools/list' => Response::json(200, JsonRpc::result($id, $this->modernToolsListResult())),
            'tools/call' => Response::json(200, JsonRpc::result($id, $this->toolsCallResult($message, true))),
            default => Response::json(
                404,
                JsonRpc::error($id, McpError::METHOD_NOT_FOUND, "Method not found: {$message->method}"),
            ),
        };
    }

    private function dispatchLegacy(Request $request, Message $message): Response
    {
        HeaderValidation::validateLegacy($request);

        if ($message->isNotification) {
            return Response::empty(202);
        }

        $id = $message->id;
        assert(null !== $id);

        // Legacy-era clients expect a JSON-RPC error that follows a
        // successful parse (unknown method, invalid params, unknown tool,
        // schema violations, …) as an ordinary HTTP 200 response. Only the
        // header rejection above, and parse/invalid-request errors raised
        // by the Kernel before dispatch, keep their 4xx status.
        try {
            return match ($message->method) {
                'initialize' => Response::json(200, JsonRpc::result($id, $this->legacyInitializeResult($message))),
                'ping' => Response::json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()]),
                'tools/list' => Response::json(200, JsonRpc::result($id, $this->legacyToolsListResult())),
                'tools/call' => Response::json(200, JsonRpc::result($id, $this->toolsCallResult($message, false))),
                default => Response::json(
                    200,
                    JsonRpc::error($id, McpError::METHOD_NOT_FOUND, "Method not found: {$message->method}"),
                ),
            };
        } catch (ProtocolException $e) {
            return Response::json(200, $e->toJsonRpc($id));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function modernDiscoverResult(): array
    {
        return [
            'resultType' => 'complete',
            'supportedVersions' => Versions::MODERN,
            'capabilities' => ['tools' => new stdClass()],
            'instructions' => $this->info->instructions(),
            'ttlMs' => 3600000,
            'cacheScope' => 'private',
            '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->info->toArray()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modernToolsListResult(): array
    {
        return [
            'resultType' => 'complete',
            'tools' => $this->registry->definitions(),
            'ttlMs' => 300000,
            'cacheScope' => 'private',
            '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->info->toArray()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyInitializeResult(Message $message): array
    {
        $requested = $message->params['protocolVersion'] ?? null;

        $version = (is_string($requested) && in_array($requested, Versions::LEGACY, true))
            ? $requested
            : Versions::LATEST_LEGACY;

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => new stdClass()],
            'serverInfo' => $this->info->toArray(),
            'instructions' => $this->info->instructions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyToolsListResult(): array
    {
        return ['tools' => $this->registry->definitions()];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolsCallResult(Message $message, bool $modern): array
    {
        $name = $message->params['name'] ?? null;

        if (!is_string($name) || '' === $name) {
            throw new ProtocolException(McpError::INVALID_PARAMS, 'Invalid params: name must be a string', 400);
        }

        $arguments = $message->params['arguments'] ?? [];

        if (!is_array($arguments) || ([] !== $arguments && array_is_list($arguments))) {
            throw new ProtocolException(McpError::INVALID_PARAMS, 'Invalid params: arguments must be an object', 400);
        }

        /** @var array<string, mixed> $arguments */
        $result = $this->registry->call($name, $arguments)->toArray();
        $this->lastResult = $result;

        if (!$modern) {
            return $result;
        }

        return $result + [
            'resultType' => 'complete',
            '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->info->toArray()],
        ];
    }
}
