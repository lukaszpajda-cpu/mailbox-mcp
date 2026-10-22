<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Mime;

use MailboxMcp\Mail\Mime\HeaderDecoder;
use PHPUnit\Framework\TestCase;

final class HeaderDecoderTest extends TestCase
{
    public function testDecodesQEncodedIso88592(): void
    {
        $this->assertSame(
            'Zamówienie',
            HeaderDecoder::decode('=?ISO-8859-2?Q?Zam=F3wienie?='),
        );
    }

    public function testDecodesBEncodedUtf8(): void
    {
        $encoded = '=?UTF-8?B?' . base64_encode('Zażółć gęślą jaźń') . '?=';

        $this->assertSame('Zażółć gęślą jaźń', HeaderDecoder::decode($encoded));
    }

    public function testJoinsAdjacentEncodedWords(): void
    {
        // "Zam\xF3wienie" (ISO-8859-2) split across two encoded words with
        // only folding whitespace between them must join without a space.
        $header = '=?ISO-8859-2?Q?Zam=F3?= =?ISO-8859-2?Q?wienie?=';

        $this->assertSame('Zamówienie', HeaderDecoder::decode($header));
    }

    public function testUnfoldsContinuationLines(): void
    {
        $this->assertSame('Hello World', HeaderDecoder::decode("Hello\r\n World"));
    }

    public function testPlainAsciiPassesThroughUnchanged(): void
    {
        $this->assertSame('Just a subject line', HeaderDecoder::decode('Just a subject line'));
    }

    public function testCollapsesInternalWhitespaceAndTrims(): void
    {
        $this->assertSame('a b', HeaderDecoder::decode("  a   b  \t "));
    }

    public function testInvalidBase64WordLeftAsIs(): void
    {
        $header = '=?UTF-8?B?not valid base64?=';

        $this->assertSame($header, HeaderDecoder::decode($header));
    }

    public function testInvalidQEscapeLeftAsIs(): void
    {
        $header = '=?UTF-8?Q?bad=ZZescape?=';

        $this->assertSame($header, HeaderDecoder::decode($header));
    }

    public function testQEncodingUnderscoreIsSpace(): void
    {
        $this->assertSame('Hello World', HeaderDecoder::decode('=?UTF-8?Q?Hello_World?='));
    }

    public function testCaseInsensitiveEncodingLetter(): void
    {
        $this->assertSame(
            'Zamówienie',
            HeaderDecoder::decode('=?ISO-8859-2?q?Zam=F3wienie?='),
        );
    }
}
