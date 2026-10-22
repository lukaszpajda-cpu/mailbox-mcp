<?php

declare(strict_types=1);

namespace MailboxMcp\Diag;

use MailboxMcp\Accounts\Account;
use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Mail\MailSessionFactoryInterface;
use Throwable;

/**
 * Builds the diagnostics report shown by `public/diag.php` and
 * `bin/diag.php`: PHP/extension facts, config/var location and
 * permission checks, and (when $probeMail is true) a live IMAP/SMTP
 * connection test per account.
 *
 * The report never contains a password, a username or the auth token:
 * mail-probe results are either the literal string `ok` or the failing
 * exception's *class name only* (never its message, which could echo
 * back a hostname or credential). `authorization_header_reaches_php` is
 * deliberately not produced here: only the HTTP entry point
 * (`public/diag.php`) knows whether the current request actually
 * carried the header, so it adds that key itself after calling
 * {@see self::run()}.
 */
final class Report
{
    /** @var list<string> */
    private const EXTENSIONS = ['json', 'openssl', 'mbstring', 'iconv', 'intl'];

    /**
     * Extensions the server cannot run without; missing one of these
     * fails `extensions_ok` and `bin/diag.php`'s exit code.
     *
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = ['json', 'openssl', 'mbstring'];

    /**
     * Extensions used for nicer behaviour (legacy-charset decoding,
     * internationalized domain names) but not required: the server
     * degrades gracefully without them, so a missing one is reported
     * via `optional_extensions_missing` without flipping `extensions_ok`
     * or the CLI's exit code.
     *
     * @var list<string>
     */
    private const OPTIONAL_EXTENSIONS = ['iconv', 'intl'];

