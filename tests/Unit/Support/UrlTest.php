<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Support;

use MailboxMcp\Support\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function testIsPlainHttps(): void
    {
        foreach ([
            'https://www.example.com/availability.php',
            'HTTPS://www.example.com/availability.php',
            'https://www.example.com:8443/a?x=1',
        ] as $url) {
            $this->assertTrue(Url::isPlainHttps($url), $url);
        }

        foreach ([
            'http://www.example.com/a.php',
            'ftp://www.example.com/a.json',
            'file:///etc/passwd',
            'https://user:pass@www.example.com/a.php',
            'https://user@www.example.com/a.php',
            '//www.example.com/a.php',
            '/a.php',
            'https:///a.php',
            'https://www.example.com/a b.php',
            "https://www.example.com/a.php\n",
            "https://www.example.com/a\x00.php",
            '',
        ] as $url) {
            $this->assertFalse(Url::isPlainHttps($url), json_encode($url, JSON_THROW_ON_ERROR));
        }
    }
}
