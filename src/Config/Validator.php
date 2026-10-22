<?php

declare(strict_types=1);

namespace MailboxMcp\Config;

/**
 * Validates a raw config array and returns it merged over
 * {@see Defaults::all()}. Throws {@see ConfigException} on the first
 * problem found; the message always starts with the offending key path.
 */
final class Validator
{
    private const TOP_LEVEL_KEYS = ['auth', 'server', 'security', 'limits', 'paths', 'audit', 'diag', 'accounts'];

    private const ENCRYPTIONS = ['tls', 'starttls', 'none'];

    private const LOCALHOSTS = ['127.0.0.1', '::1', 'localhost'];

    private const FOLDER_KEYS = ['drafts', 'sent', 'trash', 'junk'];

    private const ACCOUNT_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43,}$/';

    private const PROCESSED_KEYWORD_PATTERN = '/^\$?[A-Za-z0-9_]{1,64}$/';

    private const ORIGIN_PATTERN = '#^[a-z][a-z0-9+.-]*://[A-Za-z0-9.-]+(:\d{1,5})?$#';

    private const DOMAIN_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)+$/';

    /**
     * FILTER_VALIDATE_EMAIL rejects any single-label domain (no dot), which
     * also rules out `user@localhost` — the only address a local test
     * mailbox (e.g. GreenMail) can use. Accepted in addition to a normal
     * FILTER_VALIDATE_EMAIL match, never instead of it.
     */
    private const LOCALHOST_EMAIL_PATTERN = '/^[^@\s]+@localhost$/i';

    /**
     * Per-account defaults; not part of {@see Defaults::all()} because
     * `accounts` itself has no default (it is required and keyed by
     * operator-chosen account ids).
     *
     * @var array<string, mixed>
     */
    private const ACCOUNT_DEFAULTS = [
        'signature' => '',
        'guidelines_file' => null,
        'processed_keyword' => '$ClaudeSeen',
        'folders' => ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
        'send' => ['allowed_domains' => [], 'denied_domains' => []],
        'imap' => ['verify_peer' => true],
        'smtp' => ['verify_peer' => true],
    ];

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function validate(array $config): array
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::TOP_LEVEL_KEYS, true)) {
                throw new ConfigException('unknown key: ' . $key);
            }
        }

        $defaults = Defaults::all();

        $merged = [
            'auth' => self::sectionArray($config, 'auth') + self::defaultSection($defaults, 'auth'),
            'server' => self::sectionArray($config, 'server') + self::defaultSection($defaults, 'server'),
            'security' => self::sectionArray($config, 'security') + self::defaultSection($defaults, 'security'),
            'limits' => self::sectionArray($config, 'limits') + self::defaultSection($defaults, 'limits'),
            'paths' => self::sectionArray($config, 'paths') + self::defaultSection($defaults, 'paths'),
            'audit' => self::sectionArray($config, 'audit') + self::defaultSection($defaults, 'audit'),
            'diag' => self::sectionArray($config, 'diag') + self::defaultSection($defaults, 'diag'),
        ];

        self::validateAuth($merged);
        self::validateServer($merged);
        self::validateSecurity($merged);
        self::validateLimits($merged);
        self::validatePaths($merged);
        self::validateAudit($merged);
        self::validateDiag($merged);

        $merged['accounts'] = self::validateAccounts($config['accounts'] ?? null);

        return $merged;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function sectionArray(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (!is_array($value)) {
            throw new ConfigException("{$key}: must be an array");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private static function defaultSection(array $defaults, string $key): array
    {
        $value = $defaults[$key] ?? [];

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function validateAuth(array $merged): void
    {
        /** @var array<string, mixed> $auth */
        $auth = $merged['auth'];
        $token = $auth['token'] ?? null;

        if (!is_string($token) || 1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            throw new ConfigException('auth.token: must be at least 43 characters of [A-Za-z0-9_-]');
        }

        if (!is_bool($auth['allow_url_token'] ?? null)) {
            throw new ConfigException('auth.allow_url_token: must be a boolean');
        }
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function validateServer(array $merged): void
    {
        /** @var array<string, mixed> $server */
        $server = $merged['server'];

        if (!is_string($server['public_url'] ?? null)) {
            throw new ConfigException('server.public_url: must be a string');
        }

        if (!is_string($server['instructions_extra'] ?? null)) {
            throw new ConfigException('server.instructions_extra: must be a string');
        }
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function validateSecurity(array $merged): void
    {
        /** @var array<string, mixed> $security */
        $security = $merged['security'];

        $origins = $security['allowed_origins'] ?? [];

        if (!is_array($origins)) {
            throw new ConfigException('security.allowed_origins: must be a list of scheme://host[:port] strings');
        }

        foreach ($origins as $origin) {
            if (!is_string($origin) || 1 !== preg_match(self::ORIGIN_PATTERN, $origin)) {
                throw new ConfigException('security.allowed_origins: must be a list of scheme://host[:port] strings');
            }
        }

        foreach ([
            'rate_limit_per_minute',
            'rate_limit_global_per_minute',
            'lockout_failures',
            'lockout_window_minutes',
            'lockout_minutes',
            'max_body_bytes',
        ] as $key) {
            $value = $security[$key] ?? null;

            if (!is_int($value) || $value < 1) {
                throw new ConfigException("security.{$key}: must be a positive integer");
            }
        }

        $proxies = $security['trusted_proxies'] ?? [];

        if (!is_array($proxies)) {
            throw new ConfigException('security.trusted_proxies: must be a list of IP addresses');
        }

        foreach ($proxies as $proxy) {
            if (!is_string($proxy) || false === filter_var($proxy, FILTER_VALIDATE_IP)) {
                throw new ConfigException('security.trusted_proxies: must be a list of IP addresses');
            }
        }

        if (!is_bool($security['allow_http'] ?? null)) {
            throw new ConfigException('security.allow_http: must be a boolean');
        }
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function validateLimits(array $merged): void
    {
        /** @var array<string, mixed> $limits */
        $limits = $merged['limits'];

        foreach ([
            'max_results',
            'max_chars',
            'max_recipients',
            'daily_send_cap',
            'imap_timeout',
            'smtp_timeout',
            'request_budget_seconds',
        ] as $key) {
            $value = $limits[$key] ?? null;

            if (!is_int($value) || $value < 1) {
                throw new ConfigException("limits.{$key}: must be a positive integer");
            }
        }
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function validatePaths(array $merged): void
    {
        /** @var array<string, mixed> $paths */
        $paths = $merged['paths'];
        $var = $paths['var'] ?? null;

        if (!is_string($var) || '' === $var) {
            throw new ConfigException('paths.var: must be a non-empty string');
        }
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function validateAudit(array $merged): void
    {
        /** @var array<string, mixed> $audit */
        $audit = $merged['audit'];

        if (!is_bool($audit['enabled'] ?? null)) {
            throw new ConfigException('audit.enabled: must be a boolean');
        }

        $retention = $audit['retention_days'] ?? null;

        if (!is_int($retention) || $retention < 1) {
            throw new ConfigException('audit.retention_days: must be a positive integer');
        }

        if (!is_bool($audit['hash_ip'] ?? null)) {
            throw new ConfigException('audit.hash_ip: must be a boolean');
        }
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function validateDiag(array $merged): void
    {
        /** @var array<string, mixed> $diag */
        $diag = $merged['diag'];

        if (!is_bool($diag['enabled'] ?? null)) {
            throw new ConfigException('diag.enabled: must be a boolean');
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function validateAccounts(mixed $accounts): array
    {
        if (!is_array($accounts) || [] === $accounts) {
            throw new ConfigException('accounts: must be a non-empty array');
        }

        $result = [];

        foreach ($accounts as $id => $account) {
            $label = is_string($id) ? $id : (string) $id;

            if (!is_string($id) || 1 !== preg_match(self::ACCOUNT_ID_PATTERN, $id)) {
                throw new ConfigException("accounts.{$label}: invalid account id, must match " . self::ACCOUNT_ID_PATTERN);
            }

            if (!is_array($account)) {
                throw new ConfigException("accounts.{$id}: must be an array");
            }

            /** @var array<string, mixed> $account */
            $result[$id] = self::validateAccount($id, $account);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private static function validateAccount(string $id, array $account): array
    {
        $defaults = self::ACCOUNT_DEFAULTS;
        $merged = $account + $defaults;

        foreach (['folders', 'send', 'imap', 'smtp'] as $key) {
            $userSub = $account[$key] ?? [];

            if (!is_array($userSub)) {
                throw new ConfigException("accounts.{$id}.{$key}: must be an array");
            }

            $defaultSub = $defaults[$key];

            /** @var array<string, mixed> $userSub */
            $merged[$key] = $userSub + $defaultSub;
        }

        $email = $merged['email'] ?? null;

        if (!is_string($email) || (false === filter_var($email, FILTER_VALIDATE_EMAIL) && 1 !== preg_match(self::LOCALHOST_EMAIL_PATTERN, $email))) {
            throw new ConfigException("accounts.{$id}.email: must be a valid email address");
        }

        $name = $merged['name'] ?? null;

        if (!is_string($name) || '' === $name) {
            throw new ConfigException("accounts.{$id}.name: must be a non-empty string");
        }

        /** @var array<string, mixed> $imap */
        $imap = $merged['imap'];
        /** @var array<string, mixed> $smtp */
        $smtp = $merged['smtp'];

        $merged['imap'] = self::validateMailTransport($id, 'imap', $imap);
        $merged['smtp'] = self::validateMailTransport($id, 'smtp', $smtp);

        $signature = $merged['signature'] ?? '';

        if (!is_string($signature)) {
            throw new ConfigException("accounts.{$id}.signature: must be a string");
        }

        $guidelinesFile = $merged['guidelines_file'] ?? null;

        if (null !== $guidelinesFile && !is_string($guidelinesFile)) {
            throw new ConfigException("accounts.{$id}.guidelines_file: must be a string or null");
        }

        $processedKeyword = $merged['processed_keyword'] ?? null;

        if (!is_string($processedKeyword) || 1 !== preg_match(self::PROCESSED_KEYWORD_PATTERN, $processedKeyword)) {
            throw new ConfigException("accounts.{$id}.processed_keyword: must match /^\\\$?[A-Za-z0-9_]{1,64}$/");
        }

        /** @var array<string, mixed> $folders */
        $folders = $merged['folders'];
        /** @var array<string, mixed> $send */
        $send = $merged['send'];

        $merged['folders'] = self::validateFolders($id, $folders);
        $merged['send'] = self::validateSend($id, $send);

        return $merged;
    }

    /**
     * @param array<string, mixed> $transport
     *
     * @return array<string, mixed>
     */
    private static function validateMailTransport(string $accountId, string $protocol, array $transport): array
    {
        $prefix = "accounts.{$accountId}.{$protocol}";

        $host = $transport['host'] ?? null;

        if (!is_string($host) || '' === $host) {
            throw new ConfigException("{$prefix}.host: must be a non-empty string");
        }

        $port = $transport['port'] ?? null;

        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new ConfigException("{$prefix}.port: must be between 1 and 65535");
        }

        $encryption = $transport['encryption'] ?? null;

        if (!is_string($encryption) || !in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new ConfigException("{$prefix}.encryption: must be one of tls, starttls, none");
        }

        if ('none' === $encryption && !in_array(strtolower($host), self::LOCALHOSTS, true)) {
            throw new ConfigException("{$prefix}.encryption: none is only allowed when host is 127.0.0.1, ::1 or localhost");
        }

        $username = $transport['username'] ?? null;

        if (!is_string($username) || '' === $username) {
            throw new ConfigException("{$prefix}.username: must be a non-empty string");
        }

        $password = $transport['password'] ?? null;

        if (!is_string($password) || '' === $password) {
            throw new ConfigException("{$prefix}.password: must be a non-empty string");
        }

        if (!is_bool($transport['verify_peer'] ?? null)) {
            throw new ConfigException("{$prefix}.verify_peer: must be a boolean");
        }

        return $transport;
    }

    /**
     * @param array<string, mixed> $folders
     *
     * @return array<string, mixed>
     */
    private static function validateFolders(string $accountId, array $folders): array
    {
        foreach ($folders as $key => $value) {
            if (!in_array($key, self::FOLDER_KEYS, true)) {
                throw new ConfigException("accounts.{$accountId}.folders: unknown key '{$key}'");
            }

            if (null !== $value && !is_string($value)) {
                throw new ConfigException("accounts.{$accountId}.folders.{$key}: must be a string or null");
            }
        }

        return $folders;
    }

    /**
     * @param array<string, mixed> $send
     *
     * @return array<string, mixed>
     */
    private static function validateSend(string $accountId, array $send): array
    {
        foreach (['allowed_domains', 'denied_domains'] as $key) {
            $domains = $send[$key] ?? [];

            if (!is_array($domains)) {
                throw new ConfigException("accounts.{$accountId}.send.{$key}: must be a list of lowercase domains");
            }

            foreach ($domains as $domain) {
                if (!is_string($domain) || 1 !== preg_match(self::DOMAIN_PATTERN, $domain)) {
                    throw new ConfigException("accounts.{$accountId}.send.{$key}: must be a list of lowercase domains");
                }
            }
        }

        return $send;
    }
}
