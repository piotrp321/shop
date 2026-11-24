<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Text;

/**
 * The email address an order is confirmed to, at most 254 characters once trimmed by the rule of texts (Text: the
 * white space and the invisible characters at its ends). The API validates the format; the domain checks the length,
 * the `@` and that the value as it came has no forbidden character of the rule.
 */
final readonly class EmailAddress
{
    public const int MAX_LENGTH = 254;

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidOrderInput
     */
    public static function fromString(string $value): self
    {
        $trimmed = Text::normalize($value);
        $length = mb_strlen($trimmed);
        if (Text::hasForbiddenCharacters($value) || $length < 1 || $length > self::MAX_LENGTH || !str_contains($trimmed, '@')) {
            throw InvalidOrderInput::email();
        }

        return new self($trimmed);
    }

    /**
     * An address as stored, without the rules: an order stored before a stricter rule is still read and processed
     * (spec api-input-contract, E7); only a placement goes through fromString().
     */
    public static function stored(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
