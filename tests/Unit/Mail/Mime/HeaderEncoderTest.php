<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Mime;

use MailboxMcp\Mail\Mime\Address;
use MailboxMcp\Mail\Mime\HeaderDecoder;
use MailboxMcp\Mail\Mime\HeaderEncoder;
use PHPUnit\Framework\TestCase;

final class HeaderEncoderTest extends TestCase
{
    public function testEncodeAsciiUnchanged(): void
    {
        $this->assertSame('Just a subject line', HeaderEncoder::encode('Just a subject line'));
    }

    public function testEncodeUtf8Base64(): void
    {
        $this->assertSame(
            '=?UTF-8?B?' . base64_encode('Zażółć') . '?=',
            HeaderEncoder::encode('Zażółć'),
        );
    }

    public function testEncodeSplitsLongUtf8OnCharBoundary(): void
    {
        // 60 characters, every one a 2-byte UTF-8 Polish letter, so the
        // 45-bytes-per-chunk budget forces at least two encoded words.
        $value = str_repeat('ąćęłńóśźż', 6) . 'ąćęłńó';
        $this->assertSame(60, mb_strlen($value, 'UTF-8'));

        $encoded = HeaderEncoder::encode($value);
        $words = explode(' ', $encoded);

        $this->assertGreaterThanOrEqual(2, count($words));

        foreach ($words as $word) {
            $this->assertLessThanOrEqual(75, strlen($word));
            $this->assertMatchesRegularExpression('/^=\?UTF-8\?B\?[A-Za-z0-9+\/]*={0,2}\?=$/', $word);
        }

        $this->assertSame($value, HeaderDecoder::decode($encoded));
    }

    public function testNameAddrEmptyNameReturnsJustEmail(): void
    {
        $this->assertSame('a@b.com', HeaderEncoder::nameAddr('', 'a@b.com'));
    }

    public function testNameAddrPlainAsciiName(): void
    {
        $this->assertSame('Example Co <a@b.com>', HeaderEncoder::nameAddr('Example Co', 'a@b.com'));
    }

    public function testNameAddrAsciiWithSpecialsIsQuotedAndEscaped(): void
    {
        $this->assertSame(
            '"Doe, \\"Johnny\\" \\\\Smith" <a@b.com>',
            HeaderEncoder::nameAddr('Doe, "Johnny" \\Smith', 'a@b.com'),
        );
    }

    public function testNameAddrNonAsciiIsEncodedWord(): void
    {
        $this->assertSame(
            '=?UTF-8?B?' . base64_encode('Łukasz') . '?= <a@b.com>',
            HeaderEncoder::nameAddr('Łukasz', 'a@b.com'),
        );
    }

    public function testFoldAddressListJoinsWithCommaSpace(): void
    {
        $addrs = [
            new Address('a@b.com', 'A One'),
            new Address('c@d.com', null),
        ];

        $this->assertSame(
            'A One <a@b.com>, c@d.com',
            HeaderEncoder::foldAddressList($addrs),
        );
    }

    public function testFoldAddressListAcceptsEmailNamePairs(): void
    {
        $addrs = [
            ['a@b.com', 'A One'],
            ['c@d.com'],
        ];

        $this->assertSame(
            'A One <a@b.com>, c@d.com',
            HeaderEncoder::foldAddressList($addrs),
        );
    }

    public function testFoldReturnsShortLineUnchanged(): void
    {
        $this->assertSame('Subject: Hi', HeaderEncoder::fold('Subject: Hi'));
    }

    public function testFoldBreaksAtSpaceBoundaryUnderSoftLimit(): void
    {
        $words = array_map(static fn (int $i): string => "word{$i}", range(1, 30));
        $line = 'Subject: ' . implode(' ', $words);

        $folded = HeaderEncoder::fold($line);
        $lines = explode("\r\n", $folded);

        $this->assertGreaterThan(1, count($lines));

        foreach ($lines as $i => $physicalLine) {
            $this->assertLessThanOrEqual(78, strlen($physicalLine), "line {$i} exceeds the soft limit");

            if ($i > 0) {
                $this->assertStringStartsWith(' ', $physicalLine, "continuation line {$i} must start with a space");
            }
        }

        // Folding is reconstructable by plain concatenation (no bytes
        // invented or lost), exactly how Parser::parseHeaderBlock()
        // reassembles a folded header value.
        $this->assertSame($line, implode('', $lines));
    }

    public function testFoldNeverExceedsHardLimit(): void
    {
        $line = 'References: ' . implode(' ', array_fill(0, 5, str_repeat('a', 200)));
        $folded = HeaderEncoder::fold($line);

        foreach (explode("\r\n", $folded) as $physicalLine) {
            $this->assertLessThanOrEqual(998, strlen($physicalLine));
        }
    }

    public function testFoldForceSplitsASpaceFreeTokenLongerThanHardLimit(): void
    {
        $line = 'Subject: ' . str_repeat('a', 2000);
        $folded = HeaderEncoder::fold($line);

        foreach (explode("\r\n", $folded) as $physicalLine) {
            $this->assertLessThanOrEqual(998, strlen($physicalLine));
        }

        $this->assertGreaterThan(1, count(explode("\r\n", $folded)));
    }
}
