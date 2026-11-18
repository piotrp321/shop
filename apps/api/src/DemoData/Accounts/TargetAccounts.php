<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * The target set of one realm: the customers or the staff members 1 to `count` of the generator.
 */
final readonly class TargetAccounts
{
    public function __construct(
        private AccountGenerator $generator,
        public bool $staff,
        public int $count,
    ) {
        if ($count < 0) {
            throw new \InvalidArgumentException('The number of accounts cannot be negative.');
        }
    }

    public function id(int $number): string
    {
        return $this->staff ? $this->generator->staffId($number) : $this->generator->customerId($number);
    }

    public function account(int $number): GeneratedAccount
    {
        return $this->staff ? $this->generator->staff($number) : $this->generator->customer($number);
    }
}
