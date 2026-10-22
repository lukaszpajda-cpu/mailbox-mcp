<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Support;

use MailboxMcp\Mail\Imap\Folder;
use MailboxMcp\Mail\Imap\ImapClientInterface;
use Throwable;

/**
 * In-memory stand-in for {@see ImapClientInterface}. Every call is
 * recorded in $calls (method name followed by its arguments); responses
 * are canned via the public arrays below, set up by the test before
 * exercising the code under test.
 */
final class FakeImapClient implements ImapClientInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    /** @var array<int, string> */
    public array $capabilities = ['IMAP4REV1', 'UIDPLUS', 'SPECIAL-USE'];

    /** @var array<int, Folder> */
    public array $folders = [];

    /** @var array<string, array{messages: int, unseen: int}> */
    public array $statuses = [];

    /** @var array<string, array{exists: int, uidvalidity: int, uidnext: int}> */
    public array $selected = [];

    /** @var array<string, array<int, int>> criteria string => uids */
    public array $searchResults = [];

    /**
     * Returned by {@see self::search()} for a criteria string with no
     * matching key in $searchResults; null keeps the previous behaviour
     * (no match). Lets a test cover a uid-fallback search without having
     * to predict a server-generated criteria value (e.g. a random
     * Message-ID) ahead of time.
     *
     * @var array<int, int>|null
     */
    public ?array $searchResultsFallback = null;

    /**
     * @var array<int, array{
     *     flags: array<int, string>,
     *     size: int,
     *     internaldate: ?string,
     *     headers: string,
     *     bodystructure: string,
     * }> uid => summary
     */
    public array $summaries = [];

    /** @var array<int, string> uid => raw header block */
    public array $headers = [];

    /** @var array<string, string> "uid:section" => string */
    public array $sections = [];

    /** @var array<int, string> uid => raw message */
    public array $raw = [];

    public ?int $appendUid = 1000;

    /** @var array<int, array{0: string, 1: string, 2: array<int, string>}> list of [folder, raw, flags] */
    public array $appended = [];

    /** @var array<int, array{0: array<int, int>, 1: array<int, string>, 2: bool}> list of [uids, flags, add] */
    public array $storedFlags = [];

    /** @var array<int, int> */
    public array $expunged = [];

    public bool $connected = false;

    public bool $closed = false;

    /** @var array<string, Throwable> method name => exception to throw */
    public array $throwOn = [];

    public function connect(): void
    {
        $this->calls[] = ['connect'];
        $this->maybeThrow('connect');
        $this->connected = true;
    }

    public function capabilities(): array
    {
        $this->calls[] = ['capabilities'];
        $this->maybeThrow('capabilities');

        return $this->capabilities;
    }

    public function listFolders(): array
    {
        $this->calls[] = ['listFolders'];
        $this->maybeThrow('listFolders');

        return $this->folders;
    }

    public function status(string $folder): array
    {
        $this->calls[] = ['status', $folder];
        $this->maybeThrow('status');

        return $this->statuses[$folder] ?? ['messages' => 0, 'unseen' => 0];
    }

    public function select(string $folder, bool $readOnly = true): array
    {
        $this->calls[] = ['select', $folder, $readOnly];
        $this->maybeThrow('select');

        return $this->selected[$folder] ?? ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 1];
    }

    public function search(string $criteria, ?string $charset = 'UTF-8'): array
    {
        $this->calls[] = ['search', $criteria, $charset];
        $this->maybeThrow('search');

        return $this->searchResults[$criteria] ?? $this->searchResultsFallback ?? [];
    }

    public function fetchSummaries(array $uids): array
    {
        $this->calls[] = ['fetchSummaries', $uids];
        $this->maybeThrow('fetchSummaries');

        $result = [];

        foreach ($uids as $uid) {
            if (isset($this->summaries[$uid])) {
                $result[$uid] = $this->summaries[$uid];
            }
        }

        return $result;
    }

    public function fetchHeaders(int $uid): string
    {
        $this->calls[] = ['fetchHeaders', $uid];
        $this->maybeThrow('fetchHeaders');

        return $this->headers[$uid] ?? '';
    }

    public function fetchSection(int $uid, string $section, ?int $maxBytes = null): string
    {
        $this->calls[] = ['fetchSection', $uid, $section, $maxBytes];
        $this->maybeThrow('fetchSection');

        return $this->sections["{$uid}:{$section}"] ?? '';
    }

    public function fetchRaw(int $uid): string
    {
        $this->calls[] = ['fetchRaw', $uid];
        $this->maybeThrow('fetchRaw');

        return $this->raw[$uid] ?? '';
    }

    public function storeFlags(array $uids, array $flags, bool $add): int
    {
        $this->calls[] = ['storeFlags', $uids, $flags, $add];
        $this->maybeThrow('storeFlags');
        $this->storedFlags[] = [$uids, $flags, $add];

        return count($uids);
    }

    public function append(string $folder, string $raw, array $flags): ?int
    {
        $this->calls[] = ['append', $folder, $raw, $flags];
        $this->maybeThrow('append');
        $this->appended[] = [$folder, $raw, $flags];

        if (null === $this->appendUid) {
            return null;
        }

        return $this->appendUid++;
    }

    public function expungeUid(int $uid): bool
    {
        $this->calls[] = ['expungeUid', $uid];
        $this->maybeThrow('expungeUid');
        $this->expunged[] = $uid;

        return in_array('UIDPLUS', $this->capabilities, true);
    }

    public function close(): void
    {
        $this->calls[] = ['close'];
        $this->maybeThrow('close');
        $this->closed = true;
    }

    public function createFolder(string $name): void
    {
        $this->calls[] = ['createFolder', $name];
        $this->maybeThrow('createFolder');
        $this->folders[] = new Folder($name, '/', []);
    }

    private function maybeThrow(string $method): void
    {
        if (isset($this->throwOn[$method])) {
            throw $this->throwOn[$method];
        }
    }
}
