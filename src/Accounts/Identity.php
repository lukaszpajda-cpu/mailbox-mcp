<?php

declare(strict_types=1);

namespace MailboxMcp\Accounts;

use MailboxMcp\Mail\Mime\HeaderEncoder;

/**
 * An account's sending identity: the From address and display name used
 * on outgoing mail.
 */
final class Identity
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {
    }

    public function domain(): string
    {
        $at = strrpos($this->email, '@');

        return false === $at ? '' : substr($this->email, $at + 1);
    }

    /**
     * "Name <email>" for a plain-ASCII name, a quoted-string form when the
     * name needs it (contains a specials character), or an RFC 2047
     * encoded word when the name is non-ASCII.
     */
    public function header(): string
    {
        return HeaderEncoder::nameAddr($this->name, $this->email);
    }
}
