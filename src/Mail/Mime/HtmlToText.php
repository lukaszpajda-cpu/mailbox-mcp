<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * Converts an HTML mail body to readable plain text for an LLM: drops
 * comments, `<head>`, `<script>` and `<style>`; turns `<br>` and the end
 * of block elements into line breaks, list items into `- ` lines and
 * links into `text <url>` (just the text when it equals the URL); strips
 * every other tag and decodes entities (`&nbsp;` becomes a plain space).
 *
 * Output uses `\n`, has no trailing whitespace on lines, at most one
 * blank line in a row, and is trimmed. Malformed or binary input never
 * throws or warns: unterminated comments/script/style run to end of
 * input and a failed regex leaves the text as it was.
 */
final class HtmlToText
{
    /** Marks a line break that only happens when not already at line start. */
    private const SOFT = "\x03";

    /** Wraps a link placeholder index so tag stripping leaves it alone. */
    private const LINK_OPEN = "\x01";
    private const LINK_CLOSE = "\x02";

    /**
     * Link text longer than this is left as plain text (no `<url>`). The tempered pattern stops at the next `<a`, keeping
     * unclosed anchors linear.
     */
    private const MAX_LINK_TEXT = 2000;

    /** Closing tags that always end the line. */
    private const HARD_CLOSE = 'p|div|li|h[1-6]|tr|blockquote';

    /** Block elements whose opening (and other closings) start a fresh line if needed. */
    private const BLOCK = 'p|div|li|h[1-6]|tr|blockquote|ul|ol|table|pre|section|article|header|footer|dl|dt|dd|hr';

    public static function convert(string $html): string
    {
        $s = str_replace([self::SOFT, self::LINK_OPEN, self::LINK_CLOSE], '', $html);

        $s = self::removeBetween($s, '<!--', '-->');

        $s = self::removeHead($s);

        foreach (['script', 'style'] as $tag) {
            $s = self::removeElement($s, $tag);
        }

        // Source whitespace (including newlines) is insignificant in HTML.
        $s = self::re('/\s+/', ' ', $s);

        // Every part of the anchor pattern stops at the next `<` (attributes)
        // or the next `<a` (text), so unclosed anchors cost linear time.
        $links = [];
        $s = preg_replace_callback(
            '~<a\b[^<>]*?\bhref\s*=\s*(?:"([^"<>]*)"|\'([^\'<>]*)\'|([^\s<>]+))[^<>]*>((?:(?!<a\b|</a\s*>).)*)</a\s*>~is',
            static function (array $m) use (&$links): string {
                if (strlen($m[4]) > self::MAX_LINK_TEXT) {
                    return $m[4];
                }

                $url = self::decode(trim($m[1] . $m[2] . $m[3]));
                $text = trim(self::re('/\s+/', ' ', self::decode(self::re('/<[^>]*>/', '', $m[4]))));

                if ('' === $url || $text === $url || str_starts_with(strtolower($url), 'javascript:')) {
                    $replacement = $text;
                } elseif ('' === $text) {
                    $replacement = '<' . $url . '>';
                } else {
                    $replacement = $text . ' <' . $url . '>';
                }

                $links[] = $replacement;

                return self::LINK_OPEN . (count($links) - 1) . self::LINK_CLOSE;
            },
            $s,
        ) ?? $s;

        $s = self::re('~<br\b[^>]*>~i', "\n", $s);
        $s = self::re('~<li\b[^>]*>~i', self::SOFT . '- ', $s);
        $s = self::re('~</(?:' . self::HARD_CLOSE . ')\s*>~i', "\n", $s);
        $s = self::re('~</?(?:' . self::BLOCK . ')\b[^>]*>~i', self::SOFT, $s);
        $s = self::re('~</t[dh]\s*>~i', ' ', $s);

        // Remaining tags, including one left unterminated at end of input.
        $s = self::re('~</?[a-zA-Z!?][^>]*>~', '', $s);
        $s = self::re('~</?[a-zA-Z!?][^>]*$~', '', $s);

        $s = self::decode($s);
        $s = str_replace("\u{00A0}", ' ', $s);

        $s = preg_replace_callback(
            '/' . self::LINK_OPEN . '(\d+)' . self::LINK_CLOSE . '/',
            static fn (array $m): string => $links[(int) $m[1]] ?? '',
            $s,
        ) ?? $s;

        // Resolve soft breaks: drop them at line start, else one newline.
        $s = self::re('/' . self::SOFT . '[ \t' . self::SOFT . ']*/', self::SOFT, $s);
        $s = self::re('/(^|\n)[ \t]*' . self::SOFT . '/', '$1', $s);
        $s = str_replace(self::SOFT, "\n", $s);

        return self::tidy($s);
    }

    private static function tidy(string $s): string
    {
        $out = [];
        $blank = false;

        foreach (explode("\n", str_replace("\r", '', $s)) as $line) {
            $line = trim(self::re('/[ \t]+/', ' ', $line));

            if ('' === $line) {
                if (!$blank && [] !== $out) {
                    $out[] = '';
                }

                $blank = true;
                continue;
            }

            $out[] = $line;
            $blank = false;
        }

        return trim(implode("\n", $out));
    }

    /**
     * Removes `<head>…</head>`; when `</head>` is missing the head ends at
     * `<body`, and with neither present nothing is removed.
     */
    private static function removeHead(string $s): string
    {
        if (1 !== preg_match('~<head\b~i', $s, $m, PREG_OFFSET_CAPTURE)) {
            return $s;
        }

        $start = $m[0][1];

        if (1 === preg_match('~</head\s*>~i', $s, $end, PREG_OFFSET_CAPTURE, $start)) {
            return substr($s, 0, $start) . substr($s, $end[0][1] + strlen($end[0][0]));
        }

        if (1 === preg_match('~<body\b~i', $s, $body, PREG_OFFSET_CAPTURE, $start)) {
            return substr($s, 0, $start) . substr($s, $body[0][1]);
        }

        return $s;
    }

    private static function removeElement(string $s, string $tag): string
    {
        $out = '';
        $offset = 0;
        $len = strlen($s);

        while ($offset < $len && 1 === preg_match('~<' . $tag . '\b~i', $s, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $out .= substr($s, $offset, $start - $offset);

            if (1 === preg_match('~</' . $tag . '\s*>~i', $s, $end, PREG_OFFSET_CAPTURE, $start)) {
                $offset = $end[0][1] + strlen($end[0][0]);
            } else {
                $offset = $len;
            }
        }

        return $out . substr($s, $offset);
    }

    private static function removeBetween(string $s, string $open, string $close): string
    {
        $out = '';
        $offset = 0;

        while (false !== ($start = strpos($s, $open, $offset))) {
            $out .= substr($s, $offset, $start - $offset);
            $end = strpos($s, $close, $start + strlen($open));

            if (false === $end) {
                return $out;
            }

            $offset = $end + strlen($close);
        }

        return $out . substr($s, $offset);
    }

    private static function decode(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function re(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
