<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Text;

/**
 * The free text of a receipt or a correction by the rule of texts (Text): one without a visible character is no note,
 * the ends are trimmed of Unicode white space and invisible characters, at most 500 characters once trimmed. The note
 * has lines, so the tab, the line feed and the carriage return are allowed; every other control character and the
 * separators of lines and paragraphs are refused, looked for in the note as it came: NUL would be cut off by the
 * database driver without a word, so the history would not keep what was sent.
 */
final readonly class StockNote
{
    public const int MAX_LENGTH = 500;
    /** The characters of T1 a note allows: it has lines. */
    public const string ALLOWED = "\t\n\r";

    private function __construct(private ?string $text)
    {
    }

    /**
     * @throws InvalidStockNote when the text has a character the rule of texts forbids (other than tab and line
     *                          breaks), or the trimmed text is longer than 500 characters
     */
    public static function fromNullable(?string $text): self
    {
        if (null !== $text && Text::hasForbiddenCharacters($text, self::ALLOWED)) {
            throw InvalidStockNote::controlCharacters();
        }
        $trimmed = Text::normalize($text ?? '');
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

    /**
     * Whether this note is the one stored as `$stored`: a note stored before the rule of texts trimmed the ends of
     * every Unicode white space and invisible character kept them, so it is compared trimmed, without validation.
     */
    public function matchesStored(?string $stored): bool
    {
        $trimmed = Text::normalize($stored ?? '');

        return $this->text === ('' === $trimmed ? null : $trimmed);
    }
}
