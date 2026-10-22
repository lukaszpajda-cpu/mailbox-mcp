<?php

declare(strict_types=1);

namespace MailboxMcp\Accounts;

use InvalidArgumentException;
use MailboxMcp\Tools\ToolException;

/**
 * Resolves the `account` tool argument (or its absence) to a configured
 * {@see Account}.
 */
final class AccountResolver
{
    /** @var array<string, Account> */
    private readonly array $accounts;

    private readonly string $defaultId;

    /**
     * @param array<string, Account> $accounts id => Account; the first entry is the default
     */
    public function __construct(array $accounts)
    {
        if ([] === $accounts) {
            throw new InvalidArgumentException('accounts: must not be empty');
        }

        $this->accounts = $accounts;
        $this->defaultId = (string) array_key_first($accounts);
    }

    public function resolve(?string $id): Account
    {
        if (null === $id) {
            return $this->default();
        }

        if (!isset($this->accounts[$id])) {
            $valid = implode(', ', array_keys($this->accounts));

            throw new ToolException("Unknown account '{$id}'. Valid accounts: {$valid}");
        }

        return $this->accounts[$id];
    }

    public function default(): Account
    {
        return $this->accounts[$this->defaultId];
    }

    /**
     * @return array<string, Account>
     */
    public function all(): array
    {
        return $this->accounts;
    }

    /**
     * @param array<string, mixed> $config validated top-level config (see Config\Validator)
     */
    public static function fromConfig(array $config): self
    {
        $accountsCfg = $config['accounts'] ?? [];
        $accounts = [];

        if (is_array($accountsCfg)) {
            foreach ($accountsCfg as $id => $cfg) {
                if (is_array($cfg)) {
                    /** @var array<string, mixed> $cfg */
                    $accounts[(string) $id] = Account::fromConfig((string) $id, $cfg);
                }
            }
        }

        return new self($accounts);
    }
}
