<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

/**
 * One IMAP mailbox as returned by LIST, plus its resolved special-use
 * role (if any). $attributes are lowercased by whoever constructs the
 * Folder (the real client and {@see \MailboxMcp\Tests\Support\FakeImapClient}
 * both lowercase their LIST attributes), e.g. '\drafts'.
 */
final class Folder
{
    /**
     * @param array<int, string> $attributes lowercased, e.g. '\drafts'
     */
    public function __construct(
        public readonly string $name,
        public readonly string $delimiter,
        public readonly array $attributes,
        public ?string $role = null,
    ) {
    }
}
