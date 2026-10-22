<?php

declare(strict_types=1);

/*
 * Copy this file to `mailbox-mcp.paths.php` in the same directory as
 * index.php / diag.php (the docroot) and fill in absolute paths. Both
 * entry points read it when the MAILBOX_MCP_APP / MAILBOX_MCP_CONFIG
 * environment variables are not set — the recommended way on
 * LiteSpeed/CloudLinux hosts, where `SetEnv` in .htaccess never reaches
 * PHP. The .htaccess never serves it (only index.php and diag.php are
 * reachable), and it contains no secret, only two paths.
 *
 * With a per-domain open_basedir (CloudLinux), both paths must be inside
 * the domain directory, e.g. /home/youruser/domains/mcp.example.com/app.
 */

return [
    // directory containing src/, bin/ and var/
    'app' => '/home/youruser/domains/mcp.example.com/app',
    // the config file (chmod 600)
    'config' => '/home/youruser/domains/mcp.example.com/config/config.php',
];
