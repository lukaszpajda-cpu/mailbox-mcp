<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Mime;

use MailboxMcp\Mail\Mime\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function testBareAddress(): void
    {
        $addrs = Address::parseList('a@b.com');

        $this->assertCount(1, $addrs);
        $this->assertSame('a@b.com', $addrs[0]->email);
        $this->assertNull($addrs[0]->name);
    }

    public function testQuotedDisplayNameWithComma(): void
    {
        $addrs = Address::parseList('"Smith, John" <j@x.com>, a@b.com');

        $this->assertCount(2, $addrs);
        $this->assertSame('Smith, John', $addrs[0]->name);
        $this->assertSame('j@x.com', $addrs[0]->email);
        $this->assertSame('a@b.com', $addrs[1]->email);
        $this->assertNull($addrs[1]->name);
    }

    public function testUnquotedDisplayName(): void
    {
        $addrs = Address::parseList('John Smith <j@x.com>');

        $this->assertCount(1, $addrs);
        $this->assertSame('John Smith', $addrs[0]->name);
        $this->assertSame('j@x.com', $addrs[0]->email);
    }

    public function testEncodedWordDisplayNameIsDecoded(): void
    {
        $addrs = Address::parseList('=?ISO-8859-2?Q?Zam=F3wienie?= <z@x.com>');

        $this->assertCount(1, $addrs);
        $this->assertSame('Zamówienie', $addrs[0]->name);
    }

    public function testParseListIgnoresComments(): void
    {
        $addrs = Address::parseList('John Smith (the boss) <j@x.com> (trusted)');

        $this->assertCount(1, $addrs);
        $this->assertSame('John Smith', $addrs[0]->name);
        $this->assertSame('j@x.com', $addrs[0]->email);
    }

    public function testParseListFlattensGroup(): void
    {
        $addrs = Address::parseList('undisclosed-recipients: a@b.com, c@d.com;');

        $this->assertCount(2, $addrs);
        $this->assertSame('a@b.com', $addrs[0]->email);
        $this->assertSame('c@d.com', $addrs[1]->email);
    }

    public function testParseListSkipsInvalid(): void
    {
        $addrs = Address::parseList('"bad" <not-an-address>, ok@x.com');

        $this->assertCount(1, $addrs);
        $this->assertSame('ok@x.com', $addrs[0]->email);
    }

    public function testParseListRejectsCrlfSegment(): void
    {
        $addrs = Address::parseList("x@a.com\r\nBcc: y@b.com");

        $this->assertSame([], $addrs);

        $addrs = Address::parseList("x@a.com, y@b.com\r\n");

        $this->assertCount(1, $addrs);
        $this->assertSame('x@a.com', $addrs[0]->email);
    }

    public function testParseListRejectsTwoAngleAddrsWithoutComma(): void
    {
        $addrs = Address::parseList('<a@b.com> <c@d.com>');

        $this->assertSame([], $addrs);
    }

    public function testParseListRejectsAngleInsideDisplayName(): void
    {
        $addrs = Address::parseList('=?UTF-8?Q?x_<evil@bad.com>_y?= <real@ok.com>, other@ok.com');

        $this->assertCount(1, $addrs);
        $this->assertSame('other@ok.com', $addrs[0]->email);
    }

    public function testParseListColonWithoutSemicolonIsInvalid(): void
    {
        $this->assertSame([], Address::parseList('Bcc: y@b.com'));

        $addrs = Address::parseList('team: a@b.com, c@d.com;');

        $this->assertCount(2, $addrs);
        $this->assertSame('a@b.com', $addrs[0]->email);
        $this->assertSame('c@d.com', $addrs[1]->email);
    }

    public function testParseListGroupWithQuotedSemicolonMemberStillWorks(): void
    {
        $addrs = Address::parseList('team: "x;y" <a@b.com>, c@d.com;, z@z.com');

        $this->assertCount(3, $addrs);
        $this->assertSame(
            ['a@b.com', 'c@d.com', 'z@z.com'],
            array_map(static fn (Address $a): string => $a->email, $addrs),
        );
        $this->assertSame('x;y', $addrs[0]->name);
    }

    public function testParseListUnterminatedGroupDoesNotEatRest(): void
    {
        $addrs = Address::parseList('a@b.com, x: y@z.com, w@w.com');

        $this->assertSame(
            ['a@b.com', 'w@w.com'],
            array_map(static fn (Address $a): string => $a->email, $addrs),
        );

        $addrs = Address::parseList('"q" <a@b.com>, Re: John <j@x.com>, w@w.com, v@v.com');

        $this->assertSame(
            ['a@b.com', 'w@w.com', 'v@v.com'],
            array_map(static fn (Address $a): string => $a->email, $addrs),
        );
    }

    public function testParseListUnterminatedQuoteDoesNotEatRest(): void
    {
        $addrs = Address::parseList('"broken <a@b.com>, ok@x.com');

        $emails = array_map(static fn (Address $a): string => $a->email, $addrs);

        $this->assertContains('ok@x.com', $emails);

        foreach ($emails as $email) {
            $this->assertTrue(
                'ok@x.com' === $email || 'a@b.com' === $email,
                "Unexpected fabricated address: {$email}",
            );
        }
    }

    public function testParseListMultipleAddresses(): void
    {
        $addrs = Address::parseList('a@b.com, "C One" <c@d.com>, Bare Name <e@f.com>');

        $this->assertCount(3, $addrs);
        $this->assertSame(['a@b.com', 'c@d.com', 'e@f.com'], array_map(static fn (Address $a): string => $a->email, $addrs));
        $this->assertSame('C One', $addrs[1]->name);
        $this->assertSame('Bare Name', $addrs[2]->name);
    }

    public function testInvalidAddressesRejected(): void
    {
        $this->assertFalse(Address::isValid('a@b'));
        $this->assertFalse(Address::isValid('a b@c.com'));
        $this->assertFalse(Address::isValid("a@c.com\r\n"));
        $this->assertFalse(Address::isValid('<a@c.com>'));
    }

    public function testIsValidAcceptsOrdinaryAddress(): void
    {
        $this->assertTrue(Address::isValid('a@b.com'));
        $this->assertTrue(Address::isValid('first.last+tag@sub.example.co.uk'));
    }

    public function testIsValidAcceptsLocalhost(): void
    {
        $this->assertTrue(Address::isValid('root@localhost'));
    }

    public function testIsValidRejectsDoubleDotLocalPart(): void
    {
        $this->assertFalse(Address::isValid('a..b@example.com'));
    }

    public function testIsValidRejectsLocalPartStartingOrEndingWithDot(): void
    {
        $this->assertFalse(Address::isValid('.a@example.com'));
        $this->assertFalse(Address::isValid('a.@example.com'));
    }

    public function testIsValidRejectsDomainWithoutDot(): void
    {
        $this->assertFalse(Address::isValid('a@example'));
    }

    public function testIsValidRejectsDomainLabelWithLeadingOrTrailingHyphen(): void
    {
        $this->assertFalse(Address::isValid('a@-example.com'));
        $this->assertFalse(Address::isValid('a@example-.com'));
    }

    public function testIsValidRejectsTooLongAddress(): void
    {
        $local = str_repeat('a', 64);
        $domain = str_repeat('b', 63) . '.com';
        $tooLong = $local . '@' . str_repeat($domain . '.', 4) . 'example.com';

        $this->assertGreaterThan(254, strlen($tooLong));
        $this->assertFalse(Address::isValid($tooLong));
    }

    public function testLowerLowercasesEmail(): void
    {
        $addr = new Address('Foo.BAR@Example.COM', null);

        $this->assertSame('foo.bar@example.com', $addr->lower());
    }
}
