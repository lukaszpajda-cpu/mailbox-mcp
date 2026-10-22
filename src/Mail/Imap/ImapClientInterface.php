<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

/**
 * Everything a mail tool needs from an IMAP connection. The real
 * implementation (a plain socket client, Task 15) and
 * {@see \MailboxMcp\Tests\Support\FakeImapClient} both implement this;
 * tools never talk to IMAP except through here.
 */
interface ImapClientInterface
{
    /**
     * Opens the socket, negotiates TLS/STARTTLS per the account config,
     * and authenticates. Called at most once per instance, lazily, by
     * {@see \MailboxMcp\Mail\MailSession::imap()}.
     *
     * @throws ImapException
     */
    public function connect(): void;

    /**
     * @return array<int, string> uppercase capability names, e.g. ['IMAP4REV1', 'UIDPLUS', 'SPECIAL-USE']
     */
    public function capabilities(): array;

    /**
     * @return array<int, Folder>
     */
    public function listFolders(): array;

    /**
     * @return array{messages: int, unseen: int}
     */
    public function status(string $folder): array;

    /**
     * @return array{exists: int, uidvalidity: int, uidnext: int}
     */
    public function select(string $folder, bool $readOnly = true): array;

    /**
     * @return array<int, int> matching message UIDs
     */
    public function search(string $criteria, ?string $charset = 'UTF-8'): array;

    /**
     * @param array<int, int> $uids
     *
     * @return array<int, array{
     *     flags: array<int, string>,
     *     size: int,
     *     internaldate: ?string,
     *     headers: string,
     *     bodystructure: string,
     * }> keyed by uid; uids with no matching message are simply absent
     */
    public function fetchSummaries(array $uids): array;

    /**
     * @return string raw header block
     */
    public function fetchHeaders(int $uid): string;

    /**
     * BODY.PEEK[section]&lt;0.max&gt;
     */
    public function fetchSection(int $uid, string $section, ?int $maxBytes = null): string;

    /**
     * @return string the complete raw message (headers + body)
     */
    public function fetchRaw(int $uid): string;

    /**
     * +FLAGS.SILENT / -FLAGS.SILENT.
     *
     * @param array<int, int>    $uids
     * @param array<int, string> $flags
     *
     * @return int count of uids affected
     */
    public function storeFlags(array $uids, array $flags, bool $add): int;

    /**
     * @param array<int, string> $flags
     *
     * @return ?int the APPENDUID uid, or null when the server does not report one
     */
    public function append(string $folder, string $raw, array $flags): ?int;

    /**
     * UID EXPUNGE when UIDPLUS is available.
     *
     * @return bool false when the server lacks UIDPLUS (nothing was expunged)
     */
    public function expungeUid(int $uid): bool;

    /**
     * IMAP CREATE. A tagged NO (e.g. the folder already exists) throws
     * {@see ImapException} like any other failed command; a caller that
     * wants to tolerate "already exists" catches it itself.
     *
     * @throws ImapException
     */
    public function createFolder(string $name): void;

    public function close(): void;
}
