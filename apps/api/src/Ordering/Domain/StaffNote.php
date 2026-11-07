<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The rules of the note of a staff member to a change of an order: one line, trimmed, 1 to 500 characters. Control
 * characters (also line breaks and tabs) are refused before trimming, like in ControlCharacters: a trim would drop
 * some of them at the ends without a word. Nothing but spaces is no note.
 */
final class StaffNote
{
    public const int MAX_LENGTH = 500;

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
        $trimmed = trim($note);
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
