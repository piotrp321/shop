<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The rules of the note of a staff member to a change of an order: one line, trimmed, 1 to 500 characters. Control
 * characters (also line breaks and tabs, U+0085, U+2028 and U+2029) and a text that is not valid UTF-8 are refused
 * before trimming, like in ControlCharacters: a trim would drop some of them at the ends without a word. The ends are
 * trimmed of Unicode whitespace (`\s` and `\p{Z}`, so also NBSP), unlike `trim()` that knows only ASCII: nothing but
 * white space is no note.
 */
final class StaffNote
{
    public const int MAX_LENGTH = 500;
    private const string EDGES = '/^[\s\p{Z}]+|[\s\p{Z}]+$/Du';

    /**
     * The note without Unicode whitespace at its ends. A text that is not valid UTF-8 comes back as it is: the caller
     * refuses it with ControlCharacters::in() before.
     */
    public static function trim(string $note): string
    {
        return preg_replace(self::EDGES, '', $note) ?? $note;
    }

    /**
     * @throws InvalidOrderInput
     */
    public static function optional(?string $note): ?string
    {
        if (null === $note) {
            return null;
        }
        if (ControlCharacters::in($note)) {
            throw InvalidOrderInput::noteCharacters();
        }
        $trimmed = self::trim($note);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidOrderInput::noteTooLong();
        }

        return $trimmed;
    }

    /**
     * @throws InvalidOrderInput also when there is no note
     */
    public static function required(?string $note): string
    {
        return self::optional($note) ?? throw InvalidOrderInput::noteMissing();
    }
}
