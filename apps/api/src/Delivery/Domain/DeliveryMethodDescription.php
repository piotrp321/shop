<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Shared\Domain\Text;

/**
 * What the customer reads under the name of a method, usually the time of the delivery: a text by the rule of texts
 * (Text), 0 to 200 characters once trimmed, one line (a line break is a control character). No description, also a
 * text the rule takes for empty, is the empty text.
 */
final readonly class DeliveryMethodDescription
{
    public const int MAX_LENGTH = 200;

    private function __construct(private string $value)
    {
    }

    /**
     * @param string|null $description null and a text without a visible character are no description
     *
     * @throws InvalidDeliveryMethod
     */
    public static function of(?string $description): self
    {
        if (null === $description) {
            return new self('');
        }
        if (Text::hasForbiddenCharacters($description)) {
            throw InvalidDeliveryMethod::descriptionCharacters();
        }
        $trimmed = Text::normalize($description);
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidDeliveryMethod::descriptionTooLong();
        }

        return new self($trimmed);
    }

    /**
     * A description as stored, without the rule (spec api-input-contract, E7); only a write goes through of().
     */
    public static function stored(string $description): self
    {
        return new self($description);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
