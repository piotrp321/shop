<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Shared\Domain\Text;

/**
 * The name of a delivery method the customer chooses in the checkout: a text by the rule of texts (Text: no control
 * characters, trimmed ends, at least one visible character), 1 to 100 characters once trimmed. Unique without regard
 * to case among all methods, also the inactive ones (the unique index of the table, A9).
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
        if (Text::hasForbiddenCharacters($name)) {
            throw InvalidDeliveryMethod::nameCharacters();
        }
        $trimmed = Text::normalize($name);
        if ('' === $trimmed) {
            throw InvalidDeliveryMethod::nameRequired();
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidDeliveryMethod::nameTooLong();
        }

        return new self($trimmed);
    }

    /**
     * A name as stored, without the rule: a name stored before a stricter rule is still read (spec api-input-contract,
     * E7); only a write goes through of().
     */
    public static function stored(string $name): self
    {
        return new self($name);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
