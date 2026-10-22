<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Mail\MessageReader;
use MailboxMcp\Mail\ReadMessage;
use MailboxMcp\Mail\ThreadCollector;

/**
 * Collects the conversation around a message by following
 * Message-ID/In-Reply-To/References across the given folder, the inbox
 * and the sent folder ({@see ThreadCollector}), then renders every
 * message exactly like {@see GetEmailTool}, oldest first. A role
 * (inbox/sent) that cannot be resolved for the account is silently
 * skipped rather than failing the whole call.
 */
final class GetThreadTool implements ToolInterface
{
    private const DEFAULT_MAX_MESSAGES = 20;

    private const DEFAULT_MAX_CHARS_PER_MESSAGE = 4000;

    /** Fallback for limits.max_chars (same as the config default); the whole output is capped at twice it. */
    private const DEFAULT_MAX_TOTAL_CHARS = 50000;

    public function name(): string
    {
        return 'get_thread';
    }

    /**
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     annotations: array{
     *         readOnlyHint: bool,
     *         destructiveHint: bool,
     *         idempotentHint: bool,
     *         openWorldHint: bool,
     *     },
     * }
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'title' => 'Get thread',
            'description' => 'Collects the conversation around a message by Message-ID/In-Reply-To/References across the given folder, the inbox and the sent folder, oldest first.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string'],
                    'folder' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'pattern' => '^[^\r\n\0]+$',
                    ],
                    'uid' => ['type' => 'integer', 'minimum' => 1],
                    'max_messages' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => self::DEFAULT_MAX_MESSAGES],
                    'max_chars_per_message' => ['type' => 'integer', 'minimum' => 200, 'maximum' => 20000, 'default' => self::DEFAULT_MAX_CHARS_PER_MESSAGE],
                ],
                'required' => ['folder', 'uid'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $folderArg = is_string($args['folder'] ?? null) ? $args['folder'] : '';
        $folder = $ctx->session->folderByName($folderArg);

        $uid = is_int($args['uid'] ?? null) ? $args['uid'] : 0;
        $maxMessages = is_int($args['max_messages'] ?? null) ? $args['max_messages'] : self::DEFAULT_MAX_MESSAGES;
        $maxCharsPerMessage = is_int($args['max_chars_per_message'] ?? null)
            ? $args['max_chars_per_message']
            : self::DEFAULT_MAX_CHARS_PER_MESSAGE;

        $folderNames = [$folder->name];

        foreach (['inbox', 'sent'] as $role) {
            try {
                $folderNames[] = $ctx->session->folderForRole($role)->name;
            } catch (ToolException) {
                // Role not detected for this account: searched folders are
                // just the given folder (plus whichever role does resolve).
            }
        }

        $imap = $ctx->session->imap();
        $collector = new ThreadCollector($imap, $folderNames);
        $pairs = $collector->collect($folder->name, $uid, $maxMessages);

        $reader = new MessageReader($imap);
        $messages = [];

        foreach ($pairs as $pair) {
            $messages[] = $reader->read($pair['folder'], $pair['uid'], $maxCharsPerMessage);
        }

        usort($messages, self::compareByDate(...));

        $blocks = array_map(
            static fn (ReadMessage $message): string => GetEmailTool::render($ctx->account->id, $message),
            $messages,
        );

        $text = 'thread: ' . count($messages) . " messages\n\n" . implode("\n\n", $blocks);
        $maxChars = is_int($ctx->limits['max_chars'] ?? null) && $ctx->limits['max_chars'] > 0
            ? $ctx->limits['max_chars']
            : self::DEFAULT_MAX_TOTAL_CHARS;

        return ToolResult::text(self::capTotal($text, $maxChars * 2));
    }

    /**
     * Caps the whole thread output at $cap characters. A cut never leaves
     * a dangling partial tag, and an `<email_content>` envelope it falls
     * inside is closed again (the neutralized content cannot hold a
     * closing tag of its own, so the last opening/closing tags tell),
     * followed by a `[thread output truncated]` line.
     */
    private static function capTotal(string $text, int $cap): string
    {
        if (mb_strlen($text, 'UTF-8') <= $cap) {
            return $text;
        }

        $cut = mb_substr($text, 0, $cap, 'UTF-8');
        $cut = (string) preg_replace('/<[^>\n]*$/u', '', $cut);

        $open = strrpos($cut, '<email_content');
        $close = strrpos($cut, '</email_content>');

        if (false !== $open && (false === $close || $close < $open)) {
            $cut = rtrim($cut, "\n") . "\n</email_content>";
        }

        return rtrim($cut, "\n") . "\n[thread output truncated]";
    }

    private static function compareByDate(ReadMessage $a, ReadMessage $b): int
    {
        if (null === $a->rawDate && null === $b->rawDate) {
            return $a->uid <=> $b->uid;
        }

        if (null === $a->rawDate) {
            return 1;
        }

        if (null === $b->rawDate) {
            return -1;
        }

        $cmp = $a->rawDate <=> $b->rawDate;

        return 0 !== $cmp ? $cmp : $a->uid <=> $b->uid;
    }
}
