<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http\Validation;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderLine;
use App\Ordering\UI\Http\PlaceGuestOrderRequest;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Checks the shape ExpectedLinesFormat describes and stops at the first violation. A JSON object decodes to an array
 * with keys, a JSON list to a list, so `{}` (an empty object, the same array as an empty list) is an element without
 * `variantId`. The domain (ExpectedLines) checks the same rules again.
 */
final class ExpectedLinesFormatValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ExpectedLinesFormat) {
            throw new UnexpectedTypeException($constraint, ExpectedLinesFormat::class);
        }
        if (null === $value) {
            return;
        }
        if (!\is_array($value) || !array_is_list($value) || [] === $value || \count($value) > Order::MAX_LINES) {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        $variants = [];
        foreach ($value as $index => $line) {
            $violation = self::violationOf($line);
            if (null !== $violation) {
                $this->context->buildViolation($constraint->message)->atPath(\sprintf('[%d]%s', $index, $violation))->addViolation();

                return;
            }
            \assert(\is_array($line) && \is_string($line['variantId']));
            $variant = strtolower($line['variantId']);
            if (isset($variants[$variant])) {
                $this->context->buildViolation($constraint->message)->addViolation();

                return;
            }
            $variants[$variant] = true;
        }
    }

    /**
     * @return string|null the path of the violation inside the element (`''` for the element itself), null for none
     */
    private static function violationOf(mixed $line): ?string
    {
        if (!\is_array($line) || ([] !== $line && array_is_list($line))) {
            return '';
        }
        $variantId = $line['variantId'] ?? null;
        if (!\is_string($variantId) || 1 !== preg_match(PlaceGuestOrderRequest::CART_ID_PATTERN, $variantId)) {
            return '.variantId';
        }
        $quantity = $line['quantity'] ?? null;
        if (!\is_int($quantity) || $quantity < 1 || $quantity > OrderLine::MAX_QUANTITY) {
            return '.quantity';
        }

        return null;
    }
}
