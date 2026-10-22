<?php

/*
 * Derived from SecureMessage Mail MCP Server — Pure PHP Socket IMAP Client
 * (securemessage/mail-mcp, SocketImapClient.class.php), adapted for
 * mailbox-mcp: blocking I/O with a bounded stream_select read loop (no
 * Fibers / event loop / progress), ImapClientInterface method set, no
 * XOAUTH2 and no MIME parsing (raw bytes are returned).
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @license BSD-2-Clause
 */

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

/**
 * IMAP4rev1 client over a plain PHP stream socket (no ext-imap).
 *
 * One instance serves one HTTP request: connect() opens the socket
 * (implicit TLS, STARTTLS or — localhost only, enforced by config —
 * plaintext), reads the greeting and capabilities and logs in; the tool
 * runs a few commands; close() logs out. Every read is bounded by the
 * configured timeout. A timeout, a disconnect or a malformed response
 * drops the connection; a tagged NO/BAD throws but keeps it usable.
 *
 * Only folder names (modified UTF-7, quoted), flags (validated atoms),
 * search criteria (pre-quoted by {@see SearchCriteria}, CR/LF/NUL
 * rejected), integers and the login credentials ever reach the wire.
 * Credentials are never logged nor included in exception messages.
 */
final class Client implements ImapClientInterface
{
    private const SUMMARY_ITEMS = '(UID FLAGS RFC822.SIZE INTERNALDATE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC REPLY-TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES)])';

    private const SECTION_PATTERN = '/^[0-9.]+$|^HEADER$|^TEXT$|^[0-9.]+\.(HEADER|TEXT|MIME)$/';

    private const FLAG_PATTERN = '/^\\\\?[A-Za-z0-9$_.-]+$/';

    private const CHARSET_PATTERN = '/^[A-Za-z0-9_.:-]{1,40}$/';

    /** Poll slice of the bounded stream_select loop. */
    private const WAIT_SLICE_USEC = 100000;

    private const READ_CHUNK = 65536;

    private const MAX_LINE = 1048576;

    private const MAX_LITERAL = 67108864;

    private const MAX_SEARCH_RESULTS = 1000000;

    /** @var resource|null */
    private $socket = null;

    private ?float $deadlineNs = null;

    private int $tagCounter = 0;

    private string $buffer = '';

