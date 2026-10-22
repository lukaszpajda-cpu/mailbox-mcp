<?php

declare(strict_types=1);

namespace MailboxMcp\Mail;

use MailboxMcp\Mail\Imap\ImapClientInterface;
use MailboxMcp\Mail\Imap\SearchCriteria;
use MailboxMcp\Mail\Mime\Parser;
use MailboxMcp\Tools\ToolException;

/**
 * Collects the folder/uid pairs of a conversation by following
 * Message-ID / In-Reply-To / References across a set of folders,
 * breadth-first: the start message seeds a set of ids, every id is
 * searched for (as a Message-ID, an In-Reply-To and a References value)
 * in every folder, newly found messages contribute their own ids to the
 * next round, and this repeats until no id is left to search, the
 * message cap is reached, or {@see self::MAX_ITERATIONS} rounds have run.
 * Every folder/uid pair is read (via `fetchSummaries`) at most once.
 *
 * Two caps bound the IMAP traffic a single pathological message can
 * cause: each message contributes at most its Message-ID, its
 * In-Reply-To and its {@see self::MAX_REFERENCES_PER_MESSAGE} most recent
 * References, and a single round never searches more than
 * {@see self::MAX_NEW_IDS_PER_ROUND} ids in total (across every folder);
 * an id more than that is simply left for a later round. An id that
 * {@see SearchCriteria::quote()} rejects is skipped rather than aborting
 * the call, and a uid a search returns but `fetchSummaries` does not
 * (e.g. expunged between the two calls) is dropped, not collected.
 */
final class ThreadCollector
{
    private const MAX_ITERATIONS = 5;

    private const HARD_CAP = 50;

    private const MAX_REFERENCES_PER_MESSAGE = 20;

    private const MAX_NEW_IDS_PER_ROUND = 60;

    private const HEADERS = ['Message-ID', 'In-Reply-To', 'References'];

    /**
     * @param array<int, string> $folders folder names to search; deduplicated,
     *                                     with the start folder placed first
     */
    public function __construct(
        private readonly ImapClientInterface $imap,
        private readonly array $folders,
    ) {
    }

    /**
     * @return list<array{folder: string, uid: int}>
     */
    public function collect(string $startFolder, int $startUid, int $maxMessages): array
    {
        $maxMessages = min($maxMessages, self::HARD_CAP);

        $folders = $this->orderedFolders($startFolder);

        $this->imap->select($startFolder, true);
        $startSummary = $this->imap->fetchSummaries([$startUid])[$startUid] ?? null;

        $collected = [['folder' => $startFolder, 'uid' => $startUid]];
        $visited = [$this->pairKey($startFolder, $startUid) => true];

        /** @var array<string, true> $allIds every id seen so far */
        $allIds = [];
        /** @var array<string, true> $searchedIds ids already used as search criteria */
        $searchedIds = [];

        if (null !== $startSummary) {
            foreach (self::idsFromSummary($startSummary) as $id) {
                $allIds[$id] = true;
            }
        }

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS && count($collected) < $maxMessages; ++$iteration) {
            $newIds = array_keys(array_diff_key($allIds, $searchedIds));

            if ([] === $newIds) {
                break;
            }

            if (count($newIds) > self::MAX_NEW_IDS_PER_ROUND) {
                $newIds = array_slice($newIds, 0, self::MAX_NEW_IDS_PER_ROUND);
            }

            foreach ($folders as $folder) {
                $result = $this->searchFolder($folder, $newIds, $visited, $allIds, $collected, $maxMessages);
                $collected = $result;

                if (count($collected) >= $maxMessages) {
                    return $collected;
                }
            }

            foreach ($newIds as $id) {
                $searchedIds[$id] = true;
            }
        }

        return $collected;
    }

    /**
     * Searches one folder for every id in `$newIds`, fetches the newly
     * found uids in a single batched call, and appends them (up to the
     * cap) to `$collected`, harvesting their own ids into `$allIds`.
     *
     * @param array<int, string>                     $newIds
     * @param array<string, true>                    $visited  "folder\0uid" => true, updated in place
     * @param array<string, true>                    $allIds   updated in place
     * @param list<array{folder: string, uid: int}>  $collected
     *
     * @return list<array{folder: string, uid: int}>
     */
    private function searchFolder(string $folder, array $newIds, array &$visited, array &$allIds, array $collected, int $maxMessages): array
    {
        $this->imap->select($folder, true);

        $uidsToFetch = [];

        foreach ($newIds as $id) {
            $quoted = self::quoteOrNull($id);

            if (null === $quoted) {
                continue;
            }

            foreach (self::HEADERS as $header) {
                foreach ($this->imap->search("HEADER {$header} {$quoted}") as $uid) {
                    $key = $this->pairKey($folder, $uid);

                    if (!isset($visited[$key])) {
                        $visited[$key] = true;
                        $uidsToFetch[] = $uid;
                    }
                }
            }
        }

        if ([] === $uidsToFetch) {
            return $collected;
        }

        $summaries = $this->imap->fetchSummaries($uidsToFetch);

        foreach ($uidsToFetch as $uid) {
            if (!isset($summaries[$uid])) {
                // The search found this uid, but it is gone by the time
                // fetchSummaries runs (e.g. expunged in between): drop it
                // rather than collecting a message we cannot read.
                continue;
            }

            if (count($collected) >= $maxMessages) {
                return $collected;
            }

            $collected[] = ['folder' => $folder, 'uid' => $uid];

            foreach (self::idsFromSummary($summaries[$uid]) as $id) {
                $allIds[$id] = true;
            }
        }

        return $collected;
    }

    /**
     * {@see SearchCriteria::quote()}, swallowing the `ToolException` it
     * throws for an id it rejects (too long, or carrying a CR/LF/NUL) so
     * one malformed id cannot abort the whole `get_thread` call; the id
     * is simply left out of every search instead.
     */
    private static function quoteOrNull(string $id): ?string
    {
        try {
            return SearchCriteria::quote($id);
        } catch (ToolException) {
            return null;
        }
    }

    /**
     * @param array{flags: array<int, string>, size: int, internaldate: ?string, headers: string, bodystructure: string} $summary
     *
     * @return list<string>
     */
    private static function idsFromSummary(array $summary): array
    {
        $parsed = Parser::parse($summary['headers'] . "\r\n\r\n");
        $ids = [];

        $messageId = $parsed->messageId();

        if (null !== $messageId) {
            $ids[] = $messageId;
        }

        $inReplyTo = $parsed->inReplyTo();

        if (null !== $inReplyTo) {
            $ids[] = $inReplyTo;
        }

        $references = $parsed->references();

        if (count($references) > self::MAX_REFERENCES_PER_MESSAGE) {
            $references = array_slice($references, -self::MAX_REFERENCES_PER_MESSAGE);
        }

        foreach ($references as $reference) {
            $ids[] = $reference;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function orderedFolders(string $startFolder): array
    {
        $ordered = [];
        $seen = [];

        foreach ([$startFolder, ...$this->folders] as $name) {
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $ordered[] = $name;
            }
        }

        return $ordered;
    }

    private function pairKey(string $folder, int $uid): string
    {
        return $folder . "\0" . $uid;
    }
}
