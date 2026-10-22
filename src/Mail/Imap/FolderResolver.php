<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

/**
 * Assigns a special-use role (inbox/drafts/sent/trash/junk/archive) to at
 * most one {@see Folder} each, out of the folders returned by IMAP LIST.
 *
 * Priority order (highest first), applied role by role:
 *
 *   1. A configured override whose name equals a folder's name exactly
 *      (drafts/sent/trash/junk only; see {@see \MailboxMcp\Accounts\Account::$folders}).
 *   2. The `\inbox` attribute, or the name `INBOX` (case-insensitive).
 *   3. A SPECIAL-USE attribute: `\drafts`, `\sent`, `\trash`, `\junk`, `\archive`.
 *   4. A name heuristic, case-insensitive, after stripping a leading
 *      `INBOX<delimiter>` prefix (no heuristic exists for archive).
 *
 * A folder carrying `\noselect` never receives a role, at any priority.
 * Once a folder has a role, it is no longer a candidate for another role.
 * Within a priority tier, the first matching folder in list order wins;
 * a higher-priority match for a role always wins over a lower one.
 */
final class FolderResolver
{
    /** @var array<string, string> special-use attribute => role */
    private const SPECIAL_USE_ROLES = [
        '\\drafts' => 'drafts',
        '\\sent' => 'sent',
        '\\trash' => 'trash',
        '\\junk' => 'junk',
        '\\archive' => 'archive',
    ];

    /** @var array<string, list<string>> role => lowercased name heuristics */
    private const NAME_HEURISTICS = [
        'drafts' => ['drafts'],
        'sent' => ['sent', 'sent messages', 'sent items', 'sent mail'],
        'trash' => ['trash', 'deleted messages', 'deleted items'],
        'junk' => ['junk', 'spam', 'junk e-mail'],
    ];

    /**
     * @param array<string, ?string> $overrides role (drafts|sent|trash|junk) => folder name|null
     */
    public function __construct(private readonly array $overrides)
    {
    }

    /**
     * @param array<int, Folder> $folders
     *
     * @return array<int, Folder> the same objects, with ->role set
     */
    public function assignRoles(array $folders): array
    {
        $assigned = [];

        foreach ($this->overrides as $role => $name) {
            if (null === $name) {
                continue;
            }

            $this->assignFirstMatch($folders, $assigned, $role, static fn (Folder $f): bool => $f->name === $name);
        }

        $this->assignFirstMatch(
            $folders,
            $assigned,
            'inbox',
            static fn (Folder $f): bool => in_array('\\inbox', $f->attributes, true) || 'inbox' === strtolower($f->name),
        );

        foreach (self::SPECIAL_USE_ROLES as $attribute => $role) {
            $this->assignFirstMatch(
                $folders,
                $assigned,
                $role,
                static fn (Folder $f): bool => in_array($attribute, $f->attributes, true),
            );
        }

        foreach (self::NAME_HEURISTICS as $role => $names) {
            $this->assignFirstMatch(
                $folders,
                $assigned,
                $role,
                function (Folder $f) use ($names): bool {
                    $stripped = $this->stripInboxPrefix($f->name, $f->delimiter);

                    return in_array(strtolower($stripped), $names, true);
                },
            );
        }

        return $folders;
    }

    /**
     * @param array<int, Folder>  $folders
     * @param array<string, bool> $assigned
     * @param callable(Folder): bool $matches
     */
    private function assignFirstMatch(array $folders, array &$assigned, string $role, callable $matches): void
    {
        if (isset($assigned[$role])) {
            return;
        }

        foreach ($folders as $folder) {
            if (null !== $folder->role || $this->isNoselect($folder)) {
                continue;
            }

            if ($matches($folder)) {
                $folder->role = $role;
                $assigned[$role] = true;

                return;
            }
        }
    }

    private function isNoselect(Folder $folder): bool
    {
        return in_array('\\noselect', $folder->attributes, true);
    }

    private function stripInboxPrefix(string $name, string $delimiter): string
    {
        if ('' === $delimiter) {
            return $name;
        }

        $prefix = 'INBOX' . $delimiter;

        if (0 === stripos($name, $prefix)) {
            return substr($name, strlen($prefix));
        }

        return $name;
    }
}
