<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Protocol;

use MailboxMcp\Protocol\JsonRpc;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\ProtocolException;
use PHPUnit\Framework\TestCase;

final class JsonRpcTest extends TestCase
{
    public function testParsesRequest(): void
    {
        $message = JsonRpc::parse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'echo'],
        ], JSON_THROW_ON_ERROR));

        $this->assertFalse($message->isNotification);
        $this->assertSame(7, $message->id);
        $this->assertSame('tools/call', $message->method);
        $this->assertSame(['name' => 'echo'], $message->params);
    }

    public function testParsesNotification(): void
    {
        $message = JsonRpc::parse(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($message->isNotification);
        $this->assertNull($message->id);
        $this->assertSame('notifications/initialized', $message->method);
        $this->assertSame([], $message->params);
    }

    public function testParsesStringId(): void
    {
        $message = JsonRpc::parse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 'req-1',
            'method' => 'ping',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('req-1', $message->id);
    }

    public function testRejectsBatch(): void
    {
        try {
            JsonRpc::parse(json_encode([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
            ], JSON_THROW_ON_ERROR));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testRejectsNullId(): void
    {
        try {
            JsonRpc::parse(json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'method' => 'ping',
            ], JSON_THROW_ON_ERROR));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testRejectsFloatId(): void
    {
        try {
            JsonRpc::parse(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1.5,
                'method' => 'ping',
            ], JSON_THROW_ON_ERROR));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testRejectsBoolId(): void
    {
        try {
            JsonRpc::parse(json_encode([
                'jsonrpc' => '2.0',
                'id' => true,
                'method' => 'ping',
            ], JSON_THROW_ON_ERROR));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testRejectsBadJson(): void
    {
        try {
            JsonRpc::parse('{not json');
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::PARSE, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testRejectsWrongJsonRpcVersion(): void
    {
        try {
            JsonRpc::parse(json_encode([
                'jsonrpc' => '1.0',
                'id' => 1,
                'method' => 'ping',
            ], JSON_THROW_ON_ERROR));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testRejectsMissingMethod(): void
    {
        try {
            JsonRpc::parse(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
            ], JSON_THROW_ON_ERROR));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testParamsMustBeObject(): void
    {
        try {
            JsonRpc::parse(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'ping',
                'params' => [1, 2, 3],
            ], JSON_THROW_ON_ERROR));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testResultShape(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 5, 'result' => ['ok' => true]],
            JsonRpc::result(5, ['ok' => true]),
        );
    }

    public function testErrorShapeOmitsDataWhenNull(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 5, 'error' => ['code' => -32600, 'message' => 'bad']],
            JsonRpc::error(5, -32600, 'bad'),
        );
    }

    public function testErrorShapeIncludesData(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32602, 'message' => 'bad', 'data' => ['x' => 1]]],
            JsonRpc::error(null, -32602, 'bad', ['x' => 1]),
        );
    }

    public function testProtocolExceptionToJsonRpcOmitsDataWhenNull(): void
    {
        $exception = new ProtocolException(McpError::INTERNAL, 'boom', 500);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 9, 'error' => ['code' => McpError::INTERNAL, 'message' => 'boom']],
            $exception->toJsonRpc(9),
        );
        $this->assertSame(500, $exception->httpStatus());
        $this->assertNull($exception->data());
    }

    public function testProtocolExceptionToJsonRpcIncludesData(): void
    {
        $exception = new ProtocolException(McpError::UNSUPPORTED_VERSION, 'nope', 400, ['supported' => ['a']]);

        $this->assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => McpError::UNSUPPORTED_VERSION,
                    'message' => 'nope',
                    'data' => ['supported' => ['a']],
                ],
            ],
            $exception->toJsonRpc(null),
        );
    }
}
