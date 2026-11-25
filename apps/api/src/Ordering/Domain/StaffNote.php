<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Text;

/**
 * The rules of the note of a staff member to a change of an order: one line, a text by the rule of texts (Text: no
 * forbidden character, so no line break or tab either, looked for in the note as it came; the ends trimmed of white
 * space and invisible characters), 1 to 500 characters once trimmed. A note without a visible character is no note.
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
        if (Text::hasForbiddenCharacters($note)) {
            throw InvalidOrderInput::noteCharacters();
        }
        $trimmed = Text::normalize($note);
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
