<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The email address an order is confirmed to, trimmed, at most 254 characters. The API validates the format; the
 * domain checks the length, the `@` and that the value as it came has no control character.
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
        $raw = $value;
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < 1 || $length > self::MAX_LENGTH || !str_contains($value, '@') || ControlCharacters::in($raw)) {
            throw InvalidOrderInput::email();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
