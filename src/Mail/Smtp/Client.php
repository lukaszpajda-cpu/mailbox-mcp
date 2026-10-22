<?php

/*
 * Derived from SecureMessage Mail MCP Server — Pure PHP Socket SMTP Client
 * (securemessage/mail-mcp, SocketSmtpClient.class.php), adapted for
 * mailbox-mcp: blocking line-based I/O bounded by the configured
 * timeout (no Fibers / event loop / XOAUTH2), SmtpClientInterface
 * method set, explicit SMTPUTF8 negotiation and dot-stuffing, and
 * envelope address validation via MailboxMcp\Mail\Mime\Address.
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

namespace MailboxMcp\Mail\Smtp;

use MailboxMcp\Mail\Mime\Address;

/**
 * SMTP client over a plain PHP stream socket (no ext-imap/mail/curl).
 *
 * One instance serves one HTTP request: connect() opens the socket
 * (implicit TLS, STARTTLS or — localhost only, enforced by config —
 * plaintext), reads the greeting, sends EHLO (falling back to HELO),
 * negotiates STARTTLS when configured and authenticates; send() runs
 * one envelope (MAIL FROM / RCPT TO / DATA); close() sends QUIT. Every
 * read is bounded by the configured timeout.
 *
 * Only validated envelope addresses, the dot-stuffed message body and
 * the login credentials ever reach the wire. Credentials are never
 * logged nor included in exception messages, and a server's echoed
 * text is redacted and size-bounded before it reaches one.
 */
final class Client implements SmtpClientInterface
{
    private const LOCALHOSTS = ['127.0.0.1', '::1', 'localhost'];

    private const EHLO_PATTERN = '/^[A-Za-z0-9.-]+$/';

    private const READ_CHUNK = 65536;

    private const MAX_LINE = 1048576;

    /** @var resource|null */
    private $socket = null;

    private ?float $deadlineNs = null;

    private string $buffer = '';

