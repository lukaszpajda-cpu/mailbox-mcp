<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Send;

use Closure;
use MailboxMcp\Mail\Mime\Address;
use MailboxMcp\Tools\ToolException;

/**
 * Server-side recipient rules applied by send_draft before anything is
 * handed to SMTP: at least one recipient, at most $maxRecipients, no
 * recipient in a denied domain, and — when an allow-list is configured —
 * every recipient in an allowed domain. Denied domains are checked
 * before allowed ones, so a domain on both lists is denied. Domains are
 * compared lowercase and exactly (a sub-domain is a different domain),
 * in their ASCII (punycode) form: a U-label recipient domain such as
 * `bücher.de` is converted with UTS #46 before comparison, so it matches
 * a configured `xn--bcher-kva.de`. When that conversion is impossible
 * (intl missing, or the domain does not convert) a non-ASCII recipient
 * domain is refused ("cannot be verified") rather than compared raw.
 */
final class RecipientPolicy
{
    /** @var list<string> */
    private readonly array $allowed;

    /** @var list<string> */
    private readonly array $denied;

    /** @var Closure(string): (string|false)|null */
    private readonly ?Closure $toAscii;

    /**
     * @param array<int, string>                     $allowedDomains empty = any domain
     * @param array<int, string>                     $deniedDomains
     * @param (Closure(string): (string|false))|null $toAscii        IDNA to-ASCII converter; null = intl's
     *                                                               idn_to_ascii (UTS #46, nontransitional)
     *                                                               when available, else none (tests inject
     *                                                               a failing one to exercise fail-closed)
     */
    public function __construct(
        private readonly int $maxRecipients,
        array $allowedDomains,
        array $deniedDomains,
        ?Closure $toAscii = null,
    ) {
        $this->toAscii = $toAscii ?? self::intlConverter();
        $this->allowed = $this->normalise($allowedDomains);
        $this->denied = $this->normalise($deniedDomains);
    }

    /**
     * @param array<int, Address> $addresses
     *
     * @throws ToolException naming the rule that failed
     */
    public function check(array $addresses): void
    {
        $count = count($addresses);

        if (0 === $count) {
            throw new ToolException('No recipients');
        }

        if ($count > $this->maxRecipients) {
            throw new ToolException("Too many recipients ({$count} > {$this->maxRecipients})");
        }

        foreach ($addresses as $address) {
            $domain = self::domainOf($address);
            $ascii = $this->ascii($domain);

            if (null === $ascii) {
                throw new ToolException("Recipient domain {$domain} cannot be verified");
            }

            $domain = $ascii;

            if (in_array($domain, $this->denied, true)) {
                throw new ToolException("Recipient domain {$domain} denied");
            }

            if ([] !== $this->allowed && !in_array($domain, $this->allowed, true)) {
                throw new ToolException("Recipient domain {$domain} not allowed");
            }
        }
    }

    private static function domainOf(Address $address): string
    {
        $lower = $address->lower();
        $at = strrpos($lower, '@');

        return false === $at ? '' : substr($lower, $at + 1);
    }

    /**
     * Lowercase ASCII form of $domain, or null when it is non-ASCII and
     * cannot be converted.
     */
    private function ascii(string $domain): ?string
    {
        $domain = mb_strtolower($domain, 'UTF-8');

        if (1 !== preg_match('/[^\x00-\x7F]/', $domain)) {
            return $domain;
        }

        if (null === $this->toAscii) {
            return null;
        }

        $converted = ($this->toAscii)($domain);

        return is_string($converted) && '' !== $converted ? strtolower($converted) : null;
    }

    /**
     * @return (Closure(string): (string|false))|null
     */
    private static function intlConverter(): ?Closure
    {
        if (!function_exists('idn_to_ascii')) {
            return null;
        }

        return static fn (string $domain): string|false => idn_to_ascii(
            $domain,
            IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46,
        );
    }

    /**
     * @param array<int, string> $domains
     *
     * @return list<string>
     */
    private function normalise(array $domains): array
    {
        $out = [];

        foreach ($domains as $domain) {
            $lower = mb_strtolower(trim($domain), 'UTF-8');
            $out[] = $this->ascii($lower) ?? $lower;
        }

        return $out;
    }
}
