<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Integration;

/**
 * End-to-end message lifecycle tests against the real IMAP/SMTP server,
 * exercised only through the public tool surface
 * ({@see IntegrationCase::call()}).
 */
final class RoundTripTest extends IntegrationCase
{
    public function testAppendSearchFetchRoundTrip(): void
    {
        $tag = bin2hex(random_bytes(4));
        $uid = $this->appendFixture('INBOX', $tag);

        $search = $this->call('search_emails', ['folder' => 'INBOX', 'subject' => $tag]);
        $this->assertFalse($search->isError);
        $this->assertSame('1', self::field($search->text, 'matched'));
        $this->assertStringContainsString("## uid {$uid}", $search->text);

        $email = $this->call('get_email', ['folder' => 'INBOX', 'uid' => $uid]);
        $this->assertFalse($email->isError);
        $this->assertStringContainsString('From: sender@example.com', $email->text);
        $this->assertStringContainsString("Fixture message for mailbox-mcp integration tests, tag {$tag}.", $email->text);

        // Neither search_emails nor get_email may have marked it \Seen.
        $session = $this->openSession();
        $imap = $session->imap();
        $imap->select('INBOX', true);
        $summary = $imap->fetchSummaries([$uid])[$uid] ?? null;
        $session->close();

        $this->assertNotNull($summary);
        foreach ($summary['flags'] as $flag) {
            $this->assertNotSame('\\seen', strtolower($flag));
        }

        $marked = $this->call('mark_processed', ['folder' => 'INBOX', 'uids' => [$uid]]);
        $this->assertFalse($marked->isError);

        $excluded = $this->call('search_emails', ['folder' => 'INBOX', 'subject' => $tag, 'exclude_processed' => true]);
        $this->assertSame('0', self::field($excluded->text, 'matched'));

        $restored = $this->call('mark_processed', ['folder' => 'INBOX', 'uids' => [$uid], 'processed' => false]);
        $this->assertFalse($restored->isError);

        $includedAgain = $this->call('search_emails', ['folder' => 'INBOX', 'subject' => $tag, 'exclude_processed' => true]);
        $this->assertSame('1', self::field($includedAgain->text, 'matched'));
    }

    public function testCreateDraftThenSendDraft(): void
    {
        $this->ensureFolders();

        $fixtureTag = bin2hex(random_bytes(4));
        $replyTag = bin2hex(random_bytes(4));

        $fixtureUid = $this->appendFixture('INBOX', $fixtureTag);
        $fixtureEmail = $this->call('get_email', ['folder' => 'INBOX', 'uid' => $fixtureUid]);
        $fixtureMessageId = self::field($fixtureEmail->text, 'message_id');
        $this->assertNotNull($fixtureMessageId);

        $draft = $this->call('create_draft', [
            'to' => [$this->identityEmail()],
            'subject' => "integration reply {$replyTag}",
            'body' => "Reply body {$replyTag}",
            'in_reply_to_uid' => $fixtureUid,
            'in_reply_to_folder' => 'INBOX',
        ]);
        $this->assertFalse($draft->isError);
        $draftUidText = self::field($draft->text, 'draft_uid');
        $this->assertNotNull($draftUidText);
        $draftUid = (int) $draftUidText;

        $draftCheck = $this->call('get_email', ['folder' => 'Drafts', 'uid' => $draftUid]);
        $this->assertFalse($draftCheck->isError);
        $this->assertStringContainsString('\\Draft', (string) self::field($draftCheck->text, 'flags'));
        $this->assertSame($fixtureMessageId, self::field($draftCheck->text, 'in_reply_to'));

        $sendResult = $this->call('send_draft', ['draft_uid' => $draftUid]);
        $this->assertFalse($sendResult->isError, $sendResult->text);
        $this->assertSame('yes', self::field($sendResult->text, 'sent'));

        // The reply was sent to the identity's own address: it must come
        // back into INBOX. Delivery is asynchronous, so poll briefly.
        $arrivedMatched = '0';

        for ($i = 0; $i < 10; ++$i) {
            $inbox = $this->call('search_emails', ['folder' => 'INBOX', 'subject' => $replyTag]);
            $arrivedMatched = self::field($inbox->text, 'matched') ?? '0';

            if ('0' !== $arrivedMatched) {
                break;
            }

            usleep(500000);
        }

        $this->assertNotSame('0', $arrivedMatched, 'Sent reply did not arrive in INBOX within 5 seconds');

        $sentCopy = $this->call('search_emails', ['folder' => 'Sent', 'subject' => $replyTag]);
        $this->assertNotSame('0', self::field($sentCopy->text, 'matched'));

        $draftAfterSend = $this->call('search_emails', ['folder' => 'Drafts', 'subject' => $replyTag]);
        $remaining = self::field($draftAfterSend->text, 'matched') ?? '0';

        if ('0' !== $remaining) {
            // No UIDPLUS: the draft must still be there, but \Deleted.
            $draftStillThere = $this->call('get_email', ['folder' => 'Drafts', 'uid' => $draftUid]);
            $this->assertStringContainsString('\\Deleted', (string) self::field($draftStillThere->text, 'flags'));
        }
    }

    public function testSendDraftRefusesDeniedDomain(): void
    {
        $this->ensureFolders();

        $tag = bin2hex(random_bytes(4));
        $subject = "denied domain test {$tag}";

        $draft = $this->call('create_draft', [
            'to' => ['x@denied.example'],
            'subject' => $subject,
            'body' => 'This must never be sent.',
        ]);
        $this->assertFalse($draft->isError);
        $draftUid = (int) self::field($draft->text, 'draft_uid');

        $sendResult = $this->call('send_draft', ['draft_uid' => $draftUid]);

        $this->assertTrue($sendResult->isError);
        $this->assertStringContainsString('Recipient domain denied.example denied', $sendResult->text);

        $sentCopy = $this->call('search_emails', ['folder' => 'Sent', 'subject' => $subject]);
        $this->assertSame('0', self::field($sentCopy->text, 'matched'));
    }
}
