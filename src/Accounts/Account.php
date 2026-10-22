<?php

declare(strict_types=1);

namespace MailboxMcp\Accounts;

/**
 * One mailbox = one account = one sending identity. Built from the
 * validated per-account config shape produced by
 * {@see \MailboxMcp\Config\Validator} (accounts.<id>).
 */
final class Account
{
    /**
     * @param array<string, mixed>  $imap    validated accounts.<id>.imap shape (host, port, encryption, username, password, verify_peer)
     * @param array<string, mixed>  $smtp    validated accounts.<id>.smtp shape
     * @param array<string, ?string> $folders drafts|sent|trash|junk => folder name or null
     * @param array{allowed_domains: array<int, string>, denied_domains: array<int, string>} $send
     */
    public function __construct(
        public readonly string $id,
        public readonly Identity $identity,
        public readonly array $imap,
        public readonly array $smtp,
        public readonly array $folders,
        public readonly string $signature,
        public readonly ?string $guidelinesFile,
        public readonly string $processedKeyword,
        public readonly array $send,
    ) {
    }

    /**
     * @param array<string, mixed> $cfg validated accounts.<id> shape: email, name,
     *                                   imap{host,port,encryption,username,password,verify_peer},
     *                                   smtp{…}, folders{drafts,sent,trash,junk}, signature,
     *                                   guidelines_file, processed_keyword, send{allowed_domains,denied_domains}
     */
    public static function fromConfig(string $id, array $cfg): self
    {
        $identity = new Identity(
            self::stringOrDefault($cfg['email'] ?? null, ''),
            self::stringOrDefault($cfg['name'] ?? null, ''),
        );

        return new self(
            $id,
            $identity,
            self::arraySection($cfg, 'imap'),
            self::arraySection($cfg, 'smtp'),
            self::normaliseFolders(self::arraySection($cfg, 'folders')),
            self::stringOrDefault($cfg['signature'] ?? null, ''),
            self::nullableString($cfg['guidelines_file'] ?? null),
            self::stringOrDefault($cfg['processed_keyword'] ?? null, ''),
            self::normaliseSend(self::arraySection($cfg, 'send')),
        );
    }

    /**
     * @param array<string, mixed> $cfg
     *
     * @return array<string, mixed>
     */
    private static function arraySection(array $cfg, string $key): array
    {
        $value = $cfg[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $folders
     *
     * @return array<string, ?string>
     */
    private static function normaliseFolders(array $folders): array
    {
        return [
            'drafts' => self::nullableString($folders['drafts'] ?? null),
            'sent' => self::nullableString($folders['sent'] ?? null),
            'trash' => self::nullableString($folders['trash'] ?? null),
            'junk' => self::nullableString($folders['junk'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $send
     *
     * @return array{allowed_domains: array<int, string>, denied_domains: array<int, string>}
     */
    private static function normaliseSend(array $send): array
    {
        return [
            'allowed_domains' => self::stringList($send['allowed_domains'] ?? []),
            'denied_domains' => self::stringList($send['denied_domains'] ?? []),
        ];
    }

    private static function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
