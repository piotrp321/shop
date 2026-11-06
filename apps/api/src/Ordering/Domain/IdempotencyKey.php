<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The Idempotency-Key of a placement: 1 to 100 characters [A-Za-z0-9._:-], the format of the keys of Inventory.
 */
final readonly class IdempotencyKey
{
    private const string PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidOrderInput
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidOrderInput::idempotencyKey();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
