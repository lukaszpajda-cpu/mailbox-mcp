<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Protocol;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Http\Request;
use MailboxMcp\Protocol\Dispatcher;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\Message;
use MailboxMcp\Protocol\ServerInfo;
use MailboxMcp\Protocol\Versions;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\EchoTool;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\Registry;
use MailboxMcp\Version;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    private function account(): Account
    {
        return new Account(
            'default',
            new Identity('default@example.com', 'Default'),
            [],
            [],
            ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            '',
            null,
            '$ClaudeSeen',
            ['allowed_domains' => [], 'denied_domains' => []],
        );
    }

    private function dispatcher(): Dispatcher
    {
        $accounts = new AccountResolver(['default' => $this->account()]);
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $registry = new Registry([new EchoTool()], $accounts, new FakeSessionFactory(), [], $clock);

        return new Dispatcher(new ServerInfo(), $registry);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers = []): Request
    {
        return new Request('POST', '/mcp', $headers, '', '127.0.0.1', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function modernMeta(string $version = '2026-07-28'): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    // --- legacy era ---

    public function testLegacyInitializeReturnsRequestedVersion(): void
    {
        $message = new Message(false, 1, 'initialize', ['protocolVersion' => '2025-06-18']);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);

        $this->assertSame('2025-06-18', $body['result']['protocolVersion']);
        $this->assertSame(['tools' => []], $body['result']['capabilities']);
        $this->assertSame(['name' => ServerInfo::NAME, 'version' => Version::STRING], $body['result']['serverInfo']);
        $this->assertStringContainsString('get_reply_guidelines', $body['result']['instructions']);
        // json_decode(..., true) cannot distinguish `{}` from `[]`; assert the raw
        // body so a regression to `'tools' => []` (a JSON array) would be caught.
        $this->assertStringContainsString('"capabilities":{"tools":{}}', $response->body);
    }

    public function testLegacyInitializeFallsBackToLatestLegacy(): void
    {
        $message = new Message(false, 1, 'initialize', ['protocolVersion' => '2024-11-05']);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $body = $this->decode($response->body);
        $this->assertSame(Versions::LATEST_LEGACY, $body['result']['protocolVersion']);
    }

    public function testLegacyInitializeIgnoresMcpProtocolVersionHeaderWhenAbsent(): void
    {
        $message = new Message(false, 1, 'initialize', ['protocolVersion' => '2025-06-18']);
        $response = $this->dispatcher()->dispatch($this->request([]), $message);

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame('2025-06-18', $body['result']['protocolVersion']);
    }

    public function testLegacyPing(): void
    {
        $message = new Message(false, 5, 'ping', []);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(200, $response->status);
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 5, 'result' => []],
            $this->decode($response->body),
        );
        // json_decode(..., true) cannot distinguish `{}` from `[]`; assert the raw
        // body so a regression to `'result' => []` (a JSON array) would be caught.
        $this->assertStringContainsString('"result":{}', $response->body);
    }

    public function testLegacyNotificationIs202(): void
    {
        $message = new Message(true, null, 'notifications/initialized', []);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(202, $response->status);
        $this->assertSame('', $response->body);
    }

    public function testLegacyUnknownMethod200(): void
    {
        $message = new Message(false, 3, 'nonexistent/method', []);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::METHOD_NOT_FOUND, $body['error']['code']);
        $this->assertSame('Method not found: nonexistent/method', $body['error']['message']);
    }

    public function testIgnoresSessionId(): void
    {
        $message = new Message(false, 2, 'tools/list', []);
        $response = $this->dispatcher()->dispatch(
            $this->request(['Mcp-Session-Id' => 'abc']),
            $message,
        );

        $this->assertSame(200, $response->status);
        $this->assertArrayNotHasKey('Mcp-Session-Id', $response->headers);
    }

    // --- modern era ---

    public function testModernDiscover(): void
    {
        $message = new Message(false, 7, 'server/discover', ['_meta' => $this->modernMeta()]);
        $response = $this->dispatcher()->dispatch(
            $this->request(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover']),
            $message,
        );

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $result = $body['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(Versions::MODERN, $result['supportedVersions']);
        $this->assertSame(['tools' => []], $result['capabilities']);
        $this->assertStringContainsString('get_reply_guidelines', $result['instructions']);
        $this->assertSame(3600000, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertSame(
            ['name' => ServerInfo::NAME, 'version' => Version::STRING],
            $result['_meta']['io.modelcontextprotocol/serverInfo'],
        );
        // json_decode(..., true) cannot distinguish `{}` from `[]`; assert the raw
        // body so a regression to `'tools' => []` (a JSON array) would be caught.
        $this->assertStringContainsString('"capabilities":{"tools":{}}', $response->body);
    }

    public function testModernToolsListWithoutMcpName(): void
    {
        $message = new Message(false, 8, 'tools/list', ['_meta' => $this->modernMeta()]);
        $response = $this->dispatcher()->dispatch(
            $this->request(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']),
            $message,
        );

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $result = $body['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertCount(1, $result['tools']);
        $this->assertSame('echo', $result['tools'][0]['name']);
        $this->assertSame(300000, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertArrayHasKey('_meta', $result);
    }

    public function testModernToolsCallEchoes(): void
    {
        $message = new Message(false, 9, 'tools/call', [
            'name' => 'echo',
            'arguments' => ['text' => 'hi'],
            '_meta' => $this->modernMeta(),
        ]);
        $response = $this->dispatcher()->dispatch(
            $this->request([
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'echo',
            ]),
            $message,
        );

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $result = $body['result'];

        $this->assertSame('hi', $result['content'][0]['text']);
        $this->assertFalse($result['isError']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(
            ['name' => ServerInfo::NAME, 'version' => Version::STRING],
            $result['_meta']['io.modelcontextprotocol/serverInfo'],
        );
    }

    public function testModernIntentWithoutMetaIs32602(): void
    {
        $message = new Message(false, 17, 'tools/list', []);
        $response = $this->dispatcher()->dispatch(
            $this->request(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']),
            $message,
        );

        $this->assertSame(400, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::INVALID_PARAMS, $body['error']['code']);
        $this->assertSame(
            'Missing required _meta field: io.modelcontextprotocol/protocolVersion',
            $body['error']['message'],
        );
    }

    public function testModernHeaderMismatchIs32020WhenBodySaysModern(): void
    {
        $message = new Message(false, 18, 'tools/list', ['_meta' => $this->modernMeta('2026-07-28')]);
        $response = $this->dispatcher()->dispatch(
            $this->request(['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/list']),
            $message,
        );

        $this->assertSame(400, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::HEADER_MISMATCH, $body['error']['code']);
    }

    public function testModernPingIs404(): void
    {
        $message = new Message(false, 10, 'ping', ['_meta' => $this->modernMeta()]);
        $response = $this->dispatcher()->dispatch(
            $this->request(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'ping']),
            $message,
        );

        $this->assertSame(404, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::METHOD_NOT_FOUND, $body['error']['code']);
        $this->assertSame('Method not found: ping', $body['error']['message']);
    }

    public function testModernUnknownMethod404(): void
    {
        $message = new Message(false, 11, 'bogus/method', ['_meta' => $this->modernMeta()]);
        $response = $this->dispatcher()->dispatch(
            $this->request(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'bogus/method']),
            $message,
        );

        $this->assertSame(404, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::METHOD_NOT_FOUND, $body['error']['code']);
    }

    public function testNotificationFailingValidationReturnsErrorWithNullId(): void
    {
        $message = new Message(true, null, 'notifications/initialized', ['_meta' => $this->modernMeta()]);
        $response = $this->dispatcher()->dispatch($this->request([]), $message);

        $this->assertSame(400, $response->status);
        $body = $this->decode($response->body);
        $this->assertNull($body['id']);
        $this->assertSame(McpError::HEADER_MISMATCH, $body['error']['code']);
    }

    // --- tools/call params: legacy era answers 200, modern era 400 ---

    public function testLegacyToolsCallUnknownToolIsInvalidParamsWith200(): void
    {
        $message = new Message(false, 12, 'tools/call', ['name' => 'bogus', 'arguments' => []]);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(12, $body['id']);
        $this->assertSame(McpError::INVALID_PARAMS, $body['error']['code']);
        $this->assertSame('Unknown tool: bogus', $body['error']['message']);
    }

    public function testLegacyToolsCallMissingNameIsInvalidParamsWith200(): void
    {
        $message = new Message(false, 13, 'tools/call', ['arguments' => ['text' => 'hi']]);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::INVALID_PARAMS, $body['error']['code']);
        $this->assertSame('Invalid params: name must be a string', $body['error']['message']);
    }

    public function testLegacySchemaViolationIsInvalidParamsWith200(): void
    {
        $message = new Message(false, 17, 'tools/call', ['name' => 'echo', 'arguments' => ['bogus' => 1]]);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::INVALID_PARAMS, $body['error']['code']);
        $this->assertStringStartsWith('Invalid arguments: ', $body['error']['message']);
    }

    public function testLegacyHeaderRejectionStays400(): void
    {
        $message = new Message(false, 18, 'tools/list', []);
        $response = $this->dispatcher()->dispatch($this->request(['MCP-Protocol-Version' => '1999-01-01']), $message);

        $this->assertSame(400, $response->status);
        $this->assertArrayHasKey('error', $this->decode($response->body));
    }

    public function testModernToolsCallUnknownToolIs400(): void
    {
        $message = new Message(false, 19, 'tools/call', ['name' => 'bogus', 'arguments' => [], '_meta' => $this->modernMeta()]);
        $response = $this->dispatcher()->dispatch($this->request(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'bogus']), $message);

        $this->assertSame(400, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::INVALID_PARAMS, $body['error']['code']);
        $this->assertSame('Unknown tool: bogus', $body['error']['message']);
    }

    public function testModernSchemaViolationIs400(): void
    {
        $message = new Message(false, 20, 'tools/call', ['name' => 'echo', 'arguments' => ['bogus' => 1], '_meta' => $this->modernMeta()]);
        $response = $this->dispatcher()->dispatch($this->request(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'echo']), $message);

        $this->assertSame(400, $response->status);
        $this->assertSame(McpError::INVALID_PARAMS, $this->decode($response->body)['error']['code']);
    }

    public function testToolExecutionErrorIsResultWithIsError(): void
    {
        $message = new Message(false, 14, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'fail']]);
        $response = $this->dispatcher()->dispatch($this->request(), $message);

        $this->assertSame(200, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame('echo failed', $body['result']['content'][0]['text']);
        $this->assertTrue($body['result']['isError']);
    }

    public function testLegacyResultsHaveNoModernEnvelope(): void
    {
        $listMessage = new Message(false, 15, 'tools/list', []);
        $listResponse = $this->dispatcher()->dispatch($this->request(), $listMessage);
        $listBody = $this->decode($listResponse->body);

        $this->assertArrayNotHasKey('resultType', $listBody['result']);
        $this->assertArrayNotHasKey('_meta', $listBody['result']);

        $callMessage = new Message(false, 16, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]);
        $callResponse = $this->dispatcher()->dispatch($this->request(), $callMessage);
        $callBody = $this->decode($callResponse->body);

        $this->assertArrayNotHasKey('resultType', $callBody['result']);
        $this->assertArrayNotHasKey('_meta', $callBody['result']);
    }

    // --- server instructions ---

    public function testInstructionsContainRules(): void
    {
        $instructions = (new ServerInfo())->instructions();

        $this->assertStringContainsString('<email_content untrusted="true">', $instructions);
        $this->assertStringContainsString('get_reply_guidelines', $instructions);
        $this->assertStringContainsString('send_draft', $instructions);
        $this->assertStringContainsString('mark_processed', $instructions);

        $withExtra = (new ServerInfo('Also: be nice.'))->instructions();
        $this->assertStringEndsWith("\n\nAlso: be nice.", $withExtra);
    }
}
