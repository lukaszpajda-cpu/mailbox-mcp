<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Mime;

use MailboxMcp\Mail\Mime\BodyStructure;
use MailboxMcp\Mail\Mime\Part;
use MailboxMcp\Mail\Mime\Transfer;
use PHPUnit\Framework\TestCase;

final class BodyStructureTest extends TestCase
{
    private static function fixture(string $name): string
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/mime/bodystructure-' . $name . '.txt');
        self::assertIsString($raw);

        return $raw;
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<string>
     */
    private static function sections(array $parts): array
    {
        return array_map(static fn (Part $p): string => $p->section, $parts);
    }

    public function testSinglePartBodyStructure(): void
    {
        $root = BodyStructure::parse(self::fixture('plain-utf8'));

        $this->assertSame('1', $root->section);
        $this->assertSame('text/plain', $root->mime());
        $this->assertSame('utf-8', $root->charset);
        $this->assertSame('8bit', $root->encoding);
        $this->assertSame(105, $root->size);
        $this->assertTrue($root->isText());
        $this->assertFalse($root->isAttachment());
        $this->assertSame([], $root->children);
        $this->assertSame($root, BodyStructure::firstText($root));
        $this->assertSame([], BodyStructure::attachments($root));
    }

    public function testAlternativeWithAttachmentSections(): void
    {
        $root = BodyStructure::parse(self::fixture('alternative-with-attachment'));

        $this->assertSame('multipart/mixed', $root->mime());
        $this->assertCount(2, $root->children);

        $alt = $root->children[0];
        $this->assertSame('1', $alt->section);
        $this->assertSame('multipart/alternative', $alt->mime());
        $this->assertSame('1.1', $alt->children[0]->section);
        $this->assertSame('text/plain', $alt->children[0]->mime());
        $this->assertSame('1.2', $alt->children[1]->section);
        $this->assertSame('text/html', $alt->children[1]->mime());

        $pdf = $root->children[1];
        $this->assertSame('2', $pdf->section);
        $this->assertSame('application/pdf', $pdf->mime());
        $this->assertSame('invoice.pdf', $pdf->filename);
        $this->assertSame('attachment', $pdf->disposition);
        $this->assertSame('base64', $pdf->encoding);
        $this->assertSame(100, $pdf->size);
        $this->assertTrue($pdf->isAttachment());

        $attachments = BodyStructure::attachments($root);
        $this->assertSame(['2'], self::sections($attachments));
    }

    public function testFirstTextPrefersPlain(): void
    {
        $root = BodyStructure::parse(self::fixture('alternative-with-attachment'));
        $text = BodyStructure::firstText($root);
        $this->assertNotNull($text);
        $this->assertSame('1.1', $text->section);
        $this->assertSame('text/plain', $text->mime());

        $htmlFirst = BodyStructure::parse(
            '(("text" "html" ("charset" "utf-8") NIL NIL "7bit" 10 1 NIL NIL NIL NIL)'
            . '("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 10 1 NIL NIL NIL NIL) "alternative")'
        );
        $this->assertSame('2', BodyStructure::firstText($htmlFirst)?->section);

        $htmlOnly = BodyStructure::parse(self::fixture('html-only-windows1250'));
        $this->assertSame('1', BodyStructure::firstText($htmlOnly)?->section);
        $this->assertSame('windows-1250', $htmlOnly->charset);

        $noText = BodyStructure::parse('("image" "png" NIL NIL NIL "base64" 10 NIL NIL NIL NIL)');
        $this->assertNull(BodyStructure::firstText($noText));
    }

    public function testNestedMultipartAttachments(): void
    {
        $root = BodyStructure::parse(self::fixture('nested-multipart'));

        $related = $root->children[0];
        $this->assertSame('multipart/related', $related->mime());
        $this->assertSame('text/html', $related->children[0]->mime());
        $this->assertSame('1.1', $related->children[0]->section);
        $this->assertSame('1.2', $related->children[1]->section);
        $this->assertSame('inline', $related->children[1]->disposition);

        $attachments = BodyStructure::attachments($root);
        $this->assertSame(['1.2', '2', '3'], self::sections($attachments));
        $this->assertSame(
            ['logo.png', 'data.csv', 'notes.bin'],
            array_map(static fn (Part $p): ?string => $p->filename, $attachments),
        );

        $this->assertSame('1.1', BodyStructure::firstText($root)?->section);
    }

    public function testRfc2231Filename(): void
    {
        $root = BodyStructure::parse(self::fixture('rfc2231-filename'));
        $attachments = BodyStructure::attachments($root);

        $this->assertCount(1, $attachments);
        $this->assertSame('zamówienie.pdf', $attachments[0]->filename);

        // Content-Type `name` with an RFC 2047 encoded word is decoded too.
        $byName = BodyStructure::parse(
            '("application" "pdf" ("name" "=?UTF-8?Q?zam=C3=B3wienie.pdf?=") NIL NIL "base64" 100 NIL NIL NIL NIL)'
        );
        $this->assertSame('zamówienie.pdf', $byName->filename);
        $this->assertTrue($byName->isAttachment());
    }

