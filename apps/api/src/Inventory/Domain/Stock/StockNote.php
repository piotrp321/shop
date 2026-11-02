<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * The free text of a receipt or a correction, trimmed, at most 500 characters. Nothing but white space is no note.
 */
final readonly class StockNote
{
    public const int MAX_LENGTH = 500;

    private function __construct(private ?string $text)
    {
    }

    /**
     * @throws InvalidStockNote when the trimmed text is longer than 500 characters
     */
    public static function fromNullable(?string $text): self
    {
        $trimmed = trim($text ?? '');
        if ('' === $trimmed) {
            return self::none();
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidStockNote::tooLong();
        }

        return new self($trimmed);
    }

    public static function none(): self
    {
        return new self(null);
    }

    public function isEmpty(): bool
    {
        return null === $this->text;
    }

    public function toNullableString(): ?string
    {
        return $this->text;
    }

    public function equals(self $other): bool
    {
        return $this->text === $other->text;
    }
}
