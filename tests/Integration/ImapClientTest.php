<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Integration;

use MailboxMcp\Protocol\ProtocolException;

/**
 * Basic sanity tests against the real IMAP server: folder listing and
 * schema-level protection, exercised only through `list_folders` and
 * `search_emails` ({@see Registry::call()}).
 */
final class ImapClientTest extends IntegrationCase
{
    public function testListFoldersHasInbox(): void
    {
        $result = $this->call('list_folders');

        $this->assertFalse($result->isError);
        $this->assertMatchesRegularExpression('/^- INBOX\s+role=inbox\b/m', $result->text);
    }

    public function testSearchRejectsBadArgumentsBeforeImap(): void
    {
        $this->expectException(ProtocolException::class);

        $this->call('search_emails', ['limit' => 0]);
    }
}
