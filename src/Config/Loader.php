<?php

declare(strict_types=1);

namespace MailboxMcp\Config;

/**
 * Loads a config file (a PHP file returning an array), then validates it.
 */
final class Loader
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new ConfigException("config file not found: {$path}");
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new ConfigException('config file must return an array');
        }

        /** @var array<string, mixed> $config */
        return Validator::validate($config);
    }
}
