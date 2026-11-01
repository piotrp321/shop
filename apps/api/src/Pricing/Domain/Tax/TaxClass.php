<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Tax;

/**
 * The tax class of a priced product as a text code (e.g. `printed_book`). Pricing's own value: the code comes from
 * Catalog through a port, the rate of the class from {@see VatRates}.
 */
final readonly class TaxClass
{
    private const string PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    private function __construct(private string $code)
    {
    }

    public static function fromString(string $code): self
    {
        if (1 !== preg_match(self::PATTERN, $code)) {
            throw new \InvalidArgumentException('A tax class is a lower-case code of at most 32 characters.');
        }

        return new self($code);
    }

    public function toString(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
