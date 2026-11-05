<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http\Validation;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Builds the `Address` the Mailer would build, so the check is the Mailer's own and cannot drift from it. Not a string
 * or an empty one is left to the other constraints of the field.
 */
final class MailerAcceptsEmailValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MailerAcceptsEmail) {
            throw new UnexpectedTypeException($constraint, MailerAcceptsEmail::class);
        }
        if (!\is_string($value) || '' === trim($value)) {
            return;
        }

        try {
            new Address(trim($value));
        } catch (RfcComplianceException|InvalidArgumentException) {
            // Only a refused address: a missing validator library (a LogicException) must not turn into a 422.
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
