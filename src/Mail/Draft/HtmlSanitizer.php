<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Draft;

/**
 * Reduces a model-authored HTML draft body to a small, safe subset before
 * it is stored as the `text/html` part of a draft: only
 * `p, br, b, strong, i, em, u, a, ul, ol, li, blockquote, h1, h2, h3, pre,
 * code` survive, every attribute is dropped except `a`'s `href` (and only
 * when its scheme is `http:`, `https:` or `mailto:`), `<script>`/`<style>`
 * are removed together with their content, and comments are dropped.
 * Every other tag is stripped but its text content is kept. Implemented
 * as a bounded regex tag walker (no DOM extension dependency): every
 * pattern uses a `[^<>]*` character class instead of a lazy `.*?`, so no
 * input can cause catastrophic backtracking.
 */
final class HtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'a',
        'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'pre', 'code',
    ];

    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http:', 'https:', 'mailto:'];

    /** Placeholder markers wrapping a rendered tag index while stray `<`/`>` are escaped. */
    private const PLACEHOLDER_OPEN = "\x01";
    private const PLACEHOLDER_CLOSE = "\x02";

    public static function clean(string $html): string
    {
        $html = mb_scrub($html, 'UTF-8');

        foreach (['script', 'style'] as $tag) {
            $html = self::removeElementWithContent($html, $tag);
        }

        $html = self::removeComments($html);

        /** @var list<string> $tags */
        $tags = [];

        $withPlaceholders = preg_replace_callback(
            '~<(/?)([a-zA-Z][a-zA-Z0-9]*)([^<>]*)>~',
            static function (array $m) use (&$tags): string {
                $tags[] = self::renderTag($m[1], $m[2], $m[3]);

                return self::PLACEHOLDER_OPEN . (count($tags) - 1) . self::PLACEHOLDER_CLOSE;
            },
            $html,
        ) ?? $html;

        // Anything left is never part of a recognised tag (that was
        // already replaced above), so any remaining '<' or '>' is a
        // stray character that must be escaped to keep the output
        // well-formed.
        $escaped = str_replace(['<', '>'], ['&lt;', '&gt;'], $withPlaceholders);

        $result = preg_replace_callback(
            '/' . self::PLACEHOLDER_OPEN . '(\d+)' . self::PLACEHOLDER_CLOSE . '/',
            static fn (array $m): string => $tags[(int) $m[1]] ?? '',
            $escaped,
        ) ?? $escaped;

        return $result;
    }

    private static function renderTag(string $slash, string $name, string $attrs): string
    {
        $tag = strtolower($name);

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            return '';
        }

        if ('/' === $slash) {
            return "</{$tag}>";
        }

        if ('a' === $tag) {
            $href = self::extractHref($attrs);

            return null === $href
                ? '<a>'
                : '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }

        return "<{$tag}>";
    }

    private static function extractHref(string $attrs): ?string
    {
        if (1 !== preg_match('/\bhref\s*=\s*(?:"([^"<>]*)"|\'([^\'<>]*)\'|([^\s<>]+))/i', $attrs, $m)) {
            return null;
        }

        $raw = $m[1] . ($m[2] ?? '') . ($m[3] ?? '');
        // Decoded once here, kept decoded, and re-encoded exactly once by
        // the caller (htmlspecialchars() in self::renderTag()): returning
        // $raw instead would make that single htmlspecialchars() call
        // double-encode any entity already present in the source
        // attribute (e.g. "&amp;" in a query string becoming "&amp;amp;").
        $decoded = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        foreach (self::ALLOWED_SCHEMES as $scheme) {
            if (0 === stripos($decoded, $scheme)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Removes `<$tag ...>…</$tag>` together with its content; unterminated
     * runs to end of input, matching {@see \MailboxMcp\Mail\Mime\HtmlToText::removeElement()}.
     */
    private static function removeElementWithContent(string $html, string $tag): string
    {
        $out = '';
        $offset = 0;
        $len = strlen($html);

        while ($offset < $len && 1 === preg_match('~<' . $tag . '\b[^<>]*>~i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $out .= substr($html, $offset, $start - $offset);
            $tagEnd = $start + strlen($m[0][0]);

            if (1 === preg_match('~</' . $tag . '\s*>~i', $html, $end, PREG_OFFSET_CAPTURE, $tagEnd)) {
                $offset = $end[0][1] + strlen($end[0][0]);
            } else {
                $offset = $len;
            }
        }

        return $out . substr($html, $offset);
    }

    private static function removeComments(string $html): string
    {
        $out = '';
        $offset = 0;

        while (false !== ($start = strpos($html, '<!--', $offset))) {
            $out .= substr($html, $offset, $start - $offset);
            $end = strpos($html, '-->', $start + 4);

            if (false === $end) {
                return $out;
            }

            $offset = $end + 3;
        }

        return $out . substr($html, $offset);
    }
}
