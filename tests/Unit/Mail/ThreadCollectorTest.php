<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail;

use MailboxMcp\Mail\ThreadCollector;
use MailboxMcp\Tests\Support\FakeImapClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ThreadCollectorTest extends TestCase
{
    /**
     * @param array<int, string> $references
     *
     * @return array{flags: array<int, string>, size: int, internaldate: ?string, headers: string, bodystructure: string}
     */
    private function summary(string $messageId, ?string $inReplyTo = null, array $references = []): array
    {
        $headers = "From: a@x\r\nTo: b@x\r\nSubject: s\r\nMessage-ID: {$messageId}";

        if (null !== $inReplyTo) {
            $headers .= "\r\nIn-Reply-To: {$inReplyTo}";
        }

        if ([] !== $references) {
            $headers .= "\r\nReferences: " . implode(' ', $references);
        }

        return [
            'flags' => [],
            'size' => 10,
            'internaldate' => '20-Oct-2026 08:00:00 +0000',
            'headers' => $headers,
            'bodystructure' => '("text" "plain" ("charset" "UTF-8") NIL NIL "7bit" 1 1 NIL NIL NIL NIL)',
        ];
    }

    public function testThreadCollectsAcrossFolders(): void
    {
        $imap = new FakeImapClient();
        $imap->summaries[1] = $this->summary('<start@x>');
        $imap->summaries[2] = $this->summary('<reply@x>', '<start@x>');
        $imap->summaries[3] = $this->summary('<replysent@x>', '<start@x>');

        $imap->searchResults['HEADER In-Reply-To "<start@x>"'] = [2, 3];

        $collector = new ThreadCollector($imap, ['INBOX', 'Sent']);
        $result = $collector->collect('INBOX', 1, 20);

        $this->assertSame([
            ['folder' => 'INBOX', 'uid' => 1],
            ['folder' => 'INBOX', 'uid' => 2],
            ['folder' => 'INBOX', 'uid' => 3],
            ['folder' => 'Sent', 'uid' => 2],
            ['folder' => 'Sent', 'uid' => 3],
        ], $result);

        $this->assertContains(['select', 'INBOX', true], $imap->calls);
        $this->assertContains(['select', 'Sent', true], $imap->calls);
    }

    public function testThreadRespectsMax(): void
    {
        $imap = new FakeImapClient();
        $imap->summaries[1] = $this->summary('<start@x>');
        $imap->summaries[2] = $this->summary('<r2@x>', '<start@x>');
        $imap->summaries[3] = $this->summary('<r3@x>', '<start@x>');
        $imap->summaries[4] = $this->summary('<r4@x>', '<start@x>');

        $imap->searchResults['HEADER In-Reply-To "<start@x>"'] = [2, 3, 4];

        $collector = new ThreadCollector($imap, ['INBOX']);
        $result = $collector->collect('INBOX', 1, 2);

        $this->assertCount(2, $result);
        $this->assertSame(['folder' => 'INBOX', 'uid' => 1], $result[0]);
        $this->assertSame(['folder' => 'INBOX', 'uid' => 2], $result[1]);

        // Stops as soon as the cap is reached: no further round (which would
        // search for uid 2's own Message-ID and look for more replies) runs.
        $selectCalls = array_filter($imap->calls, static fn (array $c): bool => 'select' === $c[0]);
        $this->assertCount(2, $selectCalls, 'one select() for the start message, one for the single productive round');
    }

    public function testThreadQuotesIdsInSearches(): void
    {
        $imap = new FakeImapClient();
        $imap->summaries[1] = $this->summary('<start@x>');

        $collector = new ThreadCollector($imap, ['INBOX']);
        $collector->collect('INBOX', 1, 20);

        $searchCriteria = array_map(
            static fn (array $c): string => $c[1],
            array_values(array_filter($imap->calls, static fn (array $c): bool => 'search' === $c[0])),
        );

        $this->assertContains('HEADER Message-ID "<start@x>"', $searchCriteria);
        $this->assertContains('HEADER In-Reply-To "<start@x>"', $searchCriteria);
        $this->assertContains('HEADER References "<start@x>"', $searchCriteria);
    }

    public function testThreadDedupesFoldersAndUids(): void
    {
        $imap = new FakeImapClient();
        $imap->summaries[1] = $this->summary('<start@x>');
        $imap->summaries[2] = $this->summary('<reply@x>', '<start@x>');

        // Both searches for the seed id would return uid 2 in INBOX; the
        // collector must still fetch and list it only once.
        $imap->searchResults['HEADER Message-ID "<start@x>"'] = [2];
        $imap->searchResults['HEADER In-Reply-To "<start@x>"'] = [2];
        $imap->searchResults['HEADER References "<start@x>"'] = [2];

        // A duplicated folder name must still be searched only once per round.
        $collector = new ThreadCollector($imap, ['INBOX', 'INBOX']);
        $result = $collector->collect('INBOX', 1, 20);

        $this->assertSame([
            ['folder' => 'INBOX', 'uid' => 1],
            ['folder' => 'INBOX', 'uid' => 2],
        ], $result);

        $fetchCalls = array_values(array_filter($imap->calls, static fn (array $c): bool => 'fetchSummaries' === $c[0]));
        // Exactly one fetchSummaries([1]) for the seed and one batched
        // fetchSummaries([2]) for the newly found uid.
        $this->assertCount(2, $fetchCalls);
        $this->assertSame([2], $fetchCalls[1][1]);

        $selectCalls = array_values(array_filter($imap->calls, static fn (array $c): bool => 'select' === $c[0] && 'INBOX' === $c[1]));
        // One select() to read the start message, one for round 1 (searches
        // for <start@x>, finds uid 2), one for round 2 (searches for uid 2's
        // own Message-ID <reply@x>, finds nothing new); round 3 then starts
        // with no new ids and stops before selecting again. A duplicated
        // folder name in the constructor list never causes an extra select.
        $this->assertCount(3, $selectCalls);
    }

    public function testCapsIdsPerMessageAndPerRound(): void
    {
        $references = [];

        for ($i = 1; $i <= 300; ++$i) {
            $references[] = "<ref{$i}@x>";
        }

        $imap = new FakeImapClient();
        $imap->summaries[1] = $this->summary('<start@x>', null, $references);

        // Mirrors the reported scenario: one start message with 300
        // references, searched across 3 folders.
        $collector = new ThreadCollector($imap, ['INBOX', 'Sent', 'Drafts']);
        $result = $collector->collect('INBOX', 1, 20);

        $this->assertSame([['folder' => 'INBOX', 'uid' => 1]], $result);

        $searchCalls = array_values(array_filter($imap->calls, static fn (array $c): bool => 'search' === $c[0]));

        // Per message: Message-ID + at most 20 References = at most 21
        // ids; per round: at most 60 ids. Either cap alone already keeps
        // this far below the pre-fix blow-up (300 refs * 3 header types *
        // 3 folders = 2700 searches); the per-message cap of 20 is the
        // one actually binding here, since 21 < 60.
        $this->assertLessThanOrEqual(60 * 3 * 3, count($searchCalls));
        $this->assertSame(21 * 3 * 3, count($searchCalls));

        // Only the last 20 references (ref281..ref300 -- the last 20 of
        // the last 50 that ParsedMessage::references() itself already
        // keeps) were ever searched for.
        $this->assertNotEmpty(array_filter(
            $searchCalls,
            static fn (array $c): bool => str_contains($c[1], '"<ref300@x>"'),
        ));
        $this->assertEmpty(array_filter(
            $searchCalls,
            static fn (array $c): bool => str_contains($c[1], '"<ref1@x>"') || str_contains($c[1], '"<ref250@x>"'),
        ));
    }

    public function testSkipsIdsQuoteRejects(): void
    {
        // ParsedMessage::ids() (Finding 1's fix) already keeps any id
        // reaching ThreadCollector well under SearchCriteria::quote()'s
        // length limit and free of CR/LF/NUL, so this specific failure
        // can no longer arise through collect()'s normal data flow. The
        // defensive catch is still exercised directly here, as a unit
        // test of ThreadCollector's own robustness (belt and braces:
        // it must not depend solely on an upstream filter it does not
        // control).
        $method = new ReflectionMethod(ThreadCollector::class, 'quoteOrNull');

        $this->assertNull($method->invoke(null, '<' . str_repeat('a', 999) . '@x>'));
        $this->assertNull($method->invoke(null, "<bad\x00id@x>"));
        $this->assertSame('"<ok@x>"', $method->invoke(null, '<ok@x>'));
    }

    public function testDropsUidWithoutSummary(): void
    {
        $imap = new FakeImapClient();
        $imap->summaries[1] = $this->summary('<start@x>');

        // The search finds uid 2, but it has no configured summary,
        // simulating a message expunged between the search and the
        // fetchSummaries call.
        $imap->searchResults['HEADER Message-ID "<start@x>"'] = [2];

        $collector = new ThreadCollector($imap, ['INBOX']);
        $result = $collector->collect('INBOX', 1, 20);

        $this->assertSame([['folder' => 'INBOX', 'uid' => 1]], $result);
    }
}
