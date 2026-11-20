<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The tracking number of a shipment, written by staff (docs/features/delivery/spec.md, W8): 1 to 40 ASCII letters,
 * digits and hyphens. Input is trimmed of Unicode whitespace at its ends (`\s` and `\p{Z}`, so also NBSP, unlike
 * `trim()`); nothing but whitespace is no number. Anything else (a Polish letter, a space or a control character
 * inside, a text that is not valid UTF-8) is refused: the shape keeps the number safe in a link and in an email.
 */
final readonly class TrackingNumber
{
    public const int MAX_LENGTH = 40;
    public const string PATTERN = '/^[A-Za-z0-9-]{1,40}$/D';
    private const string EDGES = '/^[\s\p{Z}]+|[\s\p{Z}]+$/Du';

    private function __construct(private string $value)
    {
    }

    /**
     * The number of what staff typed; none for null and for nothing but whitespace.
     *
     * @throws InvalidOrderInput a text of another shape
     */
    public static function optional(?string $input): ?self
    {
        if (null === $input) {
            return null;
        }
        // A text that is not valid UTF-8 makes the trim fail (null); it is refused below as it is.
        $trimmed = preg_replace(self::EDGES, '', $input) ?? $input;
        if ('' === $trimmed) {
            return null;
        }
        if (1 !== preg_match(self::PATTERN, $trimmed)) {
            throw InvalidOrderInput::trackingNumber();
        }

        return new self($trimmed);
    }

    /**
     * A number as stored: no trimming, no repair.
     *
     * @throws InvalidOrderInput
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidOrderInput::trackingNumber();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
