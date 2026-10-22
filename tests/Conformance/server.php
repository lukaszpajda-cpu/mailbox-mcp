<?php

declare(strict_types=1);

// Entry point for the MCP conformance harness: `php -S 127.0.0.1:8765
// tests/Conformance/server.php`, driven by bin/conformance.sh. Mirrors
// public/index.php, except the config is the fixed one in
// tests/Conformance/config.php (not Config\Loader::load(), which reads a
// deployment-specific file path) and the tool set is the two conformance
// tools in tests/Conformance/tools.php instead of the real mail tools.
//
// Self-contained: no reference to anything outside this repository
// checkout (no .superpowers paths, no environment-specific state).

use MailboxMcp\Config\Validator;
use MailboxMcp\Http\Request;
use MailboxMcp\Kernel;
use MailboxMcp\Support\SystemClock;
use MailboxMcp\Tests\Conformance\ErrorTool;
use MailboxMcp\Tests\Conformance\SimpleTextTool;
use MailboxMcp\Tests\Support\FakeSessionFactory;

ini_set('display_errors', '0');
error_reporting(E_ALL);

$appDir = dirname(__DIR__, 2);

require $appDir . '/src/autoload.php';
// Only MailboxMcp\Tests\Support\FakeSessionFactory (and the fake IMAP/SMTP
// clients it wires up) come from here; everything under MailboxMcp\ is
// already reachable via src/autoload.php above.
require $appDir . '/vendor/autoload.php';
require __DIR__ . '/tools.php';

/** @var array<string, mixed> $config */
$config = Validator::validate(require __DIR__ . '/config.php');

$kernel = new Kernel(
    $config,
    new SystemClock(),
    new FakeSessionFactory(),
    [new SimpleTextTool(), new ErrorTool()],
);

/** @var array<int, string> $trustedProxies */
$trustedProxies = $config['security']['trusted_proxies'];

$request = Request::fromGlobals($_SERVER, file_get_contents('php://input') ?: '', $trustedProxies);

$kernel->handle($request)->send();
