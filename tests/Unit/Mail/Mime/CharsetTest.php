<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Mime;

use MailboxMcp\Mail\Mime\Charset;
use PHPUnit\Framework\TestCase;

final class CharsetTest extends TestCase
{
    public function testUtf8PassesThroughUnchanged(): void
    {
        $this->assertSame('Zażółć', Charset::toUtf8('Zażółć', 'UTF-8'));
        $this->assertSame('Zażółć', Charset::toUtf8('Zażółć', 'utf8'));
    }

    public function testAsciiPassesThroughUnchanged(): void
    {
        $this->assertSame('Hello', Charset::toUtf8('Hello', 'US-ASCII'));
        $this->assertSame('Hello', Charset::toUtf8('Hello', 'ascii'));
        $this->assertSame('Hello', Charset::toUtf8('Hello', ''));
    }

    public function testConvertsIso88592(): void
    {
        $bytes = "Zam\xF3wienie"; // ISO-8859-2 bytes for "Zamówienie"

        $this->assertSame('Zamówienie', Charset::toUtf8($bytes, 'ISO-8859-2'));
        $this->assertSame('Zamówienie', Charset::toUtf8($bytes, 'iso-8859-2'));
    }

    public function testCharsetAliasCp1250(): void
    {
        $utf8 = 'Zażółć gęślą jaźń';
        $bytes = iconv('UTF-8', 'Windows-1250', $utf8);
        self::assertIsString($bytes);

        $this->assertSame($utf8, Charset::toUtf8($bytes, 'cp1250'));
        $this->assertSame($utf8, Charset::toUtf8($bytes, 'CP1250'));
    }

    public function testCharsetAliasLatin1AndLatin2(): void
    {
        $latin1Bytes = iconv('UTF-8', 'ISO-8859-1', 'café');
        self::assertIsString($latin1Bytes);
        $this->assertSame('café', Charset::toUtf8($latin1Bytes, 'latin1'));

        $latin2Bytes = "Zam\xF3wienie";
        $this->assertSame('Zamówienie', Charset::toUtf8($latin2Bytes, 'latin2'));
    }

    public function testUnknownCharsetReturnsInputUnchanged(): void
    {
        $this->assertSame('whatever', Charset::toUtf8('whatever', 'totally-bogus-charset'));
    }
}
