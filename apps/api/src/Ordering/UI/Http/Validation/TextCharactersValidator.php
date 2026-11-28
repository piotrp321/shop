<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http\Validation;

use App\Shared\Domain\Text;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TextCharactersValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TextCharacters) {
            throw new UnexpectedTypeException($constraint, TextCharacters::class);
        }
        if (\is_string($value) && Text::hasForbiddenCharacters($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
