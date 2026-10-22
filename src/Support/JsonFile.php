<?php

declare(strict_types=1);

namespace MailboxMcp\Support;

use RuntimeException;

/**
 * Safe read-modify-write for small JSON files shared across short-lived
 * PHP processes. {@see self::update()} is the single primitive used for
 * this: it opens the file for reading and writing, takes an exclusive
 * lock, decodes the current contents, lets the caller compute the new
 * data, writes it back in place, and releases the lock before closing.
 *
 * There is no separate lock file: the lock lives on the data file itself
 * for the duration of the update, which is enough because every writer
 * goes through this class.
 */
final class JsonFile
{
    /**
     * @return array<string, mixed>
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        return self::decode(false === $contents ? '' : $contents);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     *
     * @return array<string, mixed>
     */
    public static function update(string $path, callable $fn): array
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('unable to create directory: ' . $dir);
        }

        $handle = fopen($path, 'c+');

        if (false === $handle) {
            throw new RuntimeException('unable to open file: ' . $path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('unable to lock file: ' . $path);
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $data = self::decode(false === $contents ? '' : $contents);

            $new = $fn($data);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($new, JSON_THROW_ON_ERROR));
            fflush($handle);

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $contents): array
    {
        if ('' === trim($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
