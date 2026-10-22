<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Draft;

use DateTimeImmutable;
use MailboxMcp\Accounts\Identity;
use MailboxMcp\Mail\Draft\Builder;
use MailboxMcp\Mail\Mime\Parser;
use MailboxMcp\Support\FrozenClock;
use MailboxMcp\Version;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    private function builder(): Builder
    {
        return new Builder(
            new Identity('me@example.com', 'Me'),
            new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+02:00')),
        );
    }

    /**
     * @return array{0: array<int, string>, 1: string}
     */
    private function splitHeaders(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        $this->assertIsInt($pos);

        $headerBlock = substr($raw, 0, $pos);
        $body = substr($raw, $pos + 4);

        return [explode("\r\n", $headerBlock), $body];
    }

    public function testPlainDraftHeadersAndQp(): void
    {
        $raw = $this->builder()->build(
            ['a@b.com', 'c@d.com'],
            [],
            'Zażółć',
            "Hello\nZażółć",
            false,
            null,
            [],
            '',
        );

        [$lines, $body] = $this->splitHeaders($raw);

        $this->assertSame('From: Me <me@example.com>', $lines[0]);
        $this->assertSame('To: a@b.com, c@d.com', $lines[1]);
        $this->assertSame('Subject: =?UTF-8?B?' . base64_encode('Zażółć') . '?=', $lines[2]);
        $this->assertSame('Date: Thu, 22 Oct 2026 10:00:00 +0200', $lines[3]);
        $this->assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{16}\.\d+@example\.com>$/', $lines[4]);
        $this->assertSame('MIME-Version: 1.0', $lines[5]);
        $this->assertSame('User-Agent: mailbox-mcp/' . Version::STRING, $lines[6]);
        $this->assertSame('Content-Type: text/plain; charset=UTF-8', $lines[7]);
        $this->assertSame('Content-Transfer-Encoding: quoted-printable', $lines[8]);
        $this->assertCount(9, $lines);

        $this->assertSame(quoted_printable_encode("Hello\r\nZażółć"), $body);
    }

    public function testReplyHeaders(): void
    {
        $subject = Builder::replySubject('Hello');

        $raw = $this->builder()->build(
            ['a@b.com'],
            [],
            $subject,
            'Body',
            false,
            '<orig@x.com>',
            ['<ref1@x.com>', '<ref2@x.com>'],
            '',
        );

        [$lines] = $this->splitHeaders($raw);

        $this->assertContains('In-Reply-To: <orig@x.com>', $lines);
        $this->assertContains('References: <ref1@x.com> <ref2@x.com>', $lines);

        $subjectLines = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Subject:')));
        $this->assertSame(['Subject: Re: Hello'], $subjectLines);
        $this->assertSame(1, substr_count($raw, 'Re:'));
    }

    public function testCcHeaderOmittedWhenEmpty(): void
    {
        $raw = $this->builder()->build(['a@b.com'], [], 'Hi', 'Body', false, null, [], '');

        $this->assertStringNotContainsString('Cc:', $raw);
    }

    public function testCcHeaderPresentWhenNonEmpty(): void
    {
        $raw = $this->builder()->build(['a@b.com'], ['c@d.com', 'e@f.com'], 'Hi', 'Body', false, null, [], '');

        [$lines] = $this->splitHeaders($raw);
        $this->assertSame('Cc: c@d.com, e@f.com', $lines[2]);
    }

    public function testReplySubjectKeepsExistingPrefixes(): void
    {
        foreach (['Re: x', 're: x', 'Odp: x', 'odp:x', 'Aw: x', 'Sv: x', 'Fwd: x', 'Fw: x', '  Re : x'] as $subject) {
            $this->assertSame($subject, Builder::replySubject($subject), "subject: {$subject}");
        }
    }

    public function testReplySubjectAddsPrefixWhenMissing(): void
    {
        $this->assertSame('Re: Hello', Builder::replySubject('Hello'));
        $this->assertSame('Re: Hello', Builder::replySubject('  Hello  '));
    }

    public function testMessageIdFormat(): void
    {
        $id = Builder::messageId('example.com');

        $this->assertMatchesRegularExpression('/^<[0-9a-f]{16}\.\d+@example\.com>$/', $id);
    }

    public function testSignatureAppendedWithSeparatorPlain(): void
    {
        $raw = $this->builder()->build(['a@b.com'], [], 'Hi', 'Body text', false, null, [], "Best,\nMe");

        [, $body] = $this->splitHeaders($raw);

        $expected = quoted_printable_encode("Body text\r\n\r\n-- \r\nBest,\r\nMe");
        $this->assertSame($expected, $body);
    }

    public function testHtmlAlternative(): void
    {
        $raw = $this->builder()->build(
            ['a@b.com'],
            [],
            'Hi',
            '<p>Hello <b>World</b></p><script>bad()</script>',
            true,
            null,
            [],
            '',
        );

        $this->assertMatchesRegularExpression(
            '/Content-Type: multipart\/alternative; boundary="([^"]+)"/',
            $raw,
            'declares a multipart/alternative boundary',
        );
        preg_match('/boundary="([^"]+)"/', $raw, $m);
        $boundary = $m[1] ?? '';
        $this->assertNotSame('', $boundary);

        $this->assertSame(3, substr_count($raw, '--' . $boundary), 'two part openers plus the closing delimiter');
        $this->assertStringContainsString('--' . $boundary . "--", $raw);

        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $raw);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $raw);
        $this->assertSame(2, substr_count($raw, 'Content-Transfer-Encoding: quoted-printable'));

        $textPart = quoted_printable_encode("Hello World");
        $this->assertStringContainsString($textPart, $raw);

        $htmlPart = quoted_printable_encode('<p>Hello <b>World</b></p>');
        $this->assertStringContainsString($htmlPart, $raw);
        $this->assertStringNotContainsString('bad()', $raw);
        $this->assertStringNotContainsString('<script', $raw);
    }

    public function testHtmlSignatureAppendedToBothParts(): void
    {
        $raw = $this->builder()->build(
            ['a@b.com'],
            [],
            'Hi',
            '<p>Hello</p>',
            true,
            null,
            [],
            "Best,\nMe & you",
        );

        $textPart = quoted_printable_encode("Hello\r\n\r\n-- \r\nBest,\r\nMe & you");
        $this->assertStringContainsString($textPart, $raw);

        $htmlPart = quoted_printable_encode('<p>Hello</p><br><br>-- <br>Best,<br>Me &amp; you');
        $this->assertStringContainsString($htmlPart, $raw);
    }

    public function testReferencesCappedAtTwentyDeduplicated(): void
    {
        $refs = [];

        for ($i = 1; $i <= 25; $i++) {
            $refs[] = "<ref{$i}@x.com>";
        }

        $refs[] = '<ref1@x.com>'; // duplicate of the oldest one
        $refs[] = '<orig@x.com>';

        $raw = $this->builder()->build(['a@b.com'], [], 'Hi', 'Body', false, '<orig@x.com>', $refs, '');

        // A References header this long is folded across several
        // physical lines (see HeaderEncoder::fold()); Parser::parse()
        // reassembles it the same way a real client would.
        $ids = Parser::parse($raw)->references();

        $this->assertCount(20, $ids);
        $this->assertSame('<orig@x.com>', $ids[count($ids) - 1]);
        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function testLongSubjectIsFolded(): void
    {
        $words = array_map(static fn (int $i): string => 'word' . $i, range(1, 150));
        $subject = implode(' ', $words);
        $this->assertGreaterThan(900, strlen($subject));

        $raw = $this->builder()->build(['a@b.com'], [], $subject, 'Body', false, null, [], '');

        [$lines] = $this->splitHeaders($raw);

        foreach ($lines as $i => $line) {
            $this->assertLessThanOrEqual(998, strlen($line), "header line {$i} exceeds RFC 5322's 998-byte hard limit");
        }

        $this->assertGreaterThan(1, count(array_filter(
            $lines,
            static fn (string $l): bool => str_starts_with($l, 'Subject:') || (str_starts_with($l, ' ') && str_contains($l, 'word')),
        )), 'the subject must actually have been folded across several physical lines');

        $this->assertSame($subject, Parser::parse($raw)->header('Subject'));
    }

    public function testLongReferencesFolded(): void
    {
        $refs = [];

        for ($i = 1; $i <= 15; $i++) {
            $refs[] = "<reference-id-number-{$i}.1234567890@mail.example.com>";
        }

        $raw = $this->builder()->build(['a@b.com'], [], 'Hi', 'Body', false, '<orig@x.com>', $refs, '');

        [$lines] = $this->splitHeaders($raw);

        foreach ($lines as $i => $line) {
            $this->assertLessThanOrEqual(998, strlen($line), "header line {$i} exceeds RFC 5322's 998-byte hard limit");
        }

        $referencesPhysicalLines = array_filter(
            $lines,
            static fn (string $l): bool => str_starts_with($l, 'References:') || (str_starts_with($l, ' ') && str_contains($l, 'reference-id-number')),
        );
        $this->assertGreaterThan(1, count($referencesPhysicalLines), 'References must actually have been folded across several physical lines');

        // Builder::build() renders exactly the given $references list (the
        // tool, not Builder, is responsible for folding the original
        // Message-ID into it before calling build()); $inReplyTo only
        // affects the separate In-Reply-To header.
        $this->assertSame($refs, Parser::parse($raw)->references());
    }

    public function testBuildIsValidMime(): void
    {
        $raw = $this->builder()->build(['a@b.com'], [], 'Hi', 'Body', false, null, [], '');

        $parsed = Parser::parse($raw);
        $this->assertSame('a@b.com', $parsed->addresses('To')[0]->email);
    }
}
