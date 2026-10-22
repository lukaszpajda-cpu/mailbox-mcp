<?php

declare(strict_types=1);

namespace MailboxMcp\Mail;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Mail\Imap\Folder;
use MailboxMcp\Mail\Imap\FolderResolver;
use MailboxMcp\Mail\Imap\ImapClientInterface;
use MailboxMcp\Mail\Smtp\SmtpClientInterface;
use MailboxMcp\Tools\ToolException;
use Throwable;

/**
 * Per-request holder of the (at most one) IMAP and SMTP client for one
 * account. Every mail tool receives this via
 * {@see \MailboxMcp\Tools\ToolContext::$session}; neither client is
 * connected until first used.
 */
final class MailSession
{
    private bool $imapConnected = false;

    private bool $smtpConnected = false;

    /** @var array<int, Folder>|null */
    private ?array $folders = null;

    /**
     * @param array<string, ?string> $folderOverrides role => name|null
     */
    public function __construct(
        private readonly Account $account,
        private readonly ImapClientInterface $imap,
        private readonly SmtpClientInterface $smtp,
        private readonly array $folderOverrides,
    ) {
    }

    public function imap(): ImapClientInterface
    {
        if (!$this->imapConnected) {
            $this->imap->connect();
            $this->imapConnected = true;
        }

        return $this->imap;
    }

    public function smtp(): SmtpClientInterface
    {
        if (!$this->smtpConnected) {
            $this->smtp->connect();
            $this->smtpConnected = true;
        }

        return $this->smtp;
    }

    /**
     * @return array<int, Folder> with roles assigned, cached for the
     *                             lifetime of this session
     */
    public function folders(): array
    {
        if (null === $this->folders) {
            $this->folders = (new FolderResolver($this->folderOverrides))->assignRoles($this->imap()->listFolders());
        }

        return $this->folders;
    }

    public function folderForRole(string $role): Folder
    {
        foreach ($this->folders() as $folder) {
            if ($folder->role === $role) {
                return $folder;
            }
        }

        throw new ToolException("No {$role} folder detected; set accounts.{$this->account->id}.folders.{$role} in the config");
    }

    public function folderByName(string $name): Folder
    {
        $folders = $this->folders();

        foreach ($folders as $folder) {
            if ($folder->name === $name) {
                return $folder;
            }
        }

        if ('inbox' === strtolower($name)) {
            foreach ($folders as $folder) {
                if ('inbox' === strtolower($folder->name)) {
                    return $folder;
                }
            }
        }

        throw new ToolException("Unknown folder '{$name}'");
    }

    public function close(): void
    {
        if ($this->imapConnected) {
            try {
                $this->imap->close();
            } catch (Throwable) {
                // best-effort close: never let a client's close() failure propagate
            }

            $this->imapConnected = false;
        }

        if ($this->smtpConnected) {
            try {
                $this->smtp->close();
            } catch (Throwable) {
                // best-effort close: never let a client's close() failure propagate
            }

            $this->smtpConnected = false;
        }
    }
}
