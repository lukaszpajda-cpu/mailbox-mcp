<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Protocol;

use MailboxMcp\Http\Request;
use MailboxMcp\Protocol\HeaderValidation;
use MailboxMcp\Protocol\McpError;
use MailboxMcp\Protocol\Message;
use MailboxMcp\Protocol\ProtocolException;
use MailboxMcp\Protocol\Versions;
use PHPUnit\Framework\TestCase;

final class HeaderValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function modernMessage(string $method, array $params = [], string $version = '2026-07-28'): Message
    {
        $meta = [
            'io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];

        return new Message(false, 1, $method, $params + ['_meta' => $meta]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): Request
    {
        return new Request('POST', '/', $headers, '', '127.0.0.1', true);
    }

    public function testValidModernPasses(): void
    {
        $message = $this->modernMessage('tools/list');
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ]);

        HeaderValidation::validateModern($request, $message);
        $this->addToAssertionCount(1);
    }

    public function testUnsupportedModernVersionListsSupported(): void
    {
        $message = $this->modernMessage('tools/list', [], '1999-01-01');
        $request = $this->request([
            'MCP-Protocol-Version' => '1999-01-01',
            'Mcp-Method' => 'tools/list',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::UNSUPPORTED_VERSION, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
            $this->assertSame(
                ['supported' => Versions::MODERN, 'requested' => '1999-01-01'],
                $e->data(),
            );
        }
    }

    public function testProtocolVersionHeaderMismatch(): void
    {
        $message = $this->modernMessage('tools/list');
        $request = $this->request([
            'MCP-Protocol-Version' => '2099-01-01',
            'Mcp-Method' => 'tools/list',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::HEADER_MISMATCH, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
            $this->assertSame(
                "Header mismatch: MCP-Protocol-Version header value '2099-01-01' does not match body value '2026-07-28'",
                $e->getMessage(),
            );
        }
    }

    public function testProtocolVersionHeaderMissing(): void
    {
        $message = $this->modernMessage('tools/list');
        $request = $this->request([
            'Mcp-Method' => 'tools/list',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::HEADER_MISMATCH, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
            $this->assertSame(
                'Header mismatch: missing required MCP-Protocol-Version header',
                $e->getMessage(),
            );
        }
    }

    public function testMcpMethodMismatch(): void
    {
        $message = $this->modernMessage('tools/list');
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::HEADER_MISMATCH, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testMcpMethodHeaderMissing(): void
    {
        $message = $this->modernMessage('tools/list');
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::HEADER_MISMATCH, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testMcpNameBase64Sentinel(): void
    {
        $message = $this->modernMessage('tools/call', ['name' => 'get_email']);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => '=?base64?Z2V0X2VtYWls?=',
        ]);

        HeaderValidation::validateModern($request, $message);
        $this->addToAssertionCount(1);
    }

    public function testMcpNamePlainValueMatches(): void
    {
        $message = $this->modernMessage('tools/call', ['name' => 'get_email']);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'get_email',
        ]);

        HeaderValidation::validateModern($request, $message);
        $this->addToAssertionCount(1);
    }

    public function testMcpNameMismatchRejected(): void
    {
        $message = $this->modernMessage('tools/call', ['name' => 'get_email']);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'send_draft',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::HEADER_MISMATCH, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testMcpNameMissingForToolsCallRejected(): void
    {
        $message = $this->modernMessage('tools/call', ['name' => 'get_email']);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::HEADER_MISMATCH, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testMcpNameIgnoredForNonToolsCall(): void
    {
        $message = $this->modernMessage('tools/list', ['name' => 'irrelevant']);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ]);

        HeaderValidation::validateModern($request, $message);
        $this->addToAssertionCount(1);
    }

    public function testMcpNameCheckSkippedWhenParamsNameNotString(): void
    {
        $message = $this->modernMessage('tools/call', ['name' => 123]);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
        ]);

        HeaderValidation::validateModern($request, $message);
        $this->addToAssertionCount(1);
    }

    public function testSentinelInvalidBase64Rejected(): void
    {
        $message = $this->modernMessage('tools/call', ['name' => 'get_email']);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => '=?base64?###?=',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::HEADER_MISMATCH, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    public function testHeaderValuesAreTrimmed(): void
    {
        $message = $this->modernMessage('tools/call', ['name' => 'test_simple_text']);
        $request = $this->request([
            'MCP-Protocol-Version' => "\t2026-07-28\t",
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'test_simple_text  ',
        ]);

        HeaderValidation::validateModern($request, $message);
        $this->addToAssertionCount(1);
    }

    public function testMissingClientCapabilities(): void
    {
        $params = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']];
        $message = new Message(false, 1, 'tools/list', $params);
        $request = $this->request([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_PARAMS, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
            $this->assertSame(
                'Missing required _meta field: io.modelcontextprotocol/clientCapabilities',
                $e->getMessage(),
            );
        }
    }

    public function testValidationOrderVersionBeforeHeaders(): void
    {
        $message = $this->modernMessage('tools/list', [], '1999-01-01');
        $request = $this->request([]);

        try {
            HeaderValidation::validateModern($request, $message);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::UNSUPPORTED_VERSION, $e->getCode());
        }
    }

    public function testDecodeSentinelPassthroughForPlainValue(): void
    {
        $this->assertSame('plain-value', HeaderValidation::decodeSentinel('plain-value'));
    }

    public function testDecodeSentinelDecodesBase64(): void
    {
        $this->assertSame('get_email', HeaderValidation::decodeSentinel('=?base64?Z2V0X2VtYWls?='));
    }

    public function testLegacyUnsupportedHeaderVersion(): void
    {
        $request = $this->request(['MCP-Protocol-Version' => '2099-01-01']);

        try {
            HeaderValidation::validateLegacy($request);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(McpError::INVALID_REQUEST, $e->getCode());
            $this->assertSame(400, $e->httpStatus());
            $this->assertSame(
                ['supported' => Versions::LEGACY, 'requested' => '2099-01-01'],
                $e->data(),
            );
        }
    }

    public function testLegacyMissingHeaderAccepted(): void
    {
        $request = $this->request([]);

        HeaderValidation::validateLegacy($request);
        $this->addToAssertionCount(1);
    }

    public function testLegacySupportedHeaderAccepted(): void
    {
        $request = $this->request(['MCP-Protocol-Version' => Versions::LATEST_LEGACY]);

        HeaderValidation::validateLegacy($request);
        $this->addToAssertionCount(1);
    }
}
