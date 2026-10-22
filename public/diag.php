<?php

declare(strict_types=1);

use MailboxMcp\Config\ConfigException;
use MailboxMcp\Config\Loader;
use MailboxMcp\Diag\Report;
use MailboxMcp\Http\ClientIp;
use MailboxMcp\Http\Response;
use MailboxMcp\Mail\MailSessionFactory;
use MailboxMcp\Security\Lockout;
use MailboxMcp\Support\SystemClock;

// Disabled before anything else runs (including the autoload require
// below), same as public/index.php, so nothing this script does can
// ever echo a warning or notice back to the client.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Same resolution as public/index.php (keep the two in sync).
// Where the application and its config live, first match wins for each:
//   1. the MAILBOX_MCP_APP / MAILBOX_MCP_CONFIG environment variables
//      (e.g. `SetEnv` in .htaccess, where the server passes it to PHP);
//   2. an optional mailbox-mcp.paths.php next to this file that returns
//      ['app' => '/abs/app/dir', 'config' => '/abs/path/config.php']
//      (see mailbox-mcp.paths.example.php; works on LiteSpeed/CloudLinux
//      hosts where SetEnv never reaches PHP);
//   3. the defaults: the parent directory, and <app>/config/config.php.
$mailboxMcpPaths = [];

if (is_file(__DIR__ . '/mailbox-mcp.paths.php')) {
    $mailboxMcpLoaded = require __DIR__ . '/mailbox-mcp.paths.php';
    $mailboxMcpPaths = is_array($mailboxMcpLoaded) ? $mailboxMcpLoaded : [];
}

$mailboxMcpApp = $mailboxMcpPaths['app'] ?? null;
$mailboxMcpConfig = $mailboxMcpPaths['config'] ?? null;
$appDir = getenv('MAILBOX_MCP_APP')
    ?: (is_string($mailboxMcpApp) && '' !== $mailboxMcpApp ? $mailboxMcpApp : dirname(__DIR__));
$configPath = getenv('MAILBOX_MCP_CONFIG')
    ?: (is_string($mailboxMcpConfig) && '' !== $mailboxMcpConfig ? $mailboxMcpConfig : $appDir . '/config/config.php');

require $appDir . '/src/autoload.php';

/**
 * A 404 that also tells any intermediate cache never to store the
 * response — same shape as the one-path-fits-all 404 the main endpoint
 * (Kernel) returns for anything unauthenticated.
 */
function mailboxMcpDiagNotFound(): never
{
    (new Response(404, [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'no-store',
    ], "Not Found\n"))->send();

    exit;
}

function mailboxMcpDiagFormPage(): string
{
    $body = <<<'HTML'
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <title>mailbox-mcp diagnostics</title>
        </head>
        <body>
        <h1>mailbox-mcp diagnostics</h1>
        <p>Enter the server token to run the diagnostics report. The token is
        never sent in the URL; this form submits it as POST body data.</p>
        <form method="post">
        <label for="token">Token</label>
        <input type="password" id="token" name="token" autocomplete="off" required>
        <button type="submit">Run diagnostics</button>
        </form>
        <p>To check whether your host forwards the <code>Authorization</code>
        header through to PHP (relevant for Bearer-token auth on
        <code>/mcp</code>), send one request that carries both the header and
        the token, then look at <code>authorization_header_reaches_php</code>
        in the report below:</p>
        <pre>curl -i -X POST -H "Authorization: Bearer test" \
            --data-urlencode "token=&lt;your-token&gt;" \
            https://mcp.example.com/diag.php</pre>
        <p>Remember to set <code>diag.enabled</code> back to <code>false</code>
        in the config once you are done.</p>
        </body>
        </html>
        HTML;

    return $body;
}

try {
    $config = Loader::load($configPath);
} catch (ConfigException $e) {
    // Same blanket 404 + no-store as every other failure path here: a
    // broken config must never surface as a distinguishable response
    // (e.g. a 500) that could tell an unauthenticated caller anything
    // about the server's state. The detail goes to the error log only.
    error_log('mailbox-mcp: config error: ' . $e->getMessage());
    mailboxMcpDiagNotFound();
}

/** @var array<string, mixed> $diagCfg */
$diagCfg = $config['diag'];

if (true !== $diagCfg['enabled']) {
    mailboxMcpDiagNotFound();
}

/** @var array<string, mixed> $security */
$security = $config['security'];
/** @var array<int, string> $trustedProxies */
$trustedProxies = $security['trusted_proxies'];
$ip = ClientIp::resolve($_SERVER, $trustedProxies);

/** @var array<string, mixed> $paths */
$paths = $config['paths'];

$lockout = new Lockout(
    $paths['var'] . '/lockout',
    new SystemClock(),
    (int) $security['lockout_failures'],
    (int) $security['lockout_window_minutes'],
    (int) $security['lockout_minutes'],
);

// Locked IPs are turned away before the token is even compared, same as
// the main endpoint.
if ($lockout->isLocked($ip)) {
    mailboxMcpDiagNotFound();
}

$method = strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET');

if ('POST' !== $method) {
    (new Response(200, [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-store',
    ], mailboxMcpDiagFormPage()))->send();
    exit;
}

$submittedToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
/** @var array<string, mixed> $auth */
$auth = $config['auth'];
$validToken = (string) $auth['token'];

if (!hash_equals($validToken, $submittedToken)) {
    $lockout->recordFailure($ip);
    mailboxMcpDiagNotFound();
}

$lockout->clear($ip);

$authorizationReachesPhp = isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$report = Report::run(
    $config,
    new MailSessionFactory(),
    true,
    is_string($documentRoot) ? $documentRoot : null,
    $configPath,
);
$report['authorization_header_reaches_php'] = $authorizationReachesPhp;

$json = json_encode($report, JSON_PRETTY_PRINT);
$json = false === $json ? '{}' : $json;

$body = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<title>mailbox-mcp diagnostics</title></head><body><pre>'
    . htmlspecialchars($json, ENT_QUOTES, 'UTF-8')
    . '</pre></body></html>';

(new Response(200, [
    'Content-Type' => 'text/html; charset=utf-8',
    'Cache-Control' => 'no-store',
], $body))->send();
