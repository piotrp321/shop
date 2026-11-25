<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Domain\Stock\StockNote;
use App\Shared\Domain\Text;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The note of a correction and of a receipt as a field of a request: the characters of the rule of texts looked for in
 * the note as it came (the trim of the length check would drop some of them at the ends), with the one message of the
 * field.
 */
final class StockNoteRules
{
    public const string BAD_CHARACTERS = 'Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).';

    private function __construct()
    {
    }

    /**
     * A note with a character the rule of texts forbids is refused; a value that is no text is left to the constraint
     * of the type.
     */
    public static function validateCharacters(mixed $note, ExecutionContextInterface $context): void
    {
        if (\is_string($note) && Text::hasForbiddenCharacters($note, StockNote::ALLOWED)) {
            $context->buildViolation(self::BAD_CHARACTERS)->addViolation();
        }
    }

    /**
     * Whether the value is a text without a visible character, i.e. no note. A text with a forbidden character is not:
     * it has its own violation.
     */
    public static function isBlank(mixed $note): bool
    {
        return \is_string($note) && !Text::hasForbiddenCharacters($note, StockNote::ALLOWED) && '' === Text::normalize($note);
    }
}