    /** @var array<int, string> */
    private array $extensions = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeoutSeconds,
        private readonly bool $verifyPeer,
        private readonly string $ehloName = 'localhost',
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
            throw new SmtpException("Unsupported SMTP encryption: {$this->encryption}");
        }

        if (1 !== preg_match(self::EHLO_PATTERN, $this->ehloName)) {
            throw new SmtpException('Invalid EHLO name');
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
            throw new SmtpException('request time budget exceeded');
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
            throw new SmtpException("SMTP connection to {$this->host}:{$this->port} failed: " . ('' !== $errstr ? $errstr : "error {$errno}"));
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, max(1, $this->timeoutSeconds));
        $this->socket = $socket;
        $this->buffer = '';
        $this->extensions = [];

        try {
            $this->handshake();
        } catch (SmtpException $e) {
            $this->drop();

            throw $e;
        }
    }

    public function send(string $from, array $recipients, string $raw): void
    {
        $this->requireSocket();

        self::validateAddress($from, 'Sender');

        foreach ($recipients as $recipient) {
            self::validateAddress($recipient, 'Recipient');
        }

        $needsUtf8 = !self::isAscii($from);

        foreach ($recipients as $recipient) {
            $needsUtf8 = $needsUtf8 || !self::isAscii($recipient);
        }

        if ($needsUtf8 && !$this->hasExtension('SMTPUTF8')) {
            throw new SmtpException('Message contains a non-ASCII address but the server does not advertise SMTPUTF8');
        }

        $param = $needsUtf8 ? ' SMTPUTF8' : '';
        $this->write("MAIL FROM:<{$from}>{$param}\r\n");
        $response = $this->readResponse();

        if (250 !== $response['code']) {
            throw new SmtpException("Sender <{$from}> rejected: " . $this->redact($response['text']));
        }

        foreach ($recipients as $recipient) {
            $this->write("RCPT TO:<{$recipient}>\r\n");
            $response = $this->readResponse();

            if (250 !== $response['code'] && 251 !== $response['code']) {
                throw new SmtpException("Recipient <{$recipient}> rejected: " . $this->redact($response['text']));
            }
        }

        $this->write("DATA\r\n");
        $response = $this->readResponse();

        if (354 !== $response['code']) {
            throw new SmtpException('DATA rejected: ' . $this->redact($response['text']));
        }

        $this->write(self::dotStuff($raw));
        $response = $this->readResponse();

        if (250 !== $response['code']) {
            throw new SmtpException('Message rejected: ' . $this->redact($response['text']));
        }
    }

    public function close(): void
    {
        if (null === $this->socket) {
            return;
        }

        try {
            $this->write("QUIT\r\n");
            $this->readResponse();
        } catch (SmtpException) {
            // The server may drop the connection right after QUIT.
        }

        $this->drop();
    }

    // ────────────────────────────────────────────────────────────
    //  Session setup
    // ────────────────────────────────────────────────────────────

    private function handshake(): void
    {
        $greeting = $this->readResponse();

        if (220 !== $greeting['code']) {
            throw new SmtpException('SMTP server rejected the connection: ' . $this->redact($greeting['text']));
        }

        $this->ehlo();

        if ('starttls' === $this->encryption) {
            if (!$this->hasExtension('STARTTLS')) {
                throw new SmtpException('SMTP server does not offer STARTTLS');
            }

            $this->starttls();
            $this->ehlo();
        }

        if ('' !== $this->username) {
            $this->authenticate();
        }
    }

    private function ehlo(): void
    {
        $this->write("EHLO {$this->ehloName}\r\n");
        $response = $this->readResponse();

        if ($response['code'] >= 500 && $response['code'] < 600) {
            $this->write("HELO {$this->ehloName}\r\n");
            $response = $this->readResponse();

            if (250 !== $response['code']) {
                throw new SmtpException('SMTP HELO failed: ' . $this->redact($response['text']));
            }

            $this->extensions = [];

            return;
        }

        if (250 !== $response['code']) {
            throw new SmtpException('SMTP EHLO failed: ' . $this->redact($response['text']));
        }

        $this->extensions = $response['lines'];
    }

    private function starttls(): void
    {
        $this->write("STARTTLS\r\n");
        $response = $this->readResponse();

        if (220 !== $response['code']) {
            throw new SmtpException('SMTP STARTTLS failed: ' . $this->redact($response['text']));
        }

        if ('' !== $this->buffer) {
            // Bytes sent before the handshake could be injected by a MITM.
            throw new SmtpException('SMTP server sent data before the TLS handshake');
        }

        $socket = $this->requireSocket();
        $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if (true !== $ok) {
            throw new SmtpException('SMTP TLS negotiation failed after STARTTLS');
        }

        // Extensions from before TLS must be discarded and re-negotiated.
        $this->extensions = [];
    }

    private function authenticate(): void
    {
        if ('none' === $this->encryption && !in_array(strtolower($this->host), self::LOCALHOSTS, true)) {
            throw new SmtpException('Refusing to authenticate in clear text to a non-local SMTP host');
        }

        $mechanisms = $this->authMechanisms();

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->authPlain();
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->authLogin();
        } else {
            throw new SmtpException('SMTP server offers no supported AUTH mechanism');
        }
    }

    private function authPlain(): void
    {
        $token = base64_encode("\0{$this->username}\0{$this->password}");
        $this->write("AUTH PLAIN {$token}\r\n");
        $response = $this->readResponse();

        if (235 !== $response['code']) {
            throw new SmtpException('SMTP authentication failed');
        }
    }

    private function authLogin(): void
    {
        $this->write("AUTH LOGIN\r\n");
        $response = $this->readResponse();

        if (334 !== $response['code']) {
            throw new SmtpException('SMTP authentication failed');
        }

        $this->write(base64_encode($this->username) . "\r\n");
        $response = $this->readResponse();

        if (334 !== $response['code']) {
            throw new SmtpException('SMTP authentication failed');
        }

        $this->write(base64_encode($this->password) . "\r\n");
        $response = $this->readResponse();

        if (235 !== $response['code']) {
            throw new SmtpException('SMTP authentication failed');
        }
    }

    /**
     * @return array<int, string>
     */
    private function authMechanisms(): array
    {
        foreach ($this->extensions as $line) {
            if (1 === preg_match('/^AUTH[ =](.+)$/i', trim($line), $m)) {
                return array_map('strtoupper', preg_split('/[\s,]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
        }

        return [];
    }

    private function hasExtension(string $name): bool
    {
        $wanted = strtoupper($name);

        foreach ($this->extensions as $line) {
            $line = strtoupper(trim($line));

            if ($line === $wanted || str_starts_with($line, $wanted . ' ')) {
                return true;
            }
        }

        return false;
    }

    // ────────────────────────────────────────────────────────────
    //  Socket I/O
    // ────────────────────────────────────────────────────────────

    /**
     * @return array{code: int, text: string, lines: array<int, string>}
     */
    private function readResponse(): array
    {
        $lines = [];
        $code = 0;
        $text = '';

        while (true) {
            $line = $this->readLine();

            if (1 !== preg_match('/^(\d{3})([ -])(.*)$/', $line, $m)) {
                $this->fail('SMTP server sent a malformed response: ' . self::clean($line));
            }

            $code = (int) $m[1];
            $text = $m[3];
            $lines[] = $text;

            if (' ' === $m[2]) {
                break;
            }
        }

        return ['code' => $code, 'text' => $text, 'lines' => $lines];
    }

    private function readLine(): string
    {
        while (false === ($nl = strpos($this->buffer, "\r\n"))) {
            if (strlen($this->buffer) > self::MAX_LINE) {
                $this->fail('SMTP response line too long');
            }

            $this->pump();
        }

        $line = substr($this->buffer, 0, $nl);
        $this->buffer = substr($this->buffer, $nl + 2);

        return $line;
    }

    private function pump(): void
    {
        $socket = $this->requireSocket();
        $this->assertBudget();
        $left = $this->budgetRemaining();
        $capped = null !== $left && $left < $this->timeoutSeconds;

        if ($capped) {
            // Cap this blocking read to what is left of the request budget.
            $micros = max(1, (int) ceil($left * 1000000));
            stream_set_timeout($socket, intdiv($micros, 1000000), $micros % 1000000);
        }

        $chunk = @fread($socket, self::READ_CHUNK);

        if (false === $chunk || '' === $chunk) {
            $meta = stream_get_meta_data($socket);

            if (true === $meta['timed_out'] && $capped) {
                $this->fail('request time budget exceeded');
            }

            $this->fail(true === $meta['timed_out'] ? 'SMTP read timed out' : 'SMTP connection closed');
        }

        $this->buffer .= $chunk;
    }

    private function write(string $data): void
    {
        $socket = $this->requireSocket();
        $this->assertBudget();
        $offset = 0;
        $total = strlen($data);

        while ($offset < $total) {
            $written = @fwrite($socket, substr($data, $offset));

            if (false === $written || 0 === $written) {
                $meta = stream_get_meta_data($socket);
                $this->fail(true === $meta['timed_out'] ? 'SMTP write timed out' : 'SMTP connection closed');
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
            throw new SmtpException('SMTP client is not connected');
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

        throw new SmtpException($message);
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

    private static function validateAddress(string $email, string $label): void
    {
        if (!Address::isValid($email)) {
            throw new SmtpException("{$label} '{$email}' invalid");
        }
    }

    private static function isAscii(string $value): bool
    {
        return 1 === preg_match('/^[\x00-\x7f]*$/', $value);
    }

    /**
     * Normalises line endings to CRLF, dot-stuffs any line that starts
     * with `.` and appends the `<CRLF>.<CRLF>` terminator, inserting a
     * CRLF first if the message does not already end in one.
     */
    private static function dotStuff(string $raw): string
    {
        $normalised = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $raw);
        $stuffed = (string) preg_replace('/^\./m', '..', $normalised);

        if (!str_ends_with($stuffed, "\r\n")) {
            $stuffed .= "\r\n";
        }

        return $stuffed . ".\r\n";
    }

    /**
     * Server text for exception messages: printable, single line,
     * bounded, with the password redacted.
     */
    private function redact(string $text): string
    {
        if ('' !== $this->password) {
            $text = str_replace($this->password, '***', $text);
        }

        return self::clean($text);
    }

    private static function clean(string $text): string
    {
        return substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text)), 0, 300);
    }
}
