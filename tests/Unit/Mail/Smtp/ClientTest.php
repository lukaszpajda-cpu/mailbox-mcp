<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Smtp;

use MailboxMcp\Mail\Smtp\Client;
use MailboxMcp\Mail\Smtp\SmtpException;
use MailboxMcp\Tests\Support\ScriptedSocketServer;
use PHPUnit\Framework\TestCase;

/**
 * The real socket client against {@see ScriptedSocketServer}: every test
 * spells out the exact wire dialogue, so the scripts double as the
 * protocol spec of each method. SMTP has no tags, so `expect` regexes
 * match the client's lines directly.
 */
final class ClientTest extends TestCase
{
    public function testEhloAuthPlainSend(): void
    {
        $token = base64_encode("\0user\0secret");
        $raw = "Subject: Test\r\n\r\nHello\r\n.Leading dot\r\nBye\r\n";

        $server = ScriptedSocketServer::start([
            ['send' => "220 mail.example.com ESMTP\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250-mail.example.com Hello\r\n250-AUTH PLAIN LOGIN\r\n250 SMTPUTF8\r\n"],
            ['expect' => '/^AUTH PLAIN ' . preg_quote($token, '/') . '$/', 'reply' => "235 Authentication successful\r\n"],
            ['expect' => '/^MAIL FROM:<from@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^RCPT TO:<a@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^RCPT TO:<b@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^DATA$/', 'reply' => "354 Start mail input\r\n"],
            ['expect' => '/^Subject: Test$/'],
            ['expect' => '/^$/'],
            ['expect' => '/^Hello$/'],
            ['expect' => '/^\.\.Leading dot$/'],
            ['expect' => '/^Bye$/'],
            ['expect' => '/^\.$/', 'reply' => "250 OK queued as 12345\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 Bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();
        $client->send('from@example.com', ['a@example.com', 'b@example.com'], $raw);
        $client->close();

        $this->assertSame([
            'EHLO localhost',
            'AUTH PLAIN ' . $token,
            'MAIL FROM:<from@example.com>',
            'RCPT TO:<a@example.com>',
            'RCPT TO:<b@example.com>',
            'DATA',
            'Subject: Test',
            '',
            'Hello',
            '..Leading dot',
            'Bye',
            '.',
            'QUIT',
        ], $server->stop());
    }

    public function testAuthLoginFallback(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250-hi\r\n250 AUTH LOGIN\r\n"],
            ['expect' => '/^AUTH LOGIN$/', 'reply' => "334 VXNlcm5hbWU6\r\n"],
            ['expect' => '/^' . preg_quote(base64_encode('user'), '/') . '$/', 'reply' => "334 UGFzc3dvcmQ6\r\n"],
            ['expect' => '/^' . preg_quote(base64_encode('secret'), '/') . '$/', 'reply' => "235 OK\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();
        $client->close();

        $this->assertSame([
            'EHLO localhost',
            'AUTH LOGIN',
            base64_encode('user'),
            base64_encode('secret'),
            'QUIT',
        ], $server->stop());
    }

    public function testRcptRejectedThrowsWithAddress(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250 hi\r\n"],
            ['expect' => '/^MAIL FROM:<from@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^RCPT TO:<bad@example\.com>$/', 'reply' => "550 No such user\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', '', '', 2, false);
        $client->connect();

        try {
            $client->send('from@example.com', ['bad@example.com'], "Subject: x\r\n\r\nhi\r\n");
            $this->fail('send() should throw');
        } catch (SmtpException $e) {
            $this->assertSame('Recipient <bad@example.com> rejected: No such user', $e->getMessage());
        }

        $client->close();
        $this->assertSame([
            'EHLO localhost',
            'MAIL FROM:<from@example.com>',
            'RCPT TO:<bad@example.com>',
            'QUIT',
        ], $server->stop());
    }

    public function testStarttlsNotOfferedThrows(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250-hi\r\n250 AUTH PLAIN\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'starttls', 'user', 'secret', 2, false);

        try {
            $client->connect();
            $this->fail('connect() should throw');
        } catch (SmtpException $e) {
            $this->assertSame('SMTP server does not offer STARTTLS', $e->getMessage());
        }

        $client->close();
        $this->assertSame(['EHLO localhost'], $server->stop());
    }

    public function testSmtpUtf8RejectedWhenNotAdvertised(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250 hi\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', '', '', 2, false);
        $client->connect();

        try {
            $client->send("from@\u{17c}\u{105}bek.pl", ['to@example.com'], "Subject: x\r\n\r\nhi\r\n");
            $this->fail('send() should throw');
        } catch (SmtpException $e) {
            $this->assertStringContainsString('SMTPUTF8', $e->getMessage());
        }

        $client->close();
        $this->assertSame(['EHLO localhost', 'QUIT'], $server->stop());
    }

    public function testSmtpUtf8ParamWhenRecipientNonAscii(): void
    {
        $recipient = "to@\u{17c}\u{105}bek.pl";

        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250-hi\r\n250 SMTPUTF8\r\n"],
            ['expect' => '/^MAIL FROM:<a@example\.com> SMTPUTF8$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^RCPT TO:<' . preg_quote($recipient, '/') . '>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^DATA$/', 'reply' => "354 Go\r\n"],
            ['expect' => '/^Hi$/'],
            ['expect' => '/^\.$/', 'reply' => "250 Queued\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 Bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', '', '', 2, false);
        $client->connect();
        $client->send('a@example.com', [$recipient], "Hi\r\n");
        $client->close();

        $this->assertSame([
            'EHLO localhost',
            'MAIL FROM:<a@example.com> SMTPUTF8',
            'RCPT TO:<' . $recipient . '>',
            'DATA',
            'Hi',
            '.',
            'QUIT',
        ], $server->stop());
    }

    public function testMultilineEhloParsed(): void
    {
        $token = base64_encode("\0user\0secret");
        $from = "from@\u{17c}\u{105}bek.pl";

        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250-mail.example.com Hello\r\n250-AUTH PLAIN LOGIN\r\n250-SMTPUTF8\r\n250 8BITMIME\r\n"],
            ['expect' => '/^AUTH PLAIN ' . preg_quote($token, '/') . '$/', 'reply' => "235 Auth OK\r\n"],
            ['expect' => '/^MAIL FROM:<' . preg_quote($from, '/') . '> SMTPUTF8$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^RCPT TO:<to@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^DATA$/', 'reply' => "354 Go\r\n"],
            ['expect' => '/^Hi$/'],
            ['expect' => '/^\.$/', 'reply' => "250 Queued\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 Bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();
        $client->send($from, ['to@example.com'], "Hi\r\n");
        $client->close();

        $this->assertSame([
            'EHLO localhost',
            'AUTH PLAIN ' . $token,
            'MAIL FROM:<' . $from . '> SMTPUTF8',
            'RCPT TO:<to@example.com>',
            'DATA',
            'Hi',
            '.',
            'QUIT',
        ], $server->stop());
    }

    public function testDataRejectedThrowsWithText(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250 hi\r\n"],
            ['expect' => '/^MAIL FROM:<from@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^RCPT TO:<to@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^DATA$/', 'reply' => "451 Temporary local problem\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', '', '', 2, false);
        $client->connect();

        try {
            $client->send('from@example.com', ['to@example.com'], "Subject: x\r\n\r\nhi\r\n");
            $this->fail('send() should throw');
        } catch (SmtpException $e) {
            $this->assertSame('DATA rejected: Temporary local problem', $e->getMessage());
        }

        $client->close();
        $this->assertSame([
            'EHLO localhost',
            'MAIL FROM:<from@example.com>',
            'RCPT TO:<to@example.com>',
            'DATA',
            'QUIT',
        ], $server->stop());
    }

    public function testCloseAfterFailedConnectIsNoop(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "421 Service not available\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', '', '', 2, false);

        try {
            $client->connect();
            $this->fail('connect() should throw');
        } catch (SmtpException $e) {
            $this->assertSame('SMTP server rejected the connection: Service not available', $e->getMessage());
        }

        $client->close();
        $client->close();

        $this->assertSame([], $server->stop());
    }

    public function testDotStuffingAndTrailingCrlf(): void
    {
        $raw = "Subject: x\r\n\r\nHello\r\n.\r\nWorld without trailing crlf";

        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250 hi\r\n"],
            ['expect' => '/^MAIL FROM:<from@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^RCPT TO:<to@example\.com>$/', 'reply' => "250 OK\r\n"],
            ['expect' => '/^DATA$/', 'reply' => "354 Go\r\n"],
            ['expect' => '/^Subject: x$/'],
            ['expect' => '/^$/'],
            ['expect' => '/^Hello$/'],
            ['expect' => '/^\.\.$/'],
            ['expect' => '/^World without trailing crlf$/'],
            ['expect' => '/^\.$/', 'reply' => "250 Queued\r\n"],
            ['expect' => '/^QUIT$/', 'reply' => "221 bye\r\n"],
        ]);

        $client = new Client('127.0.0.1', $server->port(), 'none', '', '', 2, false);
        $client->connect();
        $client->send('from@example.com', ['to@example.com'], $raw);
        $client->close();

        $sent = $server->stop();

        $this->assertSame([
            'Subject: x',
            '',
            'Hello',
            '..',
            'World without trailing crlf',
            '.',
        ], array_slice($sent, 4, 6));
    }

    public function testDeadlineAbortsBeforeCommand(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250-hi\r\n250 AUTH PLAIN\r\n"],
            ['expect' => '/^AUTH PLAIN /', 'reply' => "235 OK\r\n"],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();
        $client->setDeadline(hrtime(true) - 1.0);

        try {
            $client->send('from@example.com', ['a@example.com'], "Subject: x\r\n\r\nbody\r\n");
            $this->fail('send() should throw');
        } catch (SmtpException $e) {
            $this->assertSame('request time budget exceeded', $e->getMessage());
        }

        $client->close(); // socket already dropped: no QUIT either
        $this->assertSame(['EHLO localhost', 'AUTH PLAIN ' . base64_encode("\0user\0secret")], $server->stop());
    }

    public function testDeadlineCapsSocketWait(): void
    {
        // MAIL FROM is never answered; the 2 s read timeout would fire
        // later than the 0.3 s request budget.
        $server = ScriptedSocketServer::start([
            ['send' => "220 hi\r\n"],
            ['expect' => '/^EHLO localhost$/', 'reply' => "250 hi\r\n"],
            ['expect' => '/^MAIL FROM:<from@example\.com>$/'],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', '', '', 2, false);
        $client->connect();
        $client->setDeadline(hrtime(true) + 0.3e9);

        $start = microtime(true);

        try {
            $client->send('from@example.com', ['a@example.com'], "Subject: x\r\n\r\nbody\r\n");
            $this->fail('send() should throw');
        } catch (SmtpException $e) {
            $this->assertSame('request time budget exceeded', $e->getMessage());
        }

        $this->assertLessThan(1.5, microtime(true) - $start);
        $this->assertSame(['EHLO localhost', 'MAIL FROM:<from@example.com>'], $server->stop());
    }
}
