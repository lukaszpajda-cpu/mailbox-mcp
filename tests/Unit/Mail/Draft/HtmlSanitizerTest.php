<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Draft;

use MailboxMcp\Mail\Draft\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testStripsScriptAndOnclick(): void
    {
        $html = '<script>alert(1)</script><p onclick="evil()">Hi</p>';

        $clean = HtmlSanitizer::clean($html);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertSame('<p>Hi</p>', $clean);
    }

    public function testStripsStyleWithContent(): void
    {
        $clean = HtmlSanitizer::clean('<style>body { color: red; }</style><p>Hi</p>');

        $this->assertSame('<p>Hi</p>', $clean);
    }

    public function testKeepsHttpAndHttpsAndMailtoLinks(): void
    {
        $this->assertSame(
            '<a href="https://example.com">x</a>',
            HtmlSanitizer::clean('<a href="https://example.com">x</a>'),
        );

        $this->assertSame(
            '<a href="http://example.com">x</a>',
            HtmlSanitizer::clean('<a href="http://example.com">x</a>'),
        );

        $this->assertSame(
            '<a href="mailto:a@b.com">x</a>',
            HtmlSanitizer::clean('<a href="mailto:a@b.com">x</a>'),
        );
    }

    public function testDropsJavascriptHrefButKeepsTagAndText(): void
    {
        $this->assertSame(
            '<a>bad</a>',
            HtmlSanitizer::clean('<a href="javascript:evil()">bad</a>'),
        );
    }

    public function testHrefAmpersandNotDoubleEncoded(): void
    {
        $clean = HtmlSanitizer::clean('<a href="https://x.com/?a=1&amp;b=2">link</a>');

        $this->assertSame('<a href="https://x.com/?a=1&amp;b=2">link</a>', $clean);
        $this->assertSame(1, substr_count($clean, '&amp;b=2'));
        $this->assertStringNotContainsString('&amp;amp;', $clean);
    }

    public function testDropsDisallowedAttributeButKeepsAllowedTag(): void
    {
        $this->assertSame(
            '<p>Hi</p>',
            HtmlSanitizer::clean('<p style="color:red" class="x">Hi</p>'),
        );
    }

    public function testStripsDisallowedTagButKeepsItsText(): void
    {
        $this->assertSame(
            'Hello World',
            HtmlSanitizer::clean('<div>Hello <span>World</span></div>'),
        );
    }

    public function testKeepsAllowedTagsList(): void
    {
        $html = '<p>p</p><br><b>b</b><strong>strong</strong><i>i</i><em>em</em><u>u</u>'
            . '<ul><li>li</li></ul><ol><li>li</li></ol><blockquote>bq</blockquote>'
            . '<h1>h1</h1><h2>h2</h2><h3>h3</h3><pre>pre</pre><code>code</code>';

        $clean = HtmlSanitizer::clean($html);

        foreach (['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'li', 'ol', 'blockquote', 'h1', 'h2', 'h3', 'pre', 'code'] as $tag) {
            $this->assertStringContainsString("<{$tag}>", $clean);
        }
    }

    public function testEscapesStrayAngleBrackets(): void
    {
        $this->assertSame('5 &lt; 3 and 3 &gt; 1', HtmlSanitizer::clean('5 < 3 and 3 > 1'));
    }

    public function testRemovesCommentsEntirely(): void
    {
        $this->assertSame('Hi', HtmlSanitizer::clean('<!-- secret -->Hi'));
    }

    public function testOutputIsValidUtf8(): void
    {
        $clean = HtmlSanitizer::clean('<p>Zażółć gęślą jaźń</p>');

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertSame('<p>Zażółć gęślą jaźń</p>', $clean);
    }
}