    public function testBodyStructureWithExtensionFieldsAndNil(): void
    {
        $root = BodyStructure::parse(
            '("TEXT" "PLAIN" ("CHARSET" "UTF-8" "FORMAT" "fl\\"owed") NIL "a \\"quoted\\" desc" "QUOTED-PRINTABLE" 42 3'
            . ' "md5sum" NIL "pl" "http://example.com/x" "unknown" (1 2 (3 "x")) NIL)'
        );

        $this->assertSame('1', $root->section);
        $this->assertSame('text/plain', $root->mime());
        $this->assertSame('utf-8', $root->charset);
        $this->assertSame('fl"owed', $root->params['format']);
        $this->assertSame('quoted-printable', $root->encoding);
        $this->assertSame(42, $root->size);
        $this->assertNull($root->disposition);
        $this->assertNull($root->filename);

        $nilCharset = BodyStructure::parse('("text" "plain" NIL NIL NIL "7bit" 0 0)');
        $this->assertSame('us-ascii', $nilCharset->charset);
        $this->assertSame([], $nilCharset->params);

        $multi = BodyStructure::parse(
            '(("text" "plain" NIL NIL NIL "7bit" 1 1)("application" "zip" NIL NIL NIL "base64" 9 NIL) "mixed"'
            . ' ("boundary" "zz") ("inline" NIL) "en" "loc" "future" (data))'
        );
        $this->assertSame('multipart/mixed', $multi->mime());
        $this->assertSame('zz', $multi->params['boundary']);
        $this->assertSame(['2'], self::sections(BodyStructure::attachments($multi)));
    }

    public function testMessageRfc822PartIsLeafAttachment(): void
    {
        $root = BodyStructure::parse(
            '(("text" "plain" ("charset" "us-ascii") NIL NIL "7bit" 5 1 NIL NIL NIL NIL)'
            . '("message" "rfc822" ("name" "fwd.eml") NIL NIL "7bit" 500'
            . ' ("Tue, 20 Oct 2026 10:00:00 +0000" "Inner (subject)" (("A" NIL "a" "example.com")) NIL NIL NIL NIL NIL NIL "<i@example.com>")'
            . ' ("text" "plain" ("charset" "us-ascii") NIL NIL "7bit" 10 1 NIL NIL NIL NIL) 12 NIL NIL NIL NIL)'
            . ' "mixed" ("boundary" "x") NIL NIL NIL)'
        );

        $msg = $root->children[1];
        $this->assertSame('2', $msg->section);
        $this->assertSame('message/rfc822', $msg->mime());
        $this->assertSame([], $msg->children);
        $this->assertSame(500, $msg->size);
        $this->assertSame('fwd.eml', $msg->filename);
        $this->assertTrue($msg->isAttachment());
        $this->assertSame(['2'], self::sections(BodyStructure::attachments($root)));
        $this->assertSame('1', BodyStructure::firstText($root)?->section);
    }

    public function testInlineNonTextWithoutFilenameIsNotAttachment(): void
    {
        $root = BodyStructure::parse('("image" "gif" NIL "<c@example.com>" NIL "base64" 10 NIL ("inline" NIL) NIL NIL)');
        $this->assertFalse($root->isAttachment());

        $textAttachment = BodyStructure::parse('("text" "plain" NIL NIL NIL "7bit" 1 1 NIL ("attachment" NIL) NIL NIL)');
        $this->assertTrue($textAttachment->isAttachment());
        $this->assertNull(BodyStructure::firstText($textAttachment));
    }

    public function testMalformedInputDoesNotThrow(): void
    {
        foreach (['', '(', ')', '((((', '("text"', '"x" "y"', "\x00\xff\xfe(\"a", 'NIL', '(() "mixed")', '("text" "plain" ("charset") NIL NIL NIL x y'] as $raw) {
            $part = BodyStructure::parse($raw);
            $this->assertInstanceOf(Part::class, $part);
            BodyStructure::attachments($part);
            BodyStructure::firstText($part);
        }

        $deep = str_repeat('(', 5000) . str_repeat(')', 5000);
        $this->assertInstanceOf(Part::class, BodyStructure::parse($deep));
    }

    public function testTryParseRejectsGarbage(): void
    {
        foreach (['(((garbage', '', 'garbage'] as $raw) {
            $this->assertNull(BodyStructure::tryParse($raw));
        }

        $part = BodyStructure::tryParse(self::fixture('plain-utf8'));
        $this->assertInstanceOf(Part::class, $part);
        $this->assertSame('text/plain', $part->mime());
    }

    public function testTransferDecode(): void
    {
        $this->assertSame('hello world', Transfer::decode("aGVsbG8g\r\nd29ybGQ=", 'BASE64'));
        $this->assertSame('zażółć', Transfer::decode('za=C5=BC=C3=B3=C5=82=C4=87', 'Quoted-Printable'));
        $this->assertSame("a\r\nb", Transfer::decode("a\r\nb", '7bit'));
        $this->assertSame('raw', Transfer::decode('raw', '8BIT'));
        $this->assertSame('raw', Transfer::decode('raw', 'binary'));
        $this->assertSame('raw', Transfer::decode('raw', ''));
        $this->assertSame('raw', Transfer::decode('raw', 'x-unknown'));
        $this->assertSame('', Transfer::decode("!!!\x00\xff@@", 'base64'));
        $this->assertSame('hi', Transfer::decode('aGk=x', 'base64'));
    }
}
