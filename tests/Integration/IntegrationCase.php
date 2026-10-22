<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Integration;

use DateTimeImmutable;
use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Config\Validator;
use MailboxMcp\Mail\Imap\ImapException;
use MailboxMcp\Mail\Imap\SearchCriteria;
use MailboxMcp\Mail\MailSession;
use MailboxMcp\Mail\MailSessionFactory;
use MailboxMcp\Support\SystemClock;
use MailboxMcp\Tools\CreateDraftTool;
use MailboxMcp\Tools\GetEmailTool;
use MailboxMcp\Tools\GetReplyGuidelinesTool;
use MailboxMcp\Tools\GetThreadTool;
use MailboxMcp\Tools\ListAccountsTool;
use MailboxMcp\Tools\ListFoldersTool;
use MailboxMcp\Tools\MarkProcessedTool;
use MailboxMcp\Tools\Registry;
use MailboxMcp\Tools\SearchEmailsTool;
use MailboxMcp\Tools\SendDraftTool;
use MailboxMcp\Tools\ToolResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base class for the Integration suite (end-to-end tests against a real
 * IMAP/SMTP server, through the public tool surface only). See
 * tests/Integration/README.md for how to run these locally.
 *
 * Every test skips (via {@see setUp()}) unless `MAILBOX_MCP_TEST_IMAP_HOST`
 * is set; the full list of environment variables is documented there.
 * Each subclass test method should pick its own random tag
 * (`bin2hex(random_bytes(4))`) for any fixture it appends, so re-running
 * the suite against the same mailbox never collides with leftovers from
 * a previous run; nothing is cleaned up afterwards.
 */
abstract class IntegrationCase extends TestCase
{
    protected const ACCOUNT_ID = 'test';

    private Registry $registry;

    private Account $account;

    /** @var array<string, mixed> */
    private array $limits;

    private ?string $varDir = null;

    protected function setUp(): void
    {
        if ('' === (string) getenv('MAILBOX_MCP_TEST_IMAP_HOST')) {
            self::markTestSkipped('set MAILBOX_MCP_TEST_* to run integration tests');
        }

        $this->varDir = sys_get_temp_dir() . '/mailbox-mcp-it-' . bin2hex(random_bytes(8));

        if (!mkdir($this->varDir, 0700, true) && !is_dir($this->varDir)) {
            throw new RuntimeException("Cannot create {$this->varDir}");
        }

        $config = Validator::validate($this->rawConfig($this->varDir));
        $accounts = AccountResolver::fromConfig($config);
        $this->account = $accounts->default();
        /** @var array<string, mixed> $limits */
        $limits = $config['limits'];
        $this->limits = $limits;

        $tools = [
            new ListAccountsTool($accounts),
            new ListFoldersTool(),
            new SearchEmailsTool(),
            new GetEmailTool(),
            new GetThreadTool(),
            new CreateDraftTool(),
            new SendDraftTool($this->varDir . '/state'),
            new MarkProcessedTool(),
            new GetReplyGuidelinesTool(),
        ];

        $this->registry = new Registry($tools, $accounts, new MailSessionFactory(), $this->limits, new SystemClock());
    }

    protected function tearDown(): void
    {
        if (null !== $this->varDir) {
            self::removeDir($this->varDir);
            $this->varDir = null;
        }
    }

    /**
     * The identity address used by every test: the one account configured
     * is both the mailbox under test and (for `testCreateDraftThenSendDraft`)
     * the recipient of its own mail.
     */
    protected function identityEmail(): string
    {
        return $this->account->identity->email;
    }

    /**
     * @param array<string, mixed> $args
     */
    protected function call(string $tool, array $args = []): ToolResult
    {
        return $this->registry->call($tool, $args);
    }

    /**
     * Creates the `Drafts` and `Sent` folders the account's config
     * expects (GreenMail advertises no SPECIAL-USE and starts with only
     * `INBOX`); "already exists" is not an error, since a previous run
     * against the same mailbox may have created them already.
     */
    protected function ensureFolders(): void
    {
        $session = $this->openSession();
        $imap = $session->imap();

        foreach (['Drafts', 'Sent'] as $name) {
            try {
                $imap->createFolder($name);
            } catch (ImapException) {
                // Already exists (or some other server quirk): the test
                // only needs the folder to be there afterwards.
            }
        }

        $session->close();
    }

