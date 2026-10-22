<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

/**
 * The protocol revisions this server understands.
 */
final class Versions
{
    /** @var array<int, string> */
    public const MODERN = ['2026-07-28'];

    /** @var array<int, string> */
    public const LEGACY = ['2025-03-26', '2025-06-18', '2025-11-25'];

    public const LATEST_LEGACY = '2025-11-25';
}
