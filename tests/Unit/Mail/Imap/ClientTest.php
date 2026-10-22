<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Imap;

use MailboxMcp\Mail\Imap\Client;
use MailboxMcp\Mail\Imap\ImapException;
use MailboxMcp\Mail\Imap\Literal;
use MailboxMcp\Mail\Mime\BodyStructure;
use MailboxMcp\Tests\Support\ScriptedSocketServer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The real socket client against {@see ScriptedSocketServer}: every test
 * spells out the exact wire dialogue, so the scripts double as the
 * protocol spec of each method.
 */
final class ClientTest extends TestCase
{
    private const CAPS = 'IMAP4rev1 UIDPLUS SPECIAL-USE LITERAL+';

    private const SUMMARY_ITEMS = '(UID FLAGS RFC822.SIZE INTERNALDATE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC REPLY-TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES)])';

    public function testLoginAndCapabilities(): void
    {
        [$caps, $sent] = $this->dialogue([
            ['send' => "* OK [CAPABILITY IMAP4rev1 UIDPLUS SPECIAL-USE LITERAL+] Dovecot ready.\r\n"],
            ['expect' => '/^A001 LOGIN /', 'reply' => "{tag} OK Logged in\r\n"],
            ['expect' => '/^A002 CAPABILITY$/', 'reply' => "* CAPABILITY IMAP4rev1 UIDPLUS SPECIAL-USE LITERAL+ MOVE\r\n{tag} OK done\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->capabilities());

        $this->assertSame(['IMAP4REV1', 'UIDPLUS', 'SPECIAL-USE', 'LITERAL+', 'MOVE'], $caps);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 CAPABILITY', 'A003 LOGOUT'], $sent);
    }

    public function testGreetingWithoutCapabilityAsksAndUsesPostLoginCode(): void
    {
        [$caps, $sent] = $this->dialogue([
            ['send' => "* OK ready\r\n"],
            ['expect' => '/^A001 CAPABILITY$/', 'reply' => "* CAPABILITY IMAP4rev1 AUTH=PLAIN\r\n{tag} OK\r\n"],
            ['expect' => '/^A002 LOGIN /', 'reply' => "{tag} OK [CAPABILITY IMAP4rev1 UIDPLUS] Logged in\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->capabilities());

        $this->assertSame(['IMAP4REV1', 'UIDPLUS'], $caps);
        $this->assertSame(['A001 CAPABILITY', 'A002 LOGIN "user" "secret"', 'A003 LOGOUT'], $sent);
    }

    public function testLoginWithQuoteUsesLiteral(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK [CAPABILITY IMAP4rev1 LITERAL+] ready\r\n"],
            ['expect' => '/^A001 LOGIN \{5\}$/', 'reply' => "+ go\r\n"],
            ['read' => 5],
            ['expect' => '/^ \{6\}$/', 'reply' => "+ go\r\n"],
            ['read' => 6],
            ['expect' => '/^$/', 'reply' => "{tag} OK [CAPABILITY IMAP4rev1] Logged in\r\n"],
            ...$this->logout('A002'),
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', "us\u{e9}r", 'pa"ss\\', 2, false);
        $client->connect();
        $client->close();

        $this->assertSame(['A001 LOGIN {5}', "us\u{e9}r", ' {6}', 'pa"ss\\', '', 'A002 LOGOUT'], $server->stop());
    }

    public function testLoginFailureThrowsWithoutCredentials(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK [CAPABILITY IMAP4rev1] ready\r\n"],
            ['expect' => '/^A001 LOGIN "user" "secret"$/', 'reply' => "{tag} NO [AUTHENTICATIONFAILED] Authentication failed.\r\n"],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);

        try {
            $client->connect();
            $this->fail('connect() should throw');
        } catch (ImapException $e) {
            $this->assertSame('IMAP LOGIN failed: [AUTHENTICATIONFAILED] Authentication failed.', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }

        $client->close();
        $this->assertSame(['A001 LOGIN "user" "secret"'], $server->stop());
    }

    public function testServerEchoOfPasswordIsRedacted(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK [CAPABILITY IMAP4rev1] ready\r\n"],
            ['expect' => '/^A001 LOGIN "user" "secret"$/', 'reply' => "{tag} BAD syntax error in: LOGIN \"user\" \"secret\"\r\n"],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);

        try {
            $client->connect();
            $this->fail('connect() should throw');
        } catch (ImapException $e) {
            $this->assertSame('IMAP LOGIN failed: syntax error in: LOGIN "user" "***"', $e->getMessage());
        }

        $server->stop();
    }

    public function testStarttlsRefusedThrows(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK [CAPABILITY IMAP4rev1 STARTTLS] ready\r\n"],
            ['expect' => '/^A001 STARTTLS$/', 'reply' => "{tag} NO not now\r\n"],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'starttls', 'user', 'secret', 2, false);

        try {
            $client->connect();
            $this->fail('connect() should throw');
        } catch (ImapException $e) {
            $this->assertSame('IMAP STARTTLS failed: not now', $e->getMessage());
        }

        $this->assertSame(['A001 STARTTLS'], $server->stop());
    }

    public function testListParsesSpecialUseAndQuotedNames(): void
    {
        [$folders, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 LIST "" "\*"$/', 'reply' => "* LIST (\\HasNoChildren) \".\" INBOX\r\n"
                . "* LIST (\\HasNoChildren \\Sent) \".\" \"Sent Messages\"\r\n"
                . "* LIST (\\Noselect \\HasChildren) NIL \"Shared\"\r\n"
                . "* LIST () \"/\" {7}\r\nA \"q\" B\r\n"
                . "{tag} OK List completed\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->listFolders());

        $this->assertCount(4, $folders);
        $this->assertSame('INBOX', $folders[0]->name);
        $this->assertSame('.', $folders[0]->delimiter);
        $this->assertSame(['\hasnochildren'], $folders[0]->attributes);
        $this->assertSame('Sent Messages', $folders[1]->name);
        $this->assertSame(['\hasnochildren', '\sent'], $folders[1]->attributes);
        $this->assertSame('Shared', $folders[2]->name);
        $this->assertSame('/', $folders[2]->delimiter);
        $this->assertSame(['\noselect', '\haschildren'], $folders[2]->attributes);
        $this->assertSame('A "q" B', $folders[3]->name);
        $this->assertSame([], $folders[3]->attributes);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 LIST "" "*"', 'A003 LOGOUT'], $sent);
    }

    public function testListDecodesModifiedUtf7(): void
    {
        [$result, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 LIST /', 'reply' => "* LIST (\\HasNoChildren) \"/\" \"Archiwum/Wa&AXw-ne\"\r\n"
                . "* LIST (\\HasNoChildren) \"/\" \"R&-D\"\r\n{tag} OK\r\n"],
            ['expect' => '/^A003 STATUS "Archiwum\/Wa&AXw-ne" \(MESSAGES UNSEEN\)$/', 'reply' => "* STATUS \"Archiwum/Wa&AXw-ne\" (MESSAGES 2 UNSEEN 1)\r\n{tag} OK\r\n"],
            ['expect' => '/^A004 STATUS "R&-D" \(MESSAGES UNSEEN\)$/', 'reply' => "* STATUS \"R&-D\" (MESSAGES 0 UNSEEN 0)\r\n{tag} OK\r\n"],
            ...$this->logout('A005'),
        ], function (Client $c): array {
            $folders = $c->listFolders();

            return [$folders[0]->name, $folders[1]->name, $c->status($folders[0]->name), $c->status($folders[1]->name)];
        });

        $this->assertSame(['Archiwum/Ważne', 'R&D', ['messages' => 2, 'unseen' => 1], ['messages' => 0, 'unseen' => 0]], $result);
        $this->assertSame('A003 STATUS "Archiwum/Wa&AXw-ne" (MESSAGES UNSEEN)', $sent[2]);
    }

    public function testStatusCounts(): void
    {
        [$status, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 STATUS /', 'reply' => "* STATUS \"INBOX\" (MESSAGES 12 UNSEEN 3)\r\n{tag} OK Status completed\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->status('INBOX'));

        $this->assertSame(['messages' => 12, 'unseen' => 3], $status);
        $this->assertSame('A002 STATUS "INBOX" (MESSAGES UNSEEN)', $sent[1]);
    }

    public function testSelectAndExamine(): void
    {
        [$result, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 EXAMINE "INBOX"$/', 'reply' => "* FLAGS (\\Seen \\Deleted)\r\n* 17 EXISTS\r\n* 0 RECENT\r\n"
                . "* OK [UIDVALIDITY 3857529045] UIDs valid\r\n* OK [UIDNEXT 4392] Predicted next UID\r\n{tag} OK [READ-ONLY] done\r\n"],
            ['expect' => '/^A003 SELECT "Drafts"$/', 'reply' => "* 0 EXISTS\r\n* OK [UIDVALIDITY 7] x\r\n* OK [UIDNEXT 1] y\r\n{tag} OK [READ-WRITE] done\r\n"],
            ...$this->logout('A004'),
        ], fn (Client $c): array => [$c->select('INBOX'), $c->select('Drafts', false)]);

        $this->assertSame([
            ['exists' => 17, 'uidvalidity' => 3857529045, 'uidnext' => 4392],
            ['exists' => 0, 'uidvalidity' => 7, 'uidnext' => 1],
        ], $result);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 EXAMINE "INBOX"', 'A003 SELECT "Drafts"', 'A004 LOGOUT'], $sent);
    }

    public function testSearchWithCharsetAndBadcharsetRetry(): void
    {
        [$result, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID SEARCH CHARSET UTF-8 FROM "x"$/', 'reply' => "* SEARCH 9 3 5\r\n{tag} OK\r\n"],
            ['expect' => '/^A003 UID SEARCH CHARSET UTF-8 SUBJECT \{10\+\}$/'],
            ['read' => 10],
            ['expect' => '/^ UNSEEN$/', 'reply' => "{tag} NO [BADCHARSET (US-ASCII)] charset not supported\r\n"],
            ['expect' => '/^A004 UID SEARCH SUBJECT "[\x20-\x21\x23-\x5b\x5d-\x7e]*" UNSEEN$/', 'reply' => "* SEARCH 4\r\n{tag} OK\r\n"],
            ['expect' => '/^A005 UID SEARCH ALL$/', 'reply' => "* SEARCH\r\n{tag} OK\r\n"],
            ...$this->logout('A006'),
        ], fn (Client $c): array => [$c->search('FROM "x"'), $c->search("SUBJECT \"zażółć\" UNSEEN"), $c->search('ALL', null)]);

        $this->assertSame([[3, 5, 9], [4], []], $result);
        $this->assertSame('A002 UID SEARCH CHARSET UTF-8 FROM "x"', $sent[1]);
        $this->assertSame("za\u{17c}\u{f3}\u{142}\u{107}", $sent[3]);
        $this->assertSame('A005 UID SEARCH ALL', $sent[6]);
    }

    public function testSearchSendsLiteralForEightBitWithLiteralPlus(): void
    {
        [$uids, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID SEARCH CHARSET UTF-8 FROM \{2\+\}$/'],
            ['read' => 2],
            ['expect' => '/^ SUBJECT \{5\+\}$/'],
            ['read' => 5],
            ['expect' => '/^ TO "plain@x"$/', 'reply' => "* SEARCH 4 2\r\n{tag} OK\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->search("FROM \"\u{105}\" SUBJECT \"a\\\"\u{142}\\\\\" TO \"plain@x\""));

        $this->assertSame([2, 4], $uids);
        $this->assertSame([
            'A001 LOGIN "user" "secret"',
            'A002 UID SEARCH CHARSET UTF-8 FROM {2+}',
            "\u{105}",
            ' SUBJECT {5+}',
            "a\"\u{142}\\",
            ' TO "plain@x"',
            'A003 LOGOUT',
        ], $sent);
    }

    public function testSearchSendsLiteralWithContinuationWithoutLiteralPlus(): void
    {
        [$uids, $sent] = $this->dialogue([
            ...$this->login('IMAP4rev1 UIDPLUS'),
            ['expect' => '/^A002 UID SEARCH CHARSET UTF-8 FROM \{2\}$/', 'reply' => "+ go\r\n"],
            ['read' => 2],
            ['expect' => '/^ UNSEEN$/', 'reply' => "* SEARCH 7\r\n{tag} OK\r\n"],
            ['expect' => '/^A003 UID SEARCH CHARSET UTF-8 FROM \{2\}$/', 'reply' => "{tag} NO [BADCHARSET (US-ASCII)] no\r\n"],
            ['expect' => '/^A004 UID SEARCH FROM "[\x20-\x21\x23-\x5b\x5d-\x7e]*"$/', 'reply' => "* SEARCH 8\r\n{tag} OK\r\n"],
            ...$this->logout('A005'),
        ], fn (Client $c): array => [$c->search("FROM \"\u{105}\" UNSEEN"), $c->search("FROM \"\u{105}\"")]);

        $this->assertSame([[7], [8]], $uids);
        $this->assertSame(['A002 UID SEARCH CHARSET UTF-8 FROM {2}', "\u{105}", ' UNSEEN'], array_slice($sent, 1, 3));
    }

    public function testSevenBitSearchCriteriaStayQuoted(): void
    {
        [, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID SEARCH CHARSET UTF-8 FROM "a\\\\"b" SUBJECT "x"$/', 'reply' => "* SEARCH\r\n{tag} OK\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->search('FROM "a\\"b" SUBJECT "x"'));

        $this->assertSame('A002 UID SEARCH CHARSET UTF-8 FROM "a\\"b" SUBJECT "x"', $sent[1]);
    }

    public function testTaggedNoWithBraceTextIsNotALiteral(): void
    {
        $started = microtime(true);

        [$result] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 STATUS "INBOX" /', 'reply' => "* OK [ALERT] quota {3}\r\n* STATUS INBOX (MESSAGES 1 UNSEEN 0)\r\n{tag} OK done {5}\r\n"],
            ['expect' => '/^A003 STATUS "X" /', 'reply' => "{tag} NO too big {5}\r\n"],
            ...$this->logout('A004'),
        ], fn (Client $c): array => [$c->status('INBOX'), $this->catch(fn () => $c->status('X'))]);

