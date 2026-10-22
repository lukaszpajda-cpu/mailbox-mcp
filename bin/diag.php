#!/usr/bin/env php
<?php

declare(strict_types=1);

use MailboxMcp\Config\ConfigException;
use MailboxMcp\Config\Loader;
use MailboxMcp\Diag\Report;
use MailboxMcp\Mail\MailSessionFactory;
use MailboxMcp\Security\TokenGenerator;

/**
 * CLI counterpart of public/diag.php: same report, no token, no HTTP.
 *
 * Usage:
 *   bin/diag.php [--config <path>] [--no-mail] [--new-token]
 *
 *   --config <path>  Config file to load (default: same resolution as
 *                     public/index.php: $MAILBOX_MCP_CONFIG, or
 *                     <app dir>/config/config.php).
 *   --no-mail        Skip the IMAP/SMTP connection probes.
 *   --new-token      Print a fresh random token (TokenGenerator::generate())
 *                     and exit; no config is loaded.
 *
 * Exit status is 1 when a required PHP extension (json, openssl,
 * mbstring) is missing or any account's IMAP/SMTP probe did not come
 * back "ok" (when probed), and also 1 on a usage or config error; 0
 * otherwise. A missing *optional* extension (iconv, intl) is reported
 * but does not affect the exit status: the server degrades gracefully
 * without them.
 *
 * @param list<string> $arguments argv without the script name itself
 */
function mailboxMcpDiagMain(array $arguments, string $defaultAppDir): int
{
    $configPath = null;
    $probeMail = true;
    $newToken = false;

    $count = count($arguments);

    for ($i = 0; $i < $count; ++$i) {
        $arg = $arguments[$i];

        if ('--config' === $arg) {
            $value = $arguments[$i + 1] ?? null;

            if (null === $value) {
                fwrite(STDERR, "--config requires a path argument\n");

                return 1;
            }

            $configPath = $value;
            ++$i;
        } elseif ('--no-mail' === $arg) {
            $probeMail = false;
        } elseif ('--new-token' === $arg) {
            $newToken = true;
        } else {
            fwrite(STDERR, "Unknown option: {$arg}\n");

            return 1;
        }
    }

    if ($newToken) {
        echo TokenGenerator::generate(), "\n";

        return 0;
    }

    if (null === $configPath) {
        $configEnv = getenv('MAILBOX_MCP_CONFIG');
        $configPath = is_string($configEnv) && '' !== $configEnv ? $configEnv : $defaultAppDir . '/config/config.php';
    }

    try {
        $config = Loader::load($configPath);
    } catch (ConfigException $e) {
        fwrite(STDERR, 'Config error: ' . $e->getMessage() . "\n");

        return 1;
    }

    // The path actually loaded, so config_mode_ok checks this very file.
    $report = Report::run($config, new MailSessionFactory(), $probeMail, null, $configPath);

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo (false === $json ? '{}' : $json), "\n";

    return mailboxMcpDiagExitCode($report);
}

/**
 * @param array<string, mixed> $report
 */
function mailboxMcpDiagExitCode(array $report): int
{
    if (true !== ($report['extensions_ok'] ?? null)) {
        return 1;
    }

    /** @var array<string, mixed> $accounts */
    $accounts = is_array($report['accounts'] ?? null) ? $report['accounts'] : [];

    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }

        if (array_key_exists('imap', $account) && 'ok' !== $account['imap']) {
            return 1;
        }

        if (array_key_exists('smtp', $account) && 'ok' !== $account['smtp']) {
            return 1;
        }
    }

    return 0;
}

$appDirEnv = getenv('MAILBOX_MCP_APP');
$appDir = is_string($appDirEnv) && '' !== $appDirEnv ? $appDirEnv : dirname(__DIR__);

require $appDir . '/src/autoload.php';

/** @var list<string> $argv */
exit(mailboxMcpDiagMain(array_slice($argv, 1), $appDir));
