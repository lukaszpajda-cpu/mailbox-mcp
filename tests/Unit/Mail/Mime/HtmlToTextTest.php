<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Mime;

use MailboxMcp\Mail\Mime\HtmlToText;
use PHPUnit\Framework\TestCase;

final class HtmlToTextTest extends TestCase
{
    public function testHtmlToTextLinksAndLists(): void
    {
        $html = "<p>Hello <b>Jan</b>,</p>\n<ul>\n  <li>First</li>\n  <li>Second <a href=\"https://example.com/x?a=1&amp;b=2\">details</a></li>\n</ul>"
            . '<p>Visit <a href="https://example.com">https://example.com</a></p><div>line one<br>line two<br/>end</div>';

        $this->assertSame(
            "Hello Jan,\n- First\n- Second details <https://example.com/x?a=1&b=2>\nVisit https://example.com\nline one\nline two\nend",
            HtmlToText::convert($html),
        );
    }

    public function testHtmlToTextDropsScript(): void
    {
        $html = '<html><head><title>Secret title</title><meta charset="utf-8"><style>p { color: red }</style></head>'
            . '<body><script type="text/javascript">alert("x < y");</script><p>Visible</p><STYLE>.a{}</STYLE>'
            . '<!-- hidden comment --><h1>Title</h1><script>unterminated';

        $this->assertSame("Visible\nTitle", HtmlToText::convert($html));
    }

    public function testHtmlToTextEntitiesAndNbsp(): void
    {
        $html = '<p>Caf&eacute;&nbsp;&amp;&nbsp;bar &lt;b&gt; &quot;q&quot; &#39;s&#39; &#x17C;</p>';

        $this->assertSame("Café & bar <b> \"q\" 's' ż", HtmlToText::convert($html));
    }

    public function testBlankLinesCollapsedAndTrimmed(): void
    {
        $html = "  <p>a   b\t c  </p><br><br><br><br><p>  d</p>\n\n<table><tr><td>x</td><td>y</td></tr></table>  ";

        $this->assertSame("a b c\n\nd\nx y", HtmlToText::convert($html));
    }

    public function testMultibyteCharactersSurvive(): void
    {
        // UTF-8 "ą" is C4 85 and "à" is C3 A0: their trailing bytes must
        // not be mistaken for NEL / NBSP whitespace.
        $this->assertSame("Piątek à la carte\nŻółć", HtmlToText::convert("<p>Piątek  à la carte</p>\n<div>Żółć</div>"));
    }

    public function testHtmlToTextUnclosedAnchorsAreLinear(): void
    {
        $html = str_repeat('<a href=x>t ', 50000); // 600 KB
        $start = hrtime(true);
        $text = HtmlToText::convert($html);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertLessThan(1.0, $elapsed);
        $this->assertStringStartsWith('t t t', $text);

        foreach ([str_repeat('<a href=', 75000), str_repeat('<a href="x', 60000)] as $html) {
            $start = hrtime(true);
            HtmlToText::convert($html);
            $this->assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
        }
    }

    public function testHtmlToTextUnclosedHeadKeepsBody(): void
    {
        $this->assertSame('Body text', HtmlToText::convert('<html><head><title>T</title><body><p>Body text</p>'));
        $this->assertSame("T\nBody text", HtmlToText::convert('<html><head><title>T</title><p>Body text</p>'));
    }

    public function testGarbageDoesNotThrow(): void
    {
        $this->assertSame('', HtmlToText::convert(''));
        $this->assertStringNotContainsString('<a', HtmlToText::convert("\x00\xff<a href=\"\xfe\">\xc3</a><<<>>><!--"));
        $this->assertSame('a < b', HtmlToText::convert('a < b'));
    }
}
