<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MailboxMcp\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
