<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use InvalidArgumentException;
use RuntimeException;

/**
 * Marks mail-derived text as untrusted data for the model: a defence
 * against prompt injection carried inside message bodies, subjects, or
 * headers. Content is wrapped in an `<email_content untrusted="true">`
 * envelope and any attempt within that content to fake a closing/opening
 * `<email_content>` tag is neutralized first.
 */
final class Untrusted
{
    private const ATTRIBUTE_KEY_PATTERN = '/^[a-z_]+$/';

    /**
     * @param array<string, string> $attrs
     */
    public static function wrap(array $attrs, string $content): string
    {
        $rendered = ' untrusted="true"';

        foreach ($attrs as $key => $value) {
            if ('untrusted' === $key) {
                continue;
            }

            if (1 !== preg_match(self::ATTRIBUTE_KEY_PATTERN, $key)) {
                throw new InvalidArgumentException("Untrusted::wrap: invalid attribute key '{$key}'");
            }

            $rendered .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return '<email_content' . $rendered . ">\n" . self::neutralize($content) . "\n</email_content>";
    }

    public static function neutralize(string $s): string
    {
        // Scrub invalid UTF-8 first: the `u` modifier makes preg_replace()
        // return null (i.e. "no match, leave input as-is") on any invalid
        // byte, which would otherwise let a single stray byte disable the
        // neutralization entirely. Matching is then done byte-wise (no `u`
        // modifier) since the pattern itself is plain ASCII.
        $s = mb_scrub($s, 'UTF-8');

        $result = preg_replace('/<(?=\/?email_content)/i', "\u{2039}", $s);

        if (null === $result) {
            throw new RuntimeException('neutralize failed');
        }

        return $result;
    }
}
