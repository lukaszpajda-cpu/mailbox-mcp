<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Mail\Send;

use MailboxMcp\Mail\Mime\Address;
use MailboxMcp\Mail\Send\RecipientPolicy;
use MailboxMcp\Tools\ToolException;
use PHPUnit\Framework\TestCase;

final class RecipientPolicyTest extends TestCase
{
    /**
     * @return list<Address>
     */
    private function addrs(string ...$emails): array
    {
        return array_values(array_map(static fn (string $e): Address => new Address($e, null), $emails));
    }

    public function testPolicyAcceptsAnyDomainWhenAllowListEmpty(): void
    {
        (new RecipientPolicy(10, [], []))->check($this->addrs('a@b.com', 'c@d.org'));
        $this->addToAssertionCount(1);
    }

    public function testPolicyNoRecipients(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No recipients');

        (new RecipientPolicy(10, [], []))->check([]);
    }

    public function testPolicyTooManyRecipients(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Too many recipients (3 > 2)');

        (new RecipientPolicy(2, [], []))->check($this->addrs('a@b.com', 'c@b.com', 'd@b.com'));
    }

    public function testPolicyDeniedDomain(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Recipient domain evil.com denied');

        (new RecipientPolicy(10, [], ['Evil.COM']))->check($this->addrs('ok@good.com', 'x@EVIL.com'));
    }

    public function testPolicyAllowedDomainsWhitelist(): void
    {
        $policy = new RecipientPolicy(10, ['Example.com'], []);
        $policy->check($this->addrs('a@EXAMPLE.com'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Recipient domain other.com not allowed');

        $policy->check($this->addrs('a@example.com', 'b@other.com'));
    }

    public function testRecipientPolicyDeniedBeforeAllowed(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Recipient domain example.com denied');

        (new RecipientPolicy(10, ['example.com'], ['example.com']))->check($this->addrs('a@example.com'));
    }

    public function testDenyListMatchesIdnDomain(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension (idn_to_ascii) is not available');
        }

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Recipient domain xn--bcher-kva.de denied');

        (new RecipientPolicy(10, [], ['xn--bcher-kva.de']))->check($this->addrs('x@bücher.de'));
    }

    public function testAllowListMatchesIdnDomainConfiguredAsULabel(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension (idn_to_ascii) is not available');
        }

        (new RecipientPolicy(10, ['Bücher.de'], []))->check($this->addrs('x@xn--bcher-kva.de', 'y@BÜCHER.de'));
        $this->addToAssertionCount(1);
    }

    /**
     * The injected converter stands in for "intl missing / conversion
     * failed"; both take the same fail-closed path.
     */
    public function testNonAsciiDomainWithoutIntlFailsClosed(): void
    {
        $noIdn = static fn (string $domain): false => false;
        $policy = new RecipientPolicy(10, [], [], $noIdn);

        // ASCII domains are unaffected by the converter
        $policy->check($this->addrs('a@example.com'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Recipient domain bücher.de cannot be verified');
        $policy->check($this->addrs('x@bücher.de'));
    }
}
