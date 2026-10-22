<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Protocol;

use MailboxMcp\Protocol\Era;
use PHPUnit\Framework\TestCase;

final class EraTest extends TestCase
{
    public function testEraModernWhenMetaVersionString(): void
    {
        $era = Era::detect([
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            ],
        ]);

        $this->assertSame(Era::Modern, $era);
    }

    public function testEraModernEvenWhenVersionUnsupported(): void
    {
        $era = Era::detect([
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => 'not-a-real-version',
            ],
        ]);

        $this->assertSame(Era::Modern, $era);
    }

    public function testEraLegacyOtherwise(): void
    {
        $this->assertSame(Era::Legacy, Era::detect([]));
        $this->assertSame(Era::Legacy, Era::detect(['_meta' => []]));
        $this->assertSame(Era::Legacy, Era::detect([
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => 123],
        ]));
        $this->assertSame(Era::Legacy, Era::detect([
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => null],
        ]));
        $this->assertSame(Era::Legacy, Era::detect(['_meta' => 'not-an-array']));
    }

    public function testEraModernFromHeaderWhenBodyLacksMeta(): void
    {
        $era = Era::detect([], '2026-07-28');

        $this->assertSame(Era::Modern, $era);
    }

    public function testEraLegacyWhenHeaderIsLegacyAndBodyLacksMeta(): void
    {
        $era = Era::detect([], '2025-11-25');

        $this->assertSame(Era::Legacy, $era);
    }
}
