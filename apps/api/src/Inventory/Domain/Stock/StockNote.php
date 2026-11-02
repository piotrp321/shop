<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * The free text of a receipt or a correction, trimmed, at most 500 characters. Nothing but white space is no note.
 * Control characters other than tab and line breaks are refused, before trimming: NUL would be cut off by the database
 * driver without a word, so the history would not keep what was sent.
 */
final readonly class StockNote
{
    public const int MAX_LENGTH = 500;
    /** C0 control characters but tab, line feed and carriage return, and DEL. */
    public const string CONTROL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    private function __construct(private ?string $text)
    {
    }

    /**
     * @throws InvalidStockNote when the text has a control character other than tab and line breaks, or the trimmed
     *                          text is longer than 500 characters
     */
    public static function fromNullable(?string $text): self
    {
        if (null !== $text && 1 === preg_match(self::CONTROL_CHARACTERS, $text)) {
            throw InvalidStockNote::controlCharacters();
        }
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
