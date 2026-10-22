<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

/**
 * Which protocol era a request belongs to: the modern, header-mirrored
 * revision, or one of the legacy initialize-based revisions.
 */
enum Era: string
{
    case Modern = 'modern';
    case Legacy = 'legacy';

    /**
     * @param array<string, mixed> $params
     */
    public static function detect(array $params, ?string $protocolVersionHeader = null): self
    {
        $meta = $params['_meta'] ?? null;
        $meta = is_array($meta) ? $meta : [];

        $version = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;

        if (is_string($version)) {
            return self::Modern;
        }

        if (null !== $protocolVersionHeader && in_array(trim($protocolVersionHeader, " \t"), Versions::MODERN, true)) {
            return self::Modern;
        }

        return self::Legacy;
    }
}