        $this->assertSame(['messages' => 1, 'unseen' => 0], $result[0]);
        $this->assertInstanceOf(ImapException::class, $result[1]);
        $this->assertSame('IMAP STATUS failed: too big {5}', $result[1]->getMessage());
        $this->assertLessThan(1.5, microtime(true) - $started);
    }

    public function testEsearchResponseParsed(): void
    {
        [$uids] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID SEARCH CHARSET UTF-8 ALL$/', 'reply' => "* ESEARCH (TAG \"A002\") UID ALL 1:3,5\r\n{tag} OK\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->search('ALL'));

        $this->assertSame([1, 2, 3, 5], $uids);
    }

    public function testSearchCriteriaWithCrlfRejected(): void
    {
        [$e, $sent] = $this->dialogue([
            ...$this->login(),
            ...$this->logout('A002'),
        ], fn (Client $c): ?Throwable => $this->catch(fn () => $c->search("ALL\r\nA9 DELETE INBOX")));

        $this->assertInstanceOf(ImapException::class, $e);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 LOGOUT'], $sent);
    }

    public function testFetchSummariesParsesLiteralsAndBodystructure(): void
    {
        $h7 = "From: a@example.com\r\nSubject: Hi\r\n\r\n";
        $h9 = "From: b@example.com\r\nSubject: =?UTF-8?Q?Za=C5=BC=C3=B3=C5=82=C4=87?=\r\n\r\n";
        $bs7 = '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 12 1 NIL NIL NIL NIL)';
        $bs9wire = "((\"TEXT\" \"PLAIN\" NIL NIL NIL \"7BIT\" 3 1 NIL NIL NIL NIL)(\"APPLICATION\" \"PDF\" (\"NAME\" {9}\r\n"
            . "r\"apo.pdf) NIL NIL \"BASE64\" 100 NIL (\"ATTACHMENT\" (\"FILENAME\" \"r.pdf\")) NIL NIL) \"MIXED\" (\"BOUNDARY\" \"b1\") NIL NIL NIL)";
        $bs9 = '(("TEXT" "PLAIN" NIL NIL NIL "7BIT" 3 1 NIL NIL NIL NIL)("APPLICATION" "PDF" ("NAME" "r\"apo.pdf") NIL NIL "BASE64" 100 NIL ("ATTACHMENT" ("FILENAME" "r.pdf")) NIL NIL) "MIXED" ("BOUNDARY" "b1") NIL NIL NIL)';
        $hdrKey = 'BODY[HEADER.FIELDS (FROM TO CC REPLY-TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES)]';

        [$summaries, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID FETCH 7,9,11 /', 'reply' => "* 1 FETCH (UID 7 FLAGS (\\Seen \$ClaudeSeen) RFC822.SIZE 1234 INTERNALDATE \"20-Oct-2026 10:00:00 +0000\" BODYSTRUCTURE {$bs7} {$hdrKey} {" . strlen($h7) . "}\r\n{$h7})\r\n"
                . "* 2 FETCH (UID 9 FLAGS () RFC822.SIZE 99 INTERNALDATE NIL {$hdrKey} {" . strlen($h9) . "}\r\n{$h9} BODYSTRUCTURE {$bs9wire})\r\n"
                . "* 3 FETCH (FLAGS (\\Seen))\r\n"
                . "{tag} OK Fetch completed\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => $c->fetchSummaries([7, 9, 11]));

        $this->assertSame('A002 UID FETCH 7,9,11 ' . self::SUMMARY_ITEMS, $sent[1]);
        $this->assertSame([7, 9], array_keys($summaries));
        $this->assertSame([
            'flags' => ['\Seen', '$ClaudeSeen'],
            'size' => 1234,
            'internaldate' => '20-Oct-2026 10:00:00 +0000',
            'headers' => $h7,
            'bodystructure' => $bs7,
        ], $summaries[7]);
        $this->assertSame([], $summaries[9]['flags']);
        $this->assertNull($summaries[9]['internaldate']);
        $this->assertSame($h9, $summaries[9]['headers']);
        $this->assertSame($bs9, $summaries[9]['bodystructure']);

        $attachments = BodyStructure::attachments(BodyStructure::parse($summaries[9]['bodystructure']));
        $this->assertCount(1, $attachments);
        $this->assertSame('2', $attachments[0]->section);
    }

    public function testFetchSummariesRangeCompression(): void
    {
        [$summaries, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID FETCH 1:3,5 \(UID /', 'reply' => "{tag} OK Fetch completed\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): array => [$c->fetchSummaries([5, 1, 3, 2, 3]), $c->fetchSummaries([])]);

        $this->assertSame([[], []], $summaries);
        $this->assertSame('A002 UID FETCH 1:3,5 ' . self::SUMMARY_ITEMS, $sent[1]);
        $this->assertCount(3, $sent);
    }

    public function testFetchSectionPartial(): void
    {
        [$result, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID FETCH 4 \(BODY\.PEEK\[1\.2\]<0\.100>\)$/', 'reply' => "* 2 FETCH (UID 4 BODY[1.2]<0> {5}\r\nhello)\r\n{tag} OK\r\n"],
            ['expect' => '/^A003 UID FETCH 4 \(BODY\.PEEK\[TEXT\]\)$/', 'reply' => "* 2 FETCH (BODY[TEXT] \"hi \\\"there\\\"\" UID 4)\r\n{tag} OK\r\n"],
            ['expect' => '/^A004 UID FETCH 5 \(BODY\.PEEK\[1\.MIME\]\)$/', 'reply' => "{tag} OK\r\n"],
            ...$this->logout('A005'),
        ], fn (Client $c): array => [$c->fetchSection(4, '1.2', 100), $c->fetchSection(4, 'TEXT'), $c->fetchSection(5, '1.MIME')]);

        $this->assertSame(['hello', 'hi "there"', ''], $result);
        $this->assertSame('A002 UID FETCH 4 (BODY.PEEK[1.2]<0.100>)', $sent[1]);
        $this->assertSame('A003 UID FETCH 4 (BODY.PEEK[TEXT])', $sent[2]);
    }

    public function testFetchHeadersAndRaw(): void
    {
        $raw = "Subject: x\r\n\r\nbody {3}\r\n";

        [$result, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID FETCH 8 \(BODY\.PEEK\[HEADER\]\)$/', 'reply' => "* 1 FETCH (UID 8 BODY[HEADER] {14}\r\nSubject: x\r\n\r\n)\r\n{tag} OK\r\n"],
            ['expect' => '/^A003 UID FETCH 8 \(BODY\.PEEK\[\]\)$/', 'reply' => "* 1 FETCH (UID 8 BODY[] {" . strlen($raw) . "}\r\n{$raw} FLAGS (\\Seen))\r\n{tag} OK\r\n"],
            ...$this->logout('A004'),
        ], fn (Client $c): array => [$c->fetchHeaders(8), $c->fetchRaw(8)]);

        $this->assertSame(["Subject: x\r\n\r\n", $raw], $result);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 UID FETCH 8 (BODY.PEEK[HEADER])', 'A003 UID FETCH 8 (BODY.PEEK[])', 'A004 LOGOUT'], $sent);
    }

    public function testInvalidSectionRejected(): void
    {
        [$errors, $sent] = $this->dialogue([
            ...$this->login(),
            ...$this->logout('A002'),
        ], fn (Client $c): array => [
            $this->catch(fn () => $c->fetchSection(1, '1]<0.1> BODY[')),
            $this->catch(fn () => $c->fetchSection(1, 'HEADER.FIELDS (X)')),
        ]);

        $this->assertInstanceOf(ImapException::class, $errors[0]);
        $this->assertInstanceOf(ImapException::class, $errors[1]);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 LOGOUT'], $sent);
    }

    public function testStoreFlags(): void
    {
        [$result, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID STORE 1:3 \+FLAGS\.SILENT \(\\\\Seen \$ClaudeSeen\)$/', 'reply' => "{tag} OK Store completed\r\n"],
            ['expect' => '/^A003 UID STORE 7 -FLAGS\.SILENT \(\\\\Flagged\)$/', 'reply' => "* 4 FETCH (UID 7 FLAGS ())\r\n{tag} OK\r\n"],
            ...$this->logout('A004'),
        ], fn (Client $c): array => [
            $c->storeFlags([1, 2, 3], ['\Seen', '$ClaudeSeen'], true),
            $c->storeFlags([7], ['\Flagged'], false),
            $this->catch(fn () => $c->storeFlags([7], ['\Seen) UID EXPUNGE 1:*'], true)),
        ]);

        $this->assertSame(3, $result[0]);
        $this->assertSame(1, $result[1]);
        $this->assertInstanceOf(ImapException::class, $result[2]);
        $this->assertSame('A002 UID STORE 1:3 +FLAGS.SILENT (\Seen $ClaudeSeen)', $sent[1]);
        $this->assertSame('A003 UID STORE 7 -FLAGS.SILENT (\Flagged)', $sent[2]);
        $this->assertCount(4, $sent);
    }

    public function testAppendReturnsAppendUid(): void
    {
        $raw = "Subject: x\n\nline1\r\nline2\n";
        $wire = "Subject: x\r\n\r\nline1\r\nline2\r\n";

        [$uid, $sent] = $this->dialogue([
            ['send' => "* OK [CAPABILITY IMAP4rev1] ready\r\n"],
            ['expect' => '/^A001 LOGIN /', 'reply' => "{tag} OK [CAPABILITY IMAP4rev1 UIDPLUS] Logged in\r\n"],
            ['expect' => '/^A002 APPEND "Sent" \(\\\\Seen\) \{' . strlen($wire) . '\}$/', 'reply' => "+ Ready for literal data\r\n"],
            ['read' => strlen($wire)],
            ['expect' => '/^$/', 'reply' => "{tag} OK [APPENDUID 38505 3955] APPEND completed\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): ?int => $c->append('Sent', $raw, ['\Seen']));

        $this->assertSame(3955, $uid);
        $this->assertSame('A002 APPEND "Sent" (\Seen) {' . strlen($wire) . '}', $sent[1]);
        $this->assertSame($wire, $sent[2]);
    }

    public function testAppendLiteralPlusWhenAdvertised(): void
    {
        $wire = "Subject: y\r\n\r\nhi\r\n";

        [$uid, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 APPEND "Wa&AXw-ne" \(\) \{' . strlen($wire) . '\+\}$/'],
            ['read' => strlen($wire)],
            ['expect' => '/^$/', 'reply' => "{tag} OK APPEND completed\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): ?int => $c->append('Ważne', $wire, []));

        $this->assertNull($uid);
        $this->assertSame('A002 APPEND "Wa&AXw-ne" () {' . strlen($wire) . '+}', $sent[1]);
        $this->assertSame($wire, $sent[2]);
    }

    public function testExpungeUidRequiresUidplus(): void
    {
        [$without, $sent] = $this->dialogue([
            ['send' => "* OK [CAPABILITY IMAP4rev1] ready\r\n"],
            ['expect' => '/^A001 LOGIN /', 'reply' => "{tag} OK [CAPABILITY IMAP4rev1 MOVE] Logged in\r\n"],
            ...$this->logout('A002'),
        ], fn (Client $c): bool => $c->expungeUid(42));

        $this->assertFalse($without);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 LOGOUT'], $sent);

        [$with, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 UID EXPUNGE 42$/', 'reply' => "* 3 EXPUNGE\r\n{tag} OK Expunge completed\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): bool => $c->expungeUid(42));

        $this->assertTrue($with);
        $this->assertSame('A002 UID EXPUNGE 42', $sent[1]);
    }

    public function testCreateFolder(): void
    {
        [, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 CREATE "Drafts"$/', 'reply' => "{tag} OK Create completed\r\n"],
            ...$this->logout('A003'),
        ], function (Client $c): void {
            $c->createFolder('Drafts');
        });

        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 CREATE "Drafts"', 'A003 LOGOUT'], $sent);
    }

    public function testCreateFolderAlreadyExistsThrows(): void
    {
        [$e, $sent] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 CREATE "Drafts"$/', 'reply' => "{tag} NO [ALREADYEXISTS] Mailbox already exists\r\n"],
            ...$this->logout('A003'),
        ], fn (Client $c): ?Throwable => $this->catch(fn () => $c->createFolder('Drafts')));

        $this->assertInstanceOf(ImapException::class, $e);
        $this->assertSame('IMAP CREATE failed: [ALREADYEXISTS] Mailbox already exists', $e->getMessage());
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 CREATE "Drafts"', 'A003 LOGOUT'], $sent);
    }

    public function testNoResponseThrowsWithServerText(): void
    {
        [$e] = $this->dialogue([
            ...$this->login(),
            ['expect' => '/^A002 STATUS "Missing" /', 'reply' => "{tag} NO [NONEXISTENT] Mailbox doesn't exist: Missing\r\n"],
            ['expect' => '/^A003 EXAMINE "INBOX"$/', 'reply' => "{tag} BAD Error in IMAP command\r\n"],
            ...$this->logout('A004'),
        ], fn (Client $c): array => [
            $this->catch(fn () => $c->status('Missing')),
            $this->catch(fn () => $c->select('INBOX')),
        ]);

        $this->assertInstanceOf(ImapException::class, $e[0]);
        $this->assertSame("IMAP STATUS failed: [NONEXISTENT] Mailbox doesn't exist: Missing", $e[0]->getMessage());
        $this->assertInstanceOf(ImapException::class, $e[1]);
        $this->assertSame('IMAP EXAMINE failed: Error in IMAP command', $e[1]->getMessage());
    }

    public function testReadTimeoutThrows(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK [CAPABILITY IMAP4rev1] ready\r\n"],
            ['expect' => '/^A001 LOGIN /'],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 1, false);
        $started = microtime(true);

        try {
            $client->connect();
            $this->fail('connect() should time out');
        } catch (ImapException $e) {
            $this->assertSame('IMAP read timed out', $e->getMessage());
        }

        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $this->assertLessThan(2.5, $elapsed);
        $client->close();
        $this->assertSame(['A001 LOGIN "user" "secret"'], $server->stop());
    }

    public function testDisconnectThrows(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK [CAPABILITY IMAP4rev1] ready\r\n"],
            ['expect' => '/^A001 LOGIN /', 'reply' => "{tag} OK Logged in\r\n"],
            ['expect' => '/^A002 CAPABILITY$/', 'reply' => "* CAPABILITY IMAP4rev1\r\n{tag} OK\r\n"],
            ['expect' => '/^A003 STATUS /', 'reply' => "* BYE shutting down\r\n"],
            ['close' => true],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();

        try {
            $client->status('INBOX');
            $this->fail('status() should throw');
        } catch (ImapException $e) {
            $this->assertSame('IMAP connection closed', $e->getMessage());
        }

        $client->close();
        $client->close();
        $this->assertCount(3, $server->stop());
    }

    public function testNotConnectedThrows(): void
    {
        $client = new Client('127.0.0.1', 1, 'none', 'user', 'secret', 1, false);

        $this->expectException(ImapException::class);
        $client->listFolders();
    }

    public function testQuoteRejectsCrlf(): void
    {
        $this->assertSame('"a \\"b\\" \\\\c"', Literal::quote('a "b" \\c'));

        $this->expectException(ImapException::class);
        Literal::quote("a\r\nb");
    }

    public function testFolderNameWithCrlfRejected(): void
    {
        [$errors, $sent] = $this->dialogue([
            ...$this->login(),
            ...$this->logout('A002'),
        ], fn (Client $c): array => [
            $this->catch(fn () => $c->status("INBOX\r\nA003 DELETE INBOX")),
            $this->catch(fn () => $c->select("INBOX\0")),
            $this->catch(fn () => $c->append("Sent\n", "x\r\n", [])),
        ]);

        foreach ($errors as $e) {
            $this->assertInstanceOf(ImapException::class, $e);
        }

        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 LOGOUT'], $sent);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function login(string $caps = self::CAPS): array
    {
        return [
            ['send' => "* OK [CAPABILITY {$caps}] ready\r\n"],
            ['expect' => '/^A001 LOGIN "user" "secret"$/', 'reply' => "{tag} OK [CAPABILITY {$caps}] Logged in\r\n"],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function logout(string $tag): array
    {
        return [['expect' => '/^' . $tag . ' LOGOUT$/', 'reply' => "* BYE Logging out\r\n{tag} OK Logout completed\r\n"]];
    }

    /**
     * Runs $fn against a client connected to a scripted server, closes
     * the client and returns [$fn's result, lines the client sent]. A
     * scripting failure (unexpected line) wins over the client's own
     * exception so the message shows what was actually sent.
     *
     * @param array<int, array<string, bool|int|string>> $steps
     *
     * @return array{0: mixed, 1: array<int, string>}
     */
    private function dialogue(array $steps, callable $fn): array
    {
        $server = ScriptedSocketServer::start($steps);
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);

        try {
            $client->connect();
            $result = $fn($client);
            $client->close();
        } catch (Throwable $e) {
            $client->close();

            try {
                $server->stop();
            } catch (AssertionFailedError $failure) {
                throw new AssertionFailedError($failure->getMessage() . "\nClient error: " . $e->getMessage());
            }

            throw $e;
        }

        return [$result, $server->stop()];
    }

    private function catch(callable $fn): ?Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    public function testDeadlineAbortsBeforeCommand(): void
    {
        $server = ScriptedSocketServer::start($this->login());
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();
        $client->setDeadline(hrtime(true) - 1.0);

        $e = $this->catch(fn () => $client->select('INBOX'));

        $this->assertInstanceOf(ImapException::class, $e);
        $this->assertSame('request time budget exceeded', $e->getMessage());
        $client->close(); // socket already dropped: no LOGOUT either
        $this->assertSame(['A001 LOGIN "user" "secret"'], $server->stop());
    }

    public function testDeadlineCapsSocketWait(): void
    {
        // The server never answers EXAMINE; the 2 s idle timeout would fire
        // later than the 0.3 s request budget.
        $server = ScriptedSocketServer::start([
            ...$this->login(),
            ['expect' => '/^A002 EXAMINE "INBOX"$/'],
        ]);
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();
        $client->setDeadline(hrtime(true) + 0.3e9);

        $start = microtime(true);
        $e = $this->catch(fn () => $client->select('INBOX'));
        $elapsed = microtime(true) - $start;

        $this->assertInstanceOf(ImapException::class, $e);
        $this->assertSame('request time budget exceeded', $e->getMessage());
        $this->assertLessThan(1.5, $elapsed);
        $this->assertSame(['A001 LOGIN "user" "secret"', 'A002 EXAMINE "INBOX"'], $server->stop());
    }

    public function testDeadlinePastRefusesToConnect(): void
    {
        $client = new Client('127.0.0.1', 1, 'none', 'user', 'secret', 2, false);
        $client->setDeadline(hrtime(true) - 1.0);

        $e = $this->catch(fn () => $client->connect());

        $this->assertInstanceOf(ImapException::class, $e);
        $this->assertSame('request time budget exceeded', $e->getMessage());
    }

    public function testSelectWaitNeverNegative(): void
    {
        $server = ScriptedSocketServer::start($this->login());
        $client = new Client('127.0.0.1', $server->port(), 'none', 'user', 'secret', 2, false);
        $client->connect();
        $client->setDeadline(hrtime(true) - 1);

        // Straight into the read loop (as if the deadline passed between
        // sending a command and waiting for its reply): must be the budget
        // ImapException, never a ValueError from a negative select wait.
        $pump = new \ReflectionMethod($client, 'pump');
        $e = $this->catch(fn () => $pump->invoke($client));
        $this->assertInstanceOf(ImapException::class, $e);
        $this->assertSame('request time budget exceeded', $e->getMessage());

        $client->close();
        $this->assertSame(['A001 LOGIN "user" "secret"'], $server->stop());
    }
}