    /** @var array<int, string> */
    private array $capabilities = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeoutSeconds,
        private readonly bool $verifyPeer,
    ) {
    }

    /**
     * Wall-clock end of the whole HTTP request, as an `hrtime(true)`
     * value in nanoseconds. `set_time_limit()` does not count time spent
     * blocked in stream I/O on Linux, so this is what actually bounds a
     * slow or stalled server: every command checks it before sending and
     * every socket wait is capped to the time left, failing with
     * "request time budget exceeded" once it has passed.
     */
    public function setDeadline(float $deadlineNs): void
    {
        $this->deadlineNs = $deadlineNs;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        if (null !== $this->socket) {
            return;
        }

        if (!in_array($this->encryption, ['tls', 'starttls', 'none'], true)) {
            throw new ImapException("Unsupported IMAP encryption: {$this->encryption}");
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
                'SNI_enabled' => true,
                'peer_name' => $this->host,
            ],
        ]);

        $left = $this->budgetRemaining();

        if (null !== $left && $left <= 0) {
            throw new ImapException('request time budget exceeded');
        }

        $connectTimeout = null === $left ? (float) $this->timeoutSeconds : min((float) $this->timeoutSeconds, $left);
        $scheme = 'tls' === $this->encryption ? 'ssl' : 'tcp';
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "{$scheme}://{$host}:{$this->port}",
            $errno,
            $errstr,
            $connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (false === $socket) {
            throw new ImapException("IMAP connection to {$this->host}:{$this->port} failed: " . ('' !== $errstr ? $errstr : "error {$errno}"));
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, max(1, $this->timeoutSeconds));
        $this->socket = $socket;
        $this->buffer = '';
        $this->tagCounter = 0;
        $this->capabilities = [];

        try {
            $this->handshake();
        } catch (ImapException $e) {
            $this->drop();

            throw $e;
        }
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function listFolders(): array
    {
        $folders = [];

        foreach ($this->run('LIST', ['LIST "" "*"'])['untagged'] as $response) {
            if (1 !== preg_match('/^\* LIST /i', $response->line(), $m)) {
                continue;
            }

            $values = $response->values(strlen($m[0]));
            $attributes = is_array($values[0] ?? null) ? $values[0] : [];
            $delimiter = $values[1] ?? null;
            $name = $values[2] ?? null;

            if (!is_string($name)) {
                continue;
            }

            $folders[] = new Folder(
                Literal::decodeMailbox($name),
                is_string($delimiter) && '' !== $delimiter ? $delimiter : '/',
                array_values(array_map(
                    static fn (mixed $a): string => strtolower((string) $a),
                    array_filter($attributes, 'is_string'),
                )),
            );
        }

        return $folders;
    }

    public function status(string $folder): array
    {
        $mailbox = Literal::mailbox($folder);
        $result = ['messages' => 0, 'unseen' => 0];

        foreach ($this->run('STATUS', ["STATUS {$mailbox} (MESSAGES UNSEEN)"])['untagged'] as $response) {
            if (1 !== preg_match('/^\* STATUS /i', $response->line(), $m)) {
                continue;
            }

            $values = $response->values(strlen($m[0]));
            $list = $values[1] ?? null;

            if (!is_array($list)) {
                continue;
            }

            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $key = strtolower((string) (is_string($list[$i]) ? $list[$i] : ''));

                if ('messages' === $key || 'unseen' === $key) {
                    $result[$key] = (int) (is_string($list[$i + 1]) ? $list[$i + 1] : 0);
                }
            }

            break;
        }

        return $result;
    }

    public function select(string $folder, bool $readOnly = true): array
    {
        $mailbox = Literal::mailbox($folder);
        $verb = $readOnly ? 'EXAMINE' : 'SELECT';
        $response = $this->run($verb, ["{$verb} {$mailbox}"]);
        $result = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];

        foreach ($response['untagged'] as $untagged) {
            $line = $untagged->line();

            if (1 === preg_match('/^\* (\d+) EXISTS\b/i', $line, $m)) {
                $result['exists'] = (int) $m[1];
            } elseif (1 === preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                $result['uidvalidity'] = (int) $m[1];
            } elseif (1 === preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m)) {
                $result['uidnext'] = (int) $m[1];
            }
        }

        return $result;
    }

    public function search(string $criteria, ?string $charset = 'UTF-8'): array
    {
        Literal::assertSafe($criteria, 'Search criteria');

        if (null !== $charset && 1 !== preg_match(self::CHARSET_PATTERN, $charset)) {
            throw new ImapException('Invalid search charset');
        }

        if (null === $charset) {
            $response = $this->run('UID SEARCH', ["UID SEARCH {$criteria}"]);
        } else {
            $response = $this->exec(
                'UID SEARCH',
                self::criteriaSegments("UID SEARCH CHARSET {$charset} {$criteria}"),
                $this->hasCapability('LITERAL+'),
            );

            if ('NO' === $response['status'] && false !== stripos($response['text'], 'BADCHARSET')) {
                $response = $this->run('UID SEARCH', ['UID SEARCH ' . self::asciiCriteria($criteria)]);
            } elseif ('OK' !== $response['status']) {
                throw $this->failure('UID SEARCH', $response['text']);
            }
        }

        $uids = [];

        foreach ($response['untagged'] as $untagged) {
            $line = $untagged->line();

            if (1 === preg_match('/^\* SEARCH(?: (.*))?$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            } elseif (1 === preg_match('/^\* ESEARCH /i', $line, $m)) {
                $values = $untagged->values(strlen($m[0]));

                foreach ($values as $i => $value) {
                    $next = $values[$i + 1] ?? null;

                    if (is_string($value) && 'ALL' === strtoupper($value) && is_string($next)) {
                        array_push($uids, ...self::expandSet($next, self::MAX_SEARCH_RESULTS - count($uids)));
                    }
                }
            }

            if (count($uids) > self::MAX_SEARCH_RESULTS) {
                throw new ImapException('IMAP SEARCH returned too many results');
            }
        }

        $uids = array_values(array_unique($uids));
        sort($uids);

        return $uids;
    }

    public function fetchSummaries(array $uids): array
    {
        $set = Literal::uidSet($uids);

        if ('' === $set) {
            return [];
        }

        $wanted = array_flip(array_map('intval', $uids));
        $result = [];

        foreach ($this->run('UID FETCH', ["UID FETCH {$set} " . self::SUMMARY_ITEMS])['untagged'] as $response) {
            $items = $response->fetch();
            $uid = (int) (is_string($items['UID'] ?? null) ? $items['UID'] : 0);

            if (null === $items || $uid <= 0 || !isset($wanted[$uid])) {
                continue;
            }

            $summary = $result[$uid] ?? ['flags' => [], 'size' => 0, 'internaldate' => null, 'headers' => '', 'bodystructure' => ''];

            foreach ($items as $key => $value) {
                if ('FLAGS' === $key && is_array($value)) {
                    $summary['flags'] = array_values(array_filter($value, 'is_string'));
                } elseif ('RFC822.SIZE' === $key && is_string($value)) {
                    $summary['size'] = (int) $value;
                } elseif ('INTERNALDATE' === $key) {
                    $summary['internaldate'] = is_string($value) ? $value : null;
                } elseif ('BODYSTRUCTURE' === $key && is_string($value)) {
                    $summary['bodystructure'] = $value;
                } elseif (str_starts_with($key, 'BODY[HEADER.FIELDS')) {
                    $summary['headers'] = is_string($value) ? $value : '';
                }
            }

            $result[$uid] = $summary;
        }

        return $result;
    }

    public function fetchHeaders(int $uid): string
    {
        return $this->fetchBody($uid, 'HEADER', '');
    }

    public function fetchSection(int $uid, string $section, ?int $maxBytes = null): string
    {
        if (1 !== preg_match(self::SECTION_PATTERN, $section)) {
            throw new ImapException('Invalid IMAP body section');
        }

        if (null !== $maxBytes && $maxBytes < 1) {
            throw new ImapException('maxBytes must be positive');
        }

        return $this->fetchBody($uid, $section, null === $maxBytes ? '' : '<0.' . $maxBytes . '>');
    }

    public function fetchRaw(int $uid): string
    {
        return $this->fetchBody($uid, '', '');
    }

    public function storeFlags(array $uids, array $flags, bool $add): int
    {
        $flagList = self::flagList($flags);
        $set = Literal::uidSet($uids);

        if ('' === $set || '' === $flagList) {
            return 0;
        }

        $this->run('UID STORE', ["UID STORE {$set} " . ($add ? '+' : '-') . "FLAGS.SILENT ({$flagList})"]);

        return count($uids);
    }

    public function append(string $folder, string $raw, array $flags): ?int
    {
        $mailbox = Literal::mailbox($folder);
        $flagList = self::flagList($flags);

        if (str_contains($raw, "\0")) {
            throw new ImapException('Message contains NUL bytes');
        }

        $message = (string) preg_replace('/(?<!\r)\n/', "\r\n", $raw);
        $response = $this->run('APPEND', ["APPEND {$mailbox} ({$flagList}) ", $message, ''], $this->hasCapability('LITERAL+'));

        if (1 === preg_match('/\[APPENDUID \d+ (\d+)\]/i', $response['text'], $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function expungeUid(int $uid): bool
    {
        if ($uid < 1) {
            throw new ImapException('Invalid UID');
        }

        if (!$this->hasCapability('UIDPLUS')) {
            return false;
        }

        $this->run('UID EXPUNGE', ['UID EXPUNGE ' . $uid]);

        return true;
    }

    public function createFolder(string $name): void
    {
        $mailbox = Literal::mailbox($name);
        $this->run('CREATE', ["CREATE {$mailbox}"]);
    }

    public function close(): void
    {
        if (null === $this->socket) {
            return;
        }

        try {
            $this->exec('LOGOUT', ['LOGOUT']);
        } catch (ImapException) {
            // The server may drop the connection right after BYE.
        }

        $this->drop();
    }

    // ────────────────────────────────────────────────────────────
    //  Session setup
    // ────────────────────────────────────────────────────────────

    private function handshake(): void
    {
        $greeting = $this->readResponse()->text();

        if (1 !== preg_match('/^\* (OK|PREAUTH)\b/i', $greeting, $m)) {
            throw new ImapException('IMAP server rejected the connection: ' . self::clean($greeting));
        }

        $preauth = 'PREAUTH' === strtoupper($m[1]);
        $this->capabilities = self::codeCapabilities($greeting) ?? [];

        if ('starttls' === $this->encryption) {
            if ($preauth) {
                throw new ImapException('IMAP server sent PREAUTH; STARTTLS is impossible');
            }

            $this->starttls();
        }

        if ([] === $this->capabilities) {
            $this->refreshCapabilities();
        }

        if ($preauth) {
            return;
        }

        if ($this->hasCapability('LOGINDISABLED')) {
            throw new ImapException('IMAP server disallows LOGIN on this connection');
        }

        $this->login();
    }

    private function starttls(): void
    {
        $this->run('STARTTLS', ['STARTTLS']);

        if ('' !== $this->buffer) {
            // Bytes sent before the handshake could be injected by a MITM.
            throw new ImapException('IMAP server sent data before the TLS handshake');
        }

        $socket = $this->requireSocket();
        $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if (true !== $ok) {
            throw new ImapException('IMAP TLS negotiation failed after STARTTLS');
        }

        // Capabilities from before TLS must be discarded (RFC 3501 §6.2.1).
        $this->capabilities = [];
    }

    private function login(): void
    {
        if (str_contains($this->username, "\0") || str_contains($this->password, "\0")) {
            throw new ImapException('IMAP credentials contain a NUL byte');
        }

        $segments = ['LOGIN '];
        self::addAstring($segments, $this->username);
        $segments[count($segments) - 1] .= ' ';
        self::addAstring($segments, $this->password);

        $response = $this->run('LOGIN', $segments);
        $capabilities = self::codeCapabilities($response['text']);

        if (null !== $capabilities) {
            $this->capabilities = $capabilities;
        } else {
            $this->refreshCapabilities();
        }
    }

    private function refreshCapabilities(): void
    {
        foreach ($this->run('CAPABILITY', ['CAPABILITY'])['untagged'] as $response) {
            if (1 === preg_match('/^\* CAPABILITY (.*)$/i', $response->line(), $m)) {
                $this->capabilities = self::atoms($m[1]);
            }
        }
    }

    private function hasCapability(string $name): bool
    {
        return in_array($name, $this->capabilities, true);
    }

    // ────────────────────────────────────────────────────────────
    //  Commands
    // ────────────────────────────────────────────────────────────

    private function fetchBody(int $uid, string $section, string $partial): string
    {
        if ($uid < 1) {
            throw new ImapException('Invalid UID');
        }

        $wantKey = 'BODY[' . $section . ']';

        foreach ($this->run('UID FETCH', ["UID FETCH {$uid} (BODY.PEEK[{$section}]{$partial})"])['untagged'] as $response) {
            $items = $response->fetch();

            if (null === $items || (int) (is_string($items['UID'] ?? null) ? $items['UID'] : 0) !== $uid) {
                continue;
            }

            $value = $items[$wantKey] ?? null;

            if (is_string($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Like {@see self::exec()} but throws on a tagged NO/BAD.
     *
     * @param array<int, string> $segments
     *
     * @return array{status: string, text: string, untagged: array<int, Response>}
     */
    private function run(string $name, array $segments, bool $nonSyncLiterals = false): array
    {
        $response = $this->exec($name, $segments, $nonSyncLiterals);

        if ('OK' !== $response['status']) {
            throw $this->failure($name, $response['text']);
        }

        return $response;
    }

    /**
     * Sends one tagged command and reads everything up to its tagged
     * response. $segments alternate command text and literal payloads
     * (text, literal, text, …): each literal is announced as `{n}` (or
     * `{n+}` with LITERAL+) and, when synchronising, sent only after the
     * server's `+` continuation.
     *
     * @param array<int, string> $segments
     *
     * @return array{status: string, text: string, untagged: array<int, Response>}
     */
    private function exec(string $name, array $segments, bool $nonSyncLiterals = false): array
    {
        $this->requireSocket();
        $this->assertBudget();
        $tag = sprintf('A%03d', ++$this->tagCounter);
        $segments = array_values($segments);
        $untagged = [];
        $pending = $tag . ' ';

        foreach ($segments as $i => $segment) {
            if (0 === $i % 2) {
                $pending .= $segment;

                continue;
            }

            $this->write($pending . '{' . strlen($segment) . ($nonSyncLiterals ? '+' : '') . "}\r\n");
            $pending = '';

            if (!$nonSyncLiterals) {
                $tagged = $this->awaitContinuation($tag, $untagged);

                if (null !== $tagged) {
                    // Server refused the command before taking the literal.
                    return $tagged + ['untagged' => $untagged];
                }
            }

            $this->write($segment);
        }

        $this->write($pending . "\r\n");

        while (true) {
            $response = $this->readResponse();
            $tagged = self::tagged($response, $tag);

            if (null !== $tagged) {
                return $tagged + ['untagged' => $untagged];
            }

            $untagged[] = $response;
        }
    }

    /**
     * Reads until the `+` continuation (returns null) or the command's
     * tagged response (returned: the server refused before the literal).
     *
     * @param array<int, Response> $untagged
     *
     * @return ?array{status: string, text: string}
     */
    private function awaitContinuation(string $tag, array &$untagged): ?array
    {
        while (true) {
            $response = $this->readResponse();

            if (str_starts_with($response->line(), '+')) {
                return null;
            }

            $tagged = self::tagged($response, $tag);

            if (null !== $tagged) {
                return $tagged;
            }

            $untagged[] = $response;
        }
    }

    /**
     * @return ?array{status: string, text: string}
     */
    private static function tagged(Response $response, string $tag): ?array
    {
        $line = $response->text();

        if (!str_starts_with($line, $tag . ' ')) {
            return null;
        }

        if (1 !== preg_match('/^\S+ (OK|NO|BAD)\b ?(.*)$/is', $line, $m)) {
            return ['status' => 'BAD', 'text' => substr($line, strlen($tag) + 1)];
        }

        return ['status' => strtoupper($m[1]), 'text' => $m[2]];
    }

    private function failure(string $name, string $text): ImapException
    {
        if ('' !== $this->password) {
            // A server echoing the command back must not leak the password.
            $text = str_replace($this->password, '***', $text);
        }

        return new ImapException("IMAP {$name} failed: " . self::clean($text));
    }

    // ────────────────────────────────────────────────────────────
    //  Socket I/O
    // ────────────────────────────────────────────────────────────

    /**
     * One response line, with `{n}` literals read and kept aside.
     */
    private function readResponse(): Response
    {
        $texts = [];
        $literals = [];

        $line = $this->readLine();
        // Only untagged data lines carry literals; tagged, continuation and
        // `* OK|NO|BAD|BYE|PREAUTH` status lines are free text that may
        // happen to end in "{n}".
        $mayHaveLiterals = str_starts_with($line, '* ') && 1 !== preg_match('/^\* (OK|NO|BAD|BYE|PREAUTH)\b/i', $line);

        while (true) {
            if ($mayHaveLiterals && 1 === preg_match('/\{(\d+)\}$/', $line, $m)) {
                $size = (int) $m[1];

                if (strlen($m[1]) > 9 || $size > self::MAX_LITERAL) {
                    $this->fail('IMAP literal too large');
                }

                $texts[] = substr($line, 0, -strlen($m[0]));
                $literals[] = $this->readBytes($size);
                $line = $this->readLine();

                continue;
            }

            $texts[] = $line;

            return new Response($texts, $literals);
        }
    }

    private function readLine(): string
    {
        while (false === ($nl = strpos($this->buffer, "\n"))) {
            if (strlen($this->buffer) > self::MAX_LINE) {
                $this->fail('IMAP response line too long');
            }

            $this->pump();
        }

        $line = substr($this->buffer, 0, $nl);
        $this->buffer = substr($this->buffer, $nl + 1);

        return rtrim($line, "\r");
    }

    private function readBytes(int $count): string
    {
        while (strlen($this->buffer) < $count) {
            $this->pump();
        }

        $data = substr($this->buffer, 0, $count);
        $this->buffer = substr($this->buffer, $count);

        return $data;
    }

    /**
     * Bounded stream_select loop: waits in short slices until the socket
     * is readable or the timeout passes without any byte arriving (an
     * idle timeout, so a large literal on a slow link still completes),
     * then appends what is there to the buffer. (PHP's select also reports bytes already decrypted and
     * buffered by OpenSSL, so TLS streams are handled correctly.)
     */
    private function pump(): void
    {
        $socket = $this->requireSocket();
        $deadline = microtime(true) + max(1, $this->timeoutSeconds);

        while (true) {
            $remaining = $deadline - microtime(true);
            // One clock read for both the check and the wait, so the wait
            // can never go negative between them (stream_select would
            // throw a ValueError on a negative microsecond count).
            $left = $this->budgetRemaining();

            if (null !== $left && $left <= 0) {
                $this->fail('request time budget exceeded');
            }

            if ($remaining <= 0) {
                $this->fail('IMAP read timed out');
            }

            $wait = max(0.0, null === $left ? $remaining : min($remaining, $left));
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, (int) min(self::WAIT_SLICE_USEC, ceil($wait * 1000000)));

            if (false === $ready) {
                $this->fail('IMAP read failed');
            }

            if (0 === $ready) {
                continue;
            }

            $chunk = @fread($socket, self::READ_CHUNK);

            if (false !== $chunk && '' !== $chunk) {
                $this->buffer .= $chunk;

                return;
            }

            if (feof($socket)) {
                $this->fail('IMAP connection closed');
            }

            if (false === $chunk) {
                $this->fail('IMAP read failed');
            }
        }
    }

    private function write(string $data): void
    {
        $socket = $this->requireSocket();
        $offset = 0;
        $total = strlen($data);

        while ($offset < $total) {
            $written = @fwrite($socket, substr($data, $offset));

            if (false === $written || 0 === $written) {
                $meta = stream_get_meta_data($socket);
                $this->fail(true === $meta['timed_out'] ? 'IMAP write timed out' : 'IMAP connection closed');
            }

            $offset += $written;
        }
    }

    /**
     * @return resource
     */
    private function requireSocket()
    {
        if (null === $this->socket) {
            throw new ImapException('IMAP client is not connected');
        }

        return $this->socket;
    }

    /**
     * @return ?float seconds left before the request deadline, or null when none is set
     */
    private function budgetRemaining(): ?float
    {
        return null === $this->deadlineNs ? null : ($this->deadlineNs - hrtime(true)) / 1e9;
    }

    private function assertBudget(): void
    {
        $left = $this->budgetRemaining();

        if (null !== $left && $left <= 0) {
            $this->fail('request time budget exceeded');
        }
    }

    private function fail(string $message): never
    {
        $this->drop();

        throw new ImapException($message);
    }

    private function drop(): void
    {
        if (null !== $this->socket) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->buffer = '';
    }

    // ────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Appends $value as a quoted string, or as a literal when it holds
     * `"`, `\`, control or non-ASCII bytes.
     *
     * @param array<int, string> $segments
     */
    private static function addAstring(array &$segments, string $value): void
    {
        if (Literal::needsLiteral($value)) {
            $segments[] = $value;
            $segments[] = '';

            return;
        }

        $segments[count($segments) - 1] .= '"' . $value . '"';
    }

    /**
     * @param array<int, string> $flags
     */
    private static function flagList(array $flags): string
    {
        foreach ($flags as $flag) {
            if (1 !== preg_match(self::FLAG_PATTERN, $flag)) {
                throw new ImapException('Invalid IMAP flag');
            }
        }

        return implode(' ', $flags);
    }

    /**
     * @return ?array<int, string>
     */
    private static function codeCapabilities(string $text): ?array
    {
        if (1 !== preg_match('/\[CAPABILITY ([^\]]*)\]/i', $text, $m)) {
            return null;
        }

        return self::atoms($m[1]);
    }

    /**
     * @return array<int, string>
     */
    private static function atoms(string $s): array
    {
        return array_map('strtoupper', preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * "1:3,5" => [1, 2, 3, 5]; `*` and malformed parts are ignored.
     *
     * @return array<int, int>
     */
    private static function expandSet(string $set, int $limit): array
    {
        $out = [];

        foreach (explode(',', $set) as $part) {
            if (1 === preg_match('/^(\d+):(\d+)$/', $part, $m)) {
                $a = (int) $m[1];
                $b = (int) $m[2];

                if ($a > $b) {
                    [$a, $b] = [$b, $a];
                }

                if ($b - $a >= $limit - count($out)) {
                    throw new ImapException('IMAP SEARCH returned too many results');
                }

                for ($n = $a; $n <= $b; ++$n) {
                    $out[] = $n;
                }
            } elseif (ctype_digit($part)) {
                $out[] = (int) $part;
            }
        }

        return $out;
    }

    /**
     * Splits a search command into exec() segments: every quoted string
     * holding a byte >= 0x80 becomes a literal of its unescaped content
     * (RFC 3501 quoted strings are 7-bit); 7-bit quoted strings stay as
     * they are.
     *
     * @return array<int, string>
     */
    private static function criteriaSegments(string $command): array
    {
        $segments = [''];
        $offset = 0;

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $command, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            [$quoted, $start] = $match[0];

            if (1 !== preg_match('/[\x80-\xff]/', $quoted)) {
                continue;
            }

            $segments[count($segments) - 1] .= substr($command, $offset, $start - $offset);
            $segments[] = (string) preg_replace('/\\\\(.)/s', '$1', $match[1][0]);
            $segments[] = '';
            $offset = $start + strlen($quoted);
        }

        $segments[count($segments) - 1] .= substr($command, $offset);

        return $segments;
    }

    /**
     * Transliterates the contents of every quoted string in $criteria to
     * printable ASCII (for servers that reject CHARSET UTF-8), re-quoting
     * the result so transliteration can never break the string syntax.
     */
    private static function asciiCriteria(string $criteria): string
    {
        return (string) preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/s',
            static function (array $m): string {
                $value = (string) preg_replace('/\\\\(.)/s', '$1', $m[1]);

                if (function_exists('iconv')) {
                    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

                    if (false !== $ascii) {
                        $value = $ascii;
                    }
                }

                $value = (string) preg_replace('/[^\x20-\x7e]/', '', $value);

                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
            },
            $criteria,
        );
    }

    /**
     * Server text for exception messages: printable, single line, bounded.
     */
    private static function clean(string $text): string
    {
        return substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text)), 0, 300);
    }
}
