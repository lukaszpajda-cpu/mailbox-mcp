<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * One node of a MIME tree, as described by an IMAP `BODYSTRUCTURE`
 * response ({@see BodyStructure}) or found while parsing a raw message
 * ({@see Parser}).
 *
 * `section` is the IMAP section number used with `BODY[<section>]`:
 * `1` for a single-part message, `1`, `2`, … for the children of the
 * root multipart, `1.1`, `1.2`, … below them. The root multipart itself
 * has an empty section. `type`, `subtype`, `encoding`, `charset` and
 * parameter names are lowercased; `size` is the encoded size reported by
 * the server (BODYSTRUCTURE) or the decoded byte length (Parser).
 */
final class Part
{
    public string $section = '';
    public string $type = 'text';
    public string $subtype = 'plain';
    /** @var array<string, string> */
    public array $params = [];
    public ?string $disposition = null;
    public ?string $filename = null;
    public int $size = 0;
    public string $encoding = '';
    public string $charset = '';
    /** @var list<Part> */
    public array $children = [];

    public function isText(): bool
    {
        return 'text' === $this->type;
    }

    public function isMultipart(): bool
    {
        return 'multipart' === $this->type;
    }

    /**
     * True for parts that are listed as attachments rather than read as
     * the message body: anything with `Content-Disposition: attachment`,
     * and any non-text leaf unless it is explicitly `inline` without a
     * filename (e.g. an unnamed cid: image). Inline images that carry a
     * filename count — they are listed, never fetched as body text.
     */
    public function isAttachment(): bool
    {
        if ('attachment' === $this->disposition) {
            return true;
        }

        if ($this->isMultipart() || $this->isText()) {
            return false;
        }

        return null !== $this->filename || 'inline' !== $this->disposition;
    }

    public function mime(): string
    {
        return strtolower($this->type . '/' . $this->subtype);
    }
}