    /**
     * Appends a small RFC 5322 message straight through the IMAP client
     * (not through any tool) to $folder: a unique Message-ID and a
     * subject containing $subjectTag, `From: sender@example.com`,
     * `To: <identity>`, a short text body. Returns its uid (from
     * APPENDUID, or by searching for the Message-ID when the server does
     * not report one). Appended with no flags, so the message starts out
     * unseen.
     */
    protected function appendFixture(string $folder, string $subjectTag): int
    {
        $session = $this->openSession();
        $imap = $session->imap();

        $messageId = '<' . bin2hex(random_bytes(8)) . '.mailbox-mcp-it@example.com>';
        $date = (new DateTimeImmutable())->format(DATE_RFC2822);
        $raw = "From: sender@example.com\r\n"
            . "To: {$this->identityEmail()}\r\n"
            . "Subject: mailbox-mcp integration fixture {$subjectTag}\r\n"
            . "Date: {$date}\r\n"
            . "Message-ID: {$messageId}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 7bit\r\n"
            . "\r\n"
            . "Fixture message for mailbox-mcp integration tests, tag {$subjectTag}.\r\n";

        $uid = $imap->append($folder, $raw, []);

        if (null === $uid) {
            $imap->select($folder, true);
            $found = $imap->search('HEADER Message-ID ' . SearchCriteria::quote($messageId), null);

            if ([] === $found) {
                $session->close();

                throw new RuntimeException("Fixture appended to {$folder} but its uid could not be determined");
            }

            $uid = max($found);
        }

        $session->close();

        return $uid;
    }

    /**
     * Pulls one `key: value` fact line out of a tool's rendered text
     * (see {@see \MailboxMcp\Tools\Render}), e.g. `field($result->text,
     * 'draft_uid')`. Returns null when the key is not present as its own
     * line.
     */
    protected static function field(string $text, string $key): ?string
    {
        $pattern = '/^' . preg_quote($key, '/') . ': (.*)$/m';

        return 1 === preg_match($pattern, $text, $m) ? $m[1] : null;
    }

    /**
     * Opens a fresh, ready-to-use session directly against the real
     * IMAP/SMTP servers (bypassing the Registry/tool layer), for setup
     * helpers that are not themselves part of the tool surface under
     * test. The caller is responsible for closing it.
     */
    protected function openSession(): MailSession
    {
        return (new MailSessionFactory())->open($this->account, $this->limits);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawConfig(string $varDir): array
    {
        $user = (string) getenv('MAILBOX_MCP_TEST_USER');
        $password = (string) getenv('MAILBOX_MCP_TEST_PASSWORD');
        $verifyPeer = '1' === getenv('MAILBOX_MCP_TEST_VERIFY_PEER');

        return [
            'auth' => ['token' => str_repeat('a', 43)],
            'paths' => ['var' => $varDir],
            'limits' => ['daily_send_cap' => 100],
            'accounts' => [
                self::ACCOUNT_ID => [
                    'email' => self::envOr('MAILBOX_MCP_TEST_EMAIL', $user),
                    'name' => 'Mailbox MCP Integration Test',
                    'imap' => [
                        'host' => (string) getenv('MAILBOX_MCP_TEST_IMAP_HOST'),
                        'port' => (int) getenv('MAILBOX_MCP_TEST_IMAP_PORT'),
                        'encryption' => self::envOr('MAILBOX_MCP_TEST_IMAP_ENCRYPTION', 'none'),
                        'username' => $user,
                        'password' => $password,
                        'verify_peer' => $verifyPeer,
                    ],
                    'smtp' => [
                        'host' => (string) getenv('MAILBOX_MCP_TEST_SMTP_HOST'),
                        'port' => (int) getenv('MAILBOX_MCP_TEST_SMTP_PORT'),
                        'encryption' => self::envOr('MAILBOX_MCP_TEST_SMTP_ENCRYPTION', 'none'),
                        'username' => $user,
                        'password' => $password,
                        'verify_peer' => $verifyPeer,
                    ],
                    'folders' => ['drafts' => 'Drafts', 'sent' => 'Sent'],
                    'processed_keyword' => '$ClaudeSeen',
                    'send' => ['denied_domains' => ['denied.example']],
                ],
            ],
        ];
    }

    private static function envOr(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : $default;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
