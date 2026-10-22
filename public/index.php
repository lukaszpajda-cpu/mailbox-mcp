<?php

declare(strict_types=1);

// First statements: nothing below, including a failing require of a
// mistyped app path, may ever echo a warning back to the client.
ini_set('display_errors', '0');
error_reporting(E_ALL);

use MailboxMcp\Accounts\AccountResolver;
use MailboxMcp\Config\ConfigException;
use MailboxMcp\Config\Loader;
use MailboxMcp\Http\Request;
use MailboxMcp\Kernel;
use MailboxMcp\Mail\MailSessionFactory;
use MailboxMcp\Support\SystemClock;
use MailboxMcp\Tools\CreateDraftTool;
use MailboxMcp\Tools\GetEmailTool;
use MailboxMcp\Tools\GetReplyGuidelinesTool;
use MailboxMcp\Tools\GetThreadTool;
use MailboxMcp\Tools\ListAccountsTool;
use MailboxMcp\Tools\ListFoldersTool;
use MailboxMcp\Tools\MarkProcessedTool;
use MailboxMcp\Tools\SearchEmailsTool;
use MailboxMcp\Tools\SendDraftTool;

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

try {
    $config = Loader::load($configPath);
} catch (ConfigException $e) {
    http_response_code(500);
    error_log('mailbox-mcp: config error: ' . $e->getMessage());
    echo "Internal error\n";
    exit;
}

$accounts = AccountResolver::fromConfig($config);

// Registration order: list_accounts, list_folders, search_emails,
// get_email, get_thread, create_draft, send_draft, mark_processed,
// get_reply_guidelines. send_draft keeps its daily-cap counters under
// <paths.var>/state.
$tools = [
    new ListAccountsTool($accounts),
    new ListFoldersTool(),
    new SearchEmailsTool(),
    new GetEmailTool(),
    new GetThreadTool(),
    new CreateDraftTool(),
    new SendDraftTool($config['paths']['var'] . '/state'),
    new MarkProcessedTool(),
    new GetReplyGuidelinesTool(),
];

$kernel = new Kernel(
    $config,
    new SystemClock(),
    new MailSessionFactory(),
    $tools,
);

/** @var array<int, string> $trustedProxies */
$trustedProxies = $config['security']['trusted_proxies'];

$request = Request::fromGlobals($_SERVER, file_get_contents('php://input') ?: '', $trustedProxies);

$kernel->handle($request)->send();
