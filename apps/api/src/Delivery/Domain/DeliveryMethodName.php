<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * The name of a delivery method the customer chooses in the checkout: 1 to 100 characters once trimmed of Unicode
 * whitespace, one line. Unique without regard to case among all methods, also the inactive ones (the unique index of
 * the table, A9).
 */
final readonly class DeliveryMethodName
{
    public const int MAX_LENGTH = 100;

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidDeliveryMethod
     */
    public static function of(string $name): self
    {
        if (DeliveryText::hasForbiddenCharacters($name)) {
            throw InvalidDeliveryMethod::nameCharacters();
        }
        $trimmed = DeliveryText::trim($name);
        if ('' === $trimmed) {
            throw InvalidDeliveryMethod::nameRequired();
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidDeliveryMethod::nameTooLong();
        }

        return new self($trimmed);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
