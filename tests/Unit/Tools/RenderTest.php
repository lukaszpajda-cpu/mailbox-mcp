<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use MailboxMcp\Tools\Render;
use PHPUnit\Framework\TestCase;

final class RenderTest extends TestCase
{
    public function testDatePrefersHeaderDate(): void
    {
        $this->assertSame(
            '2026-10-20T10:00:00+02:00',
            Render::date('Tue, 20 Oct 2026 10:00:00 +0200', '19-Oct-2026 08:00:00 +0000'),
        );
    }

    public function testDateFallsBackToInternalDateWhenHeaderInvalid(): void
    {
        $this->assertSame(
            '2026-10-19T08:00:00+00:00',
            Render::date('not a date', '19-Oct-2026 08:00:00 +0000'),
        );
    }

    public function testDateFallsBackToInternalDateWhenHeaderMissing(): void
    {
        $this->assertSame(
            '2026-10-19T08:00:00+00:00',
            Render::date(null, '19-Oct-2026 08:00:00 +0000'),
        );
    }

    public function testDateIsUnknownWhenBothMissingOrUnparsable(): void
    {
        $this->assertSame('unknown', Render::date(null, null));
        $this->assertSame('unknown', Render::date('not a date', null));
        $this->assertSame('unknown', Render::date(null, 'not an internaldate'));
    }

    public function testDateAcceptsSpacePaddedInternalDateDay(): void
    {
        $this->assertSame(
            '2026-10-05T10:00:00+00:00',
            Render::date(null, ' 5-Oct-2026 10:00:00 +0000'),
        );
    }

    public function testFlagsNoneWhenEmpty(): void
    {
        $this->assertSame('none', Render::flags([]));
    }

    public function testFlagsSpaceJoined(): void
    {
        $this->assertSame('\\Seen $ClaudeSeen', Render::flags(['\\Seen', '$ClaudeSeen']));
    }

    public function testMessageBlockWithHeaders(): void
    {
        $result = Render::messageBlock(
            ['date' => '2026-10-20T10:00:00+02:00', 'flags' => 'none', 'size' => 2048, 'attachments' => 0],
            ['account' => 'default', 'folder' => 'INBOX', 'uid' => '4711'],
            ['From' => 'Alice <alice@example.com>', 'To' => 'Bob <bob@example.com>', 'Subject' => 'Hello'],
            'Hi there',
        );

        $expected = implode("\n", [
            'date: 2026-10-20T10:00:00+02:00',
            'flags: none',
            'size: 2048',
            'attachments: 0',
            '<email_content untrusted="true" account="default" folder="INBOX" uid="4711">',
            'From: Alice <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello',
            '---',
            'Hi there',
            '</email_content>',
        ]);

        $this->assertSame($expected, $result);
    }

    public function testMessageBlockWithNoHeadersHasNoLeadingBlankLine(): void
    {
        $result = Render::messageBlock(
            ['uid' => 1],
            ['account' => 'default'],
            [],
            'body text',
        );

        $expected = implode("\n", [
            'uid: 1',
            '<email_content untrusted="true" account="default">',
            '---',
            'body text',
            '</email_content>',
        ]);

        $this->assertSame($expected, $result);
    }
}