    /**
     * @param array<string, mixed> $config     validated top-level config (see Config\Validator)
     * @param ?string              $configPath the config file the caller actually loaded; when
     *                                         null it is resolved from MAILBOX_MCP_CONFIG /
     *                                         MAILBOX_MCP_APP like the entry points' env step
     *
     * @return array<string, mixed>
     */
    public static function run(
        array $config,
        MailSessionFactoryInterface $factory,
        bool $probeMail,
        ?string $docroot = null,
        ?string $configPath = null,
    ): array {
        /** @var array<string, mixed> $auth */
        $auth = $config['auth'];
        /** @var array<string, mixed> $security */
        $security = $config['security'];
        /** @var array<string, mixed> $limits */
        $limits = $config['limits'];
        /** @var array<string, mixed> $paths */
        $paths = $config['paths'];

        $varDir = (string) $paths['var'];
        $configFilePath = $configPath ?? self::configFilePath();

        $extensions = [];

        foreach (self::EXTENSIONS as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        $extensionsSummary = self::extensionsSummary($extensions);

        return [
            'php_version' => PHP_VERSION,
            'open_basedir' => self::openBasedir(),
            'extensions' => $extensions,
            'extensions_ok' => $extensionsSummary['extensions_ok'],
            'optional_extensions_missing' => $extensionsSummary['optional_extensions_missing'],
            'config_outside_docroot' => self::isOutsideDocroot($configFilePath, $docroot),
            'var_outside_docroot' => self::isOutsideDocroot($varDir, $docroot),
            'config_mode_ok' => self::modeOk($configFilePath),
            'var_writable' => is_writable($varDir),
            'allow_url_token' => (bool) $auth['allow_url_token'],
            'allow_http' => (bool) $security['allow_http'],
            'accounts' => self::accountReports($config, $factory, $limits, $probeMail),
        ];
    }

    /**
     * Pure, deterministic classification of an extension-loaded map (as
     * produced by {@see self::run()}'s `extensions` key) into whether
     * the *required* set ({@see self::REQUIRED_EXTENSIONS}) is fully
     * present, and which of the *optional* set
     * ({@see self::OPTIONAL_EXTENSIONS}) is missing. Exposed as a public
     * method (rather than folded into `run()`) so it — and the
     * required/optional distinction it encodes — can be tested directly
     * without depending on which extensions happen to be loaded in the
     * test environment.
     *
     * @param array<string, bool> $loaded extension name => loaded
     *
     * @return array{extensions_ok: bool, optional_extensions_missing: list<string>}
     */
    public static function extensionsSummary(array $loaded): array
    {
        $extensionsOk = true;

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (true !== ($loaded[$extension] ?? false)) {
                $extensionsOk = false;
            }
        }

        $missingOptional = [];

        foreach (self::OPTIONAL_EXTENSIONS as $extension) {
            if (true !== ($loaded[$extension] ?? false)) {
                $missingOptional[] = $extension;
            }
        }

        return ['extensions_ok' => $extensionsOk, 'optional_extensions_missing' => $missingOptional];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $limits
     *
     * @return array<string, array<string, mixed>>
     */
    private static function accountReports(array $config, MailSessionFactoryInterface $factory, array $limits, bool $probeMail): array
    {
        $result = [];

        foreach (AccountResolver::fromConfig($config)->all() as $id => $account) {
            $result[$id] = $probeMail
                ? self::probeAccount($account, $factory, $limits)
                : ['email' => $account->identity->email];
        }

        return $result;
    }

    /**
     * Opens a session for $account and probes IMAP (via `folders()`) and
     * SMTP (via `smtp()->connect()`) independently, so a failure on one
     * account or one protocol never stops the rest of the report. The
     * session is always closed, even when a probe throws.
     *
     * @param array<string, mixed> $limits
     *
     * @return array<string, mixed>
     */
    private static function probeAccount(Account $account, MailSessionFactoryInterface $factory, array $limits): array
    {
        $entry = ['email' => $account->identity->email];

        try {
            $session = $factory->open($account, $limits);
        } catch (Throwable $e) {
            $entry['imap'] = $e::class;
            $entry['smtp'] = $e::class;

            return $entry;
        }

        try {
            $imapOk = false;

            try {
                $session->folders();
                $entry['imap'] = 'ok';
                $imapOk = true;
            } catch (Throwable $e) {
                $entry['imap'] = $e::class;
            }

            try {
                $session->smtp()->connect();
                $entry['smtp'] = 'ok';
            } catch (Throwable $e) {
                $entry['smtp'] = $e::class;
            }

            if ($imapOk) {
                $folders = [];

                foreach ($session->folders() as $folder) {
                    $folders[$folder->name] = $folder->role;
                }

                $entry['folders'] = $folders;
            }
        } finally {
            $session->close();
        }

        return $entry;
    }

    /**
     * The effective `open_basedir` ini value, or null when none is set.
     * On CloudLinux/LiteSpeed hosts it is usually per domain, which is
     * why app, config and var must live inside the domain directory.
     */
    private static function openBasedir(): ?string
    {
        $value = ini_get('open_basedir');

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Fallback when the caller passes no config path: resolves it the same way `public/index.php` and
     * `bin/diag.php` do, so `config_outside_docroot` / `config_mode_ok`
     * check the file actually in use rather than guessing.
     */
    private static function configFilePath(): string
    {
        $appDirEnv = getenv('MAILBOX_MCP_APP');
        $appDir = is_string($appDirEnv) && '' !== $appDirEnv ? $appDirEnv : dirname(__DIR__, 2);

        $configPathEnv = getenv('MAILBOX_MCP_CONFIG');

        return is_string($configPathEnv) && '' !== $configPathEnv ? $configPathEnv : $appDir . '/config/config.php';
    }

    /**
     * True when $path resolves outside $docroot, false when inside, null
     * when either realpath cannot be resolved (missing file/dir) or
     * $docroot itself is unknown.
     */
    private static function isOutsideDocroot(string $path, ?string $docroot): ?bool
    {
        if (null === $docroot) {
            return null;
        }

        $docrootReal = realpath($docroot);
        $pathReal = realpath($path);

        if (false === $docrootReal || false === $pathReal) {
            return null;
        }

        if ($pathReal === $docrootReal) {
            return false;
        }

        return !str_starts_with($pathReal, rtrim($docrootReal, '/\\') . DIRECTORY_SEPARATOR);
    }

    /**
     * True when $path's permission bits are exactly 0600 or 0400, null
     * when that cannot be determined (Windows, or the file is missing).
     */
    private static function modeOk(string $path): ?bool
    {
        if ('Windows' === PHP_OS_FAMILY) {
            return null;
        }

        $real = realpath($path);

        if (false === $real) {
            return null;
        }

        $perms = fileperms($real);

        if (false === $perms) {
            return null;
        }

        $mode = $perms & 0777;

        return 0600 === $mode || 0400 === $mode;
    }
}
