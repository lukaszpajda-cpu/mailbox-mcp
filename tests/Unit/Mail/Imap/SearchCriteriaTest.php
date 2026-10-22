<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Imap;

use MailboxMcp\Mail\Imap\SearchCriteria;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\TestCase;

final class SearchCriteriaTest extends TestCase
{
    private const KEYWORD = '$ClaudeSeen';

    public function testAllWhenEmpty(): void
    {
        $criteria = SearchCriteria::fromArgs([], self::KEYWORD);

        $this->assertSame('ALL', $criteria->toImap());
        $this->assertFalse($criteria->needsCharset());
    }

    public function testCriteriaDateFormat(): void
    {
        $criteria = SearchCriteria::fromArgs(['since' => '2026-10-20'], self::KEYWORD);

        $this->assertSame('SINCE 20-Oct-2026', $criteria->toImap());

        $criteria = SearchCriteria::fromArgs(['since' => '2026-01-05'], self::KEYWORD);

        $this->assertSame('SINCE 5-Jan-2026', $criteria->toImap());
    }

    public function testCriteriaBeforeAndSinceOrdering(): void
    {
        $criteria = SearchCriteria::fromArgs(
            ['since' => '2026-10-20', 'before' => '2026-11-01'],
            self::KEYWORD,
        );

        $this->assertSame('SINCE 20-Oct-2026 BEFORE 1-Nov-2026', $criteria->toImap());
    }

    public function testCriteriaFullOrdering(): void
    {
        $criteria = SearchCriteria::fromArgs([
            'from' => 'a@example.com',
            'to' => 'b@example.com',
            'subject' => 'hello',
            'text' => 'world',
            'since' => '2026-10-20',
            'before' => '2026-11-01',
            'unseen_only' => true,
            'exclude_processed' => true,
        ], self::KEYWORD);

        $this->assertSame(
            'FROM "a@example.com" TO "b@example.com" SUBJECT "hello" TEXT "world" '
            . 'SINCE 20-Oct-2026 BEFORE 1-Nov-2026 UNSEEN NOT KEYWORD $ClaudeSeen',
            $criteria->toImap(),
        );
    }

    public function testCriteriaEscapesQuotes(): void
    {
        $criteria = SearchCriteria::fromArgs(['subject' => 'Say "Hello"'], self::KEYWORD);

        $this->assertSame('SUBJECT "Say \\"Hello\\""', $criteria->toImap());
    }

    public function testCriteriaEscapesBackslashes(): void
    {
        $criteria = SearchCriteria::fromArgs(['from' => 'C:\\Users\\x'], self::KEYWORD);

        $this->assertSame('FROM "C:\\\\Users\\\\x"', $criteria->toImap());
    }

    public function testCriteriaRejectsCrlf(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Search text contains forbidden characters');

        SearchCriteria::fromArgs(['subject' => "line1\r\nline2"], self::KEYWORD);
    }

    public function testExcludeProcessedAddsNotKeyword(): void
    {
        $criteria = SearchCriteria::fromArgs(['exclude_processed' => true], self::KEYWORD);

        $this->assertSame('NOT KEYWORD $ClaudeSeen', $criteria->toImap());
    }

    public function testUnseenOnlyAddsUnseen(): void
    {
        $criteria = SearchCriteria::fromArgs(['unseen_only' => true], self::KEYWORD);

        $this->assertSame('UNSEEN', $criteria->toImap());
    }

    public function testNeedsCharsetForNonAscii(): void
    {
        $this->assertTrue(SearchCriteria::fromArgs(['subject' => 'Zażółć'], self::KEYWORD)->needsCharset());
        $this->assertFalse(SearchCriteria::fromArgs(['subject' => 'hello'], self::KEYWORD)->needsCharset());
        $this->assertFalse(SearchCriteria::fromArgs([], self::KEYWORD)->needsCharset());
    }

    public function testInvalidDateRejected(): void
    {
        try {
            SearchCriteria::fromArgs(['since' => '2026-13-01'], self::KEYWORD);
            $this->fail('expected ToolException for invalid month');
        } catch (ToolException $e) {
            $this->assertSame('Invalid date: 2026-13-01', $e->getMessage());
        }

        try {
            SearchCriteria::fromArgs(['before' => '2026-02-30'], self::KEYWORD);
            $this->fail('expected ToolException for invalid day-of-month');
        } catch (ToolException $e) {
            $this->assertSame('Invalid date: 2026-02-30', $e->getMessage());
        }

        try {
            SearchCriteria::fromArgs(['since' => '20261020'], self::KEYWORD);
            $this->fail('expected ToolException for malformed date');
        } catch (ToolException $e) {
            $this->assertSame('Invalid date: 20261020', $e->getMessage());
        }
    }

    public function testQuoteEscapesAndRejects(): void
    {
        $this->assertSame('"simple"', SearchCriteria::quote('simple'));
        $this->assertSame('"he said \\"hi\\""', SearchCriteria::quote('he said "hi"'));

        foreach (["bad\0char", "bad\rchar", "bad\nchar"] as $bad) {
            try {
                SearchCriteria::quote($bad);
                $this->fail('expected ToolException for forbidden character');
            } catch (ToolException $e) {
                $this->assertSame('Search text contains forbidden characters', $e->getMessage());
            }
        }

        try {
            SearchCriteria::quote(str_repeat('a', 999));
            $this->fail('expected ToolException for over-length string');
        } catch (ToolException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Exactly at the limit must still be accepted.
        $atLimit = str_repeat('a', 998);
        $this->assertSame('"' . $atLimit . '"', SearchCriteria::quote($atLimit));
    }

    public function testInvalidKeywordAtomRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SearchCriteria::fromArgs([], 'not a valid keyword!');
    }
}
