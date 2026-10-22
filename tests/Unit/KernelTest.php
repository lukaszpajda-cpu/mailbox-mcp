<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit;

use DateTimeImmutable;
use MailboxMcp\Config\Validator;
use MailboxMcp\Http\Request;
use MailboxMcp\Kernel;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Tests\Support\EchoTool;
use MailboxMcp\Tests\Support\FakeSessionFactory;
use MailboxMcp\Tools\ToolContext;
use MailboxMcp\Tools\ToolInterface;
use MailboxMcp\Tools\ToolResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelTest extends TestCase
{
    private string $varDir;

    protected function setUp(): void
    {
        $this->varDir = sys_get_temp_dir() . '/mailbox-mcp-kernel-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->varDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir) && !is_file($dir)) {
            return;
        }

        if (is_file($dir)) {
            unlink($dir);

            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->rmrf($dir . '/' . $entry);
        }

        rmdir($dir);
    }

    private function token(): string
    {
        return str_repeat('A', 43);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        $base = [
            'auth' => ['token' => $this->token()],
            'security' => ['allow_http' => true],
            'paths' => ['var' => $this->varDir],
            'accounts' => [
                'example' => [
                    'email' => 'contact@example.com',
                    'name' => 'Example Co',
                    'imap' => [
                        'host' => 'mail.example.com',
                        'port' => 993,
                        'encryption' => 'tls',
                        'username' => 'contact@example.com',
                        'password' => 'secret',
                    ],
                    'smtp' => [
                        'host' => 'mail.example.com',
                        'port' => 587,
                        'encryption' => 'starttls',
                        'username' => 'contact@example.com',
                        'password' => 'secret',
                    ],
                ],
            ],
        ];

        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($base, $overrides);

        return Validator::validate($merged);
    }

    private function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config, ?FakeSessionFactory $sessions = null, ?FrozenClock $clock = null): Kernel
    {
        return new Kernel(
            $config,
            $clock ?? $this->clock(),
            $sessions ?? new FakeSessionFactory(),
            [new EchoTool()],
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $headers,
        string $body,
        bool $isHttps = true,
        string $ip = '198.51.100.1',
    ): Request {
        return new Request($method, $path, $headers, $body, $ip, $isHttps);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function authHeaders(array $headers = []): array
    {
        return ['Authorization' => 'Bearer ' . $this->token(), 'Content-Type' => 'application/json'] + $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function auditEntries(): array
    {
        $files = glob($this->varDir . '/audit/*.jsonl') ?: [];
        $this->assertNotEmpty($files, 'expected an audit log file to exist');
        sort($files);

        $entries = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            foreach (array_filter(explode("\n", $contents)) as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                /** @var array<string, mixed> $decoded */
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    private function pingBody(): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], JSON_THROW_ON_ERROR);
    }

    public function testUnknownPath404(): void
    {
        $kernel = $this->kernel($this->config());
        $response = $kernel->handle($this->request('GET', '/x', [], ''));

        $this->assertSame(404, $response->status);
        $this->assertSame("Not Found\n", $response->body);
    }

    public function testNonPostIs405WithAllowHeader(): void
    {
        $kernel = $this->kernel($this->config());
        $response = $kernel->handle($this->request('GET', '/mcp', [], ''));

        $this->assertSame(405, $response->status);
        $this->assertSame('POST', $response->headers['Allow']);
        $this->assertSame('', $response->body);
    }

    public function testBadToken404AndAuditEntry(): void
    {
        $kernel = $this->kernel($this->config());
        $response = $kernel->handle($this->request(
            'POST',
            '/mcp',
            ['Authorization' => 'Bearer wrong-token-value', 'Content-Type' => 'application/json'],
            $this->pingBody(),
        ));

        $this->assertSame(404, $response->status);
        $this->assertSame("Not Found\n", $response->body);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertSame('auth_failed', $last['event']);
        $this->assertSame('bad_header_token', $last['reason']);
    }

    public function testLockoutAfterFailures(): void
    {
        $config = $this->config();
        $kernel = $this->kernel($config);

        for ($i = 0; $i < 10; ++$i) {
            $response = $kernel->handle($this->request(
                'POST',
                '/mcp',
                ['Authorization' => 'Bearer wrong-token-value', 'Content-Type' => 'application/json'],
                $this->pingBody(),
            ));
            $this->assertSame(404, $response->status);
        }

        // The 10th failure is the one that trips the lock: its own
        // auth_failed entry must say so.
        $entriesAfterTenFailures = $this->auditEntries();
        $triggering = $entriesAfterTenFailures[count($entriesAfterTenFailures) - 1];
        $this->assertSame('auth_failed', $triggering['event']);
        $this->assertTrue($triggering['locked']);

        // 11th request, this time with a *correct* token: must still be
        // locked out (the lockout check runs before authentication), and
        // must not write any further audit entry (locked-out requests are
        // turned away silently).
        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));

        $this->assertSame(404, $response->status);
        $this->assertSame("Not Found\n", $response->body);
        $this->assertCount(count($entriesAfterTenFailures), $this->auditEntries());
    }

    public function testLockedOutRequestsAreNotAudited(): void
    {
        $config = $this->config(['security' => ['lockout_failures' => 1]]);
        $kernel = $this->kernel($config);

        $response = $kernel->handle($this->request(
            'POST',
            '/mcp',
            ['Authorization' => 'Bearer wrong-token-value', 'Content-Type' => 'application/json'],
            $this->pingBody(),
        ));
        $this->assertSame(404, $response->status);

        $entries = $this->auditEntries();
        $triggering = $entries[count($entries) - 1];
        $this->assertSame('auth_failed', $triggering['event']);
        $this->assertTrue($triggering['locked']);

        $countAfterLock = count($entries);

        for ($i = 0; $i < 3; ++$i) {
            $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));
            $this->assertSame(404, $response->status);
        }

        $this->assertCount($countAfterLock, $this->auditEntries());
    }

    public function testRateLimit429(): void
    {
        $config = $this->config(['security' => ['rate_limit_per_minute' => 2]]);
        $kernel = $this->kernel($config);

        $first = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));
        $second = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));
        $third = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));

        $this->assertSame(200, $first->status);
        $this->assertSame(200, $second->status);
        $this->assertSame(429, $third->status);
        $this->assertArrayHasKey('Retry-After', $third->headers);

        $body = $this->decode($third->body);
        $this->assertSame(-32000, $body['error']['code']);
        $this->assertSame('Rate limit exceeded', $body['error']['message']);
    }

    public function testOrigin403(): void
    {
        $config = $this->config(['security' => ['allowed_origins' => ['https://allowed.example']]]);
        $kernel = $this->kernel($config);

        $headers = $this->authHeaders(['Origin' => 'https://evil.example']);
        $response = $kernel->handle($this->request('POST', '/mcp', $headers, $this->pingBody()));

        $this->assertSame(403, $response->status);
        $body = $this->decode($response->body);
        $this->assertNull($body['id']);
        $this->assertSame(McpError::INVALID_REQUEST, $body['error']['code']);
        $this->assertSame('Origin not allowed', $body['error']['message']);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertSame('origin_rejected', $last['event']);
    }

    public function testOriginRejectionIsRateLimited(): void
    {
        $config = $this->config(['security' => [
            'allowed_origins' => ['https://allowed.example'],
            'rate_limit_per_minute' => 1,
        ]]);
        $kernel = $this->kernel($config);

        $headers = $this->authHeaders(['Origin' => 'https://evil.example']);

        $first = $kernel->handle($this->request('POST', '/mcp', $headers, $this->pingBody()));
        $second = $kernel->handle($this->request('POST', '/mcp', $headers, $this->pingBody()));

        $this->assertSame(403, $first->status);
        $this->assertSame(429, $second->status);

        $entries = $this->auditEntries();
        $events = array_map(static fn (array $e): string => (string) $e['event'], $entries);

        $this->assertSame(1, count(array_filter($events, static fn (string $e): bool => 'origin_rejected' === $e)));
        $this->assertSame(1, count(array_filter($events, static fn (string $e): bool => 'rate_limited' === $e)));
    }

    public function testBodyTooLarge413(): void
    {
        $config = $this->config(['security' => ['max_body_bytes' => 10]]);
        $kernel = $this->kernel($config);

        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));

        $this->assertSame(413, $response->status);
        $body = $this->decode($response->body);
        $this->assertSame(McpError::INVALID_REQUEST, $body['error']['code']);
        $this->assertSame('Request body too large', $body['error']['message']);
    }

    public function testWrongContentType415(): void
    {
        $config = $this->config();
        $kernel = $this->kernel($config);

        $headers = ['Authorization' => 'Bearer ' . $this->token(), 'Content-Type' => 'text/plain'];
        $response = $kernel->handle($this->request('POST', '/mcp', $headers, $this->pingBody()));

        $this->assertSame(415, $response->status);
    }

    public function testLegacyInitializeEndToEnd(): void
    {
        $kernel = $this->kernel($this->config());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ], JSON_THROW_ON_ERROR);

        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));

        $this->assertSame(200, $response->status);
        $decoded = $this->decode($response->body);
        $this->assertSame('2025-06-18', $decoded['result']['protocolVersion']);
    }

    public function testModernToolsCallEndToEnd(): void
    {
        $kernel = $this->kernel($this->config());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
                'arguments' => ['text' => 'hi'],
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $headers = $this->authHeaders([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'echo',
        ]);

        $response = $kernel->handle($this->request('POST', '/mcp', $headers, $body));

        $this->assertSame(200, $response->status);
        $decoded = $this->decode($response->body);
        $this->assertSame('hi', $decoded['result']['content'][0]['text']);
        $this->assertFalse($decoded['result']['isError']);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertSame('tool_call', $last['event']);
        $this->assertSame('echo', $last['tool']);
        $this->assertSame('modern', $last['era']);
        $this->assertSame('example', $last['account']);
        $this->assertTrue($last['ok']);
        $this->assertArrayNotHasKey('text', $last);
        $this->assertIsInt($last['duration_ms']);
    }

    public function testAuditToolCallEntryHasNoMessageText(): void
    {
        $kernel = $this->kernel($this->config());

        $secret = 'super-secret-message-body-xyz';
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => $secret]],
        ], JSON_THROW_ON_ERROR);

        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));
        $this->assertSame(200, $response->status);

        $files = glob($this->varDir . '/audit/*.jsonl') ?: [];
        $this->assertNotEmpty($files);
        $raw = file_get_contents($files[0]);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($secret, $raw);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertArrayNotHasKey('text', $last);
        $this->assertSame([], $last['args']);
    }

    public function testPathTokenWhenEnabled(): void
    {
        $config = $this->config(['auth' => ['allow_url_token' => true]]);
        $kernel = $this->kernel($config);

        $path = '/t/' . $this->token() . '/mcp';
        $response = $kernel->handle($this->request(
            'POST',
            $path,
            ['Content-Type' => 'application/json'],
            $this->pingBody(),
        ));

        $this->assertSame(200, $response->status);
    }

    public function testHttpsRequiredByDefault(): void
    {
        $config = $this->config(['security' => ['allow_http' => false]]);
        $kernel = $this->kernel($config);

        $response = $kernel->handle($this->request(
            'POST',
            '/mcp',
            $this->authHeaders(),
            $this->pingBody(),
            false,
        ));

        $this->assertSame(404, $response->status);
        $this->assertSame("Not Found\n", $response->body);
    }

    public function testDispatchExceptionIs500AndAudited(): void
    {
        $sessions = new FakeSessionFactory();
        $sessions->throwOnOpen = new RuntimeException('boom');
        $kernel = $this->kernel($this->config(), $sessions);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']],
        ], JSON_THROW_ON_ERROR);

        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));

        $this->assertSame(500, $response->status);
        $decoded = $this->decode($response->body);
        $this->assertSame(McpError::INTERNAL, $decoded['error']['code']);
        $this->assertSame('Internal error', $decoded['error']['message']);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertSame('internal_error', $last['event']);
        $this->assertStringContainsString('RuntimeException', $last['error']);
        $this->assertStringContainsString('boom', $last['error']);

        $files = glob($this->varDir . '/audit/*.jsonl') ?: [];
        $raw = file_get_contents($files[0]);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($body, $raw);
    }

    public function testSendDraftAuditIncludesRecipients(): void
    {
        $stub = new class () implements ToolInterface {
            public function name(): string
            {
                return 'send_draft';
            }

            public function definition(): array
            {
                return [
                    'name' => 'send_draft',
                    'title' => 'Send draft',
                    'description' => 'stub',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['draft_uid' => ['type' => 'integer', 'minimum' => 1]],
                        'required' => ['draft_uid'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => [
                        'readOnlyHint' => false,
                        'destructiveHint' => true,
                        'idempotentHint' => false,
                        'openWorldHint' => true,
                    ],
                ];
            }

            public function execute(array $args, ToolContext $ctx): ToolResult
            {
                return ToolResult::text('ok')->withAudit([
                    'recipients' => ['alice@example.com', 'bob@example.org'],
                    'message_id' => '<m1@example.com>',
                    'sent' => true,
                    'body' => 'super-secret-body',
                    'event' => 'forged',
                ]);
            }
        };

        $kernel = new Kernel($this->config(), $this->clock(), new FakeSessionFactory(), [$stub]);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => ['name' => 'send_draft', 'arguments' => ['draft_uid' => 42]],
        ], JSON_THROW_ON_ERROR);

        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));
        $this->assertSame(200, $response->status);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertSame('tool_call', $last['event']);
        $this->assertSame('send_draft', $last['tool']);
        $this->assertSame(['draft_uid' => 42], $last['args']);
        $this->assertSame(['alice@example.com', 'bob@example.org'], $last['recipients']);
        $this->assertSame('<m1@example.com>', $last['message_id']);
        $this->assertTrue($last['sent']);
        $this->assertArrayNotHasKey('body', $last);

        $files = glob($this->varDir . '/audit/*.jsonl') ?: [];
        $raw = file_get_contents($files[0]);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('super-secret-body', $raw);
    }

    public function testToolCallWithoutAuditHasNoSendFields(): void
    {
        $kernel = $this->kernel($this->config());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']],
        ], JSON_THROW_ON_ERROR);

        $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertArrayNotHasKey('recipients', $last);
        $this->assertArrayNotHasKey('message_id', $last);
        $this->assertArrayNotHasKey('sent', $last);
    }

    public function testMaybeGcRemovesStaleStateWhenProbabilityIs100(): void
    {
        $clock = $this->clock(); // 2026-01-01T00:00:00Z
        $minute = intdiv($clock->now()->getTimestamp(), 60);

        mkdir($this->varDir . '/ratelimit', 0700, true);
        mkdir($this->varDir . '/state', 0700, true);
        $staleWindow = $this->varDir . '/ratelimit/' . hash('sha256', '203.0.113.9') . '.json';
        file_put_contents($staleWindow, json_encode(['minute' => $minute - 5, 'count' => 3], JSON_THROW_ON_ERROR));
        $oldCounter = $this->varDir . '/state/send-example-20251101.count'; // 61 days old, retention 30
        $todayCounter = $this->varDir . '/state/send-example-20260101.count';
        file_put_contents($oldCounter, '{"count":1}');
        file_put_contents($todayCounter, '{"count":1}');

        $never = new Kernel($this->config(), $clock, new FakeSessionFactory(), [new EchoTool()], null, 0);
        $never->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));
        $this->assertFileExists($staleWindow);
        $this->assertFileExists($oldCounter);

        $always = new Kernel($this->config(), $clock, new FakeSessionFactory(), [new EchoTool()], null, 100);
        $response = $always->handle($this->request('POST', '/mcp', $this->authHeaders(), $this->pingBody()));

        $this->assertSame(200, $response->status);
        $this->assertFileDoesNotExist($staleWindow);
        $this->assertFileDoesNotExist($oldCounter);
        $this->assertFileExists($todayCounter);
        // the current window of the caller itself is kept
        $this->assertFileExists($this->varDir . '/ratelimit/' . hash('sha256', '198.51.100.1') . '.json');
    }

    public function testToolExceptionIsRecordedInAudit(): void
    {
        $kernel = $this->kernel($this->config());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'fail']],
        ], JSON_THROW_ON_ERROR);

        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));
        $this->assertSame(200, $response->status);
        $this->assertTrue($this->decode($response->body)['result']['isError']);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertSame('tool_call', $last['event']);
        $this->assertSame('echo', $last['tool']);
        $this->assertFalse($last['ok']);
        $this->assertSame('MailboxMcp\\Tools\\ToolException: echo failed', $last['error']);
    }

    public function testRequestDeadlineReachesSessionFactory(): void
    {
        $sessions = new FakeSessionFactory();
        $kernel = $this->kernel($this->config(['limits' => ['request_budget_seconds' => 30]]), $sessions);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']],
        ], JSON_THROW_ON_ERROR);

        $before = hrtime(true);
        $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));
        $after = hrtime(true);

        $this->assertCount(1, $sessions->deadlines);
        $deadline = $sessions->deadlines[0];
        $this->assertIsFloat($deadline);
        $this->assertGreaterThanOrEqual($before + 30e9, $deadline);
        $this->assertLessThanOrEqual($after + 30e9, $deadline);
    }

    public function testLegacyInvalidParamsIs200ButParseErrorStays400(): void
    {
        $kernel = $this->kernel($this->config());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => ['name' => 'bogus', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), $body));
        $this->assertSame(200, $response->status);
        $decoded = $this->decode($response->body);
        $this->assertSame(9, $decoded['id']);
        $this->assertSame(McpError::INVALID_PARAMS, $decoded['error']['code']);

        $entries = $this->auditEntries();
        $last = $entries[count($entries) - 1];
        $this->assertSame('tool_call', $last['event']);
        $this->assertFalse($last['ok']);

        $parse = $kernel->handle($this->request('POST', '/mcp', $this->authHeaders(), '{not json'));
        $this->assertSame(400, $parse->status);
        $this->assertSame(McpError::PARSE, $this->decode($parse->body)['error']['code']);
    }
}
