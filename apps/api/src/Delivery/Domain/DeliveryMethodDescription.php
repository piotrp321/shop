<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * What the customer reads under the name of a method, usually the time of the delivery: 0 to 200 characters once
 * trimmed of Unicode whitespace, one line (a line break is a control character). No description is the empty text.
 */
final readonly class DeliveryMethodDescription
{
    public const int MAX_LENGTH = 200;

    private function __construct(private string $value)
    {
    }

    /**
     * @param string|null $description null and a text of white space only are no description
     *
     * @throws InvalidDeliveryMethod
     */
    public static function of(?string $description): self
    {
        if (null === $description) {
            return new self('');
        }
        if (DeliveryText::hasForbiddenCharacters($description)) {
            throw InvalidDeliveryMethod::descriptionCharacters();
        }
        $trimmed = DeliveryText::trim($description);
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidDeliveryMethod::descriptionTooLong();
        }

        return new self($trimmed);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
