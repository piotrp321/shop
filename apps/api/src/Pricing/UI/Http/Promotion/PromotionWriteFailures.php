<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Command\InvalidPromotionVariants;
use App\Pricing\Domain\Promotion\InvalidPromotion;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * What a failed promotion write means for the response. A rule of the promotion is shown at the field it names, a
 * refused variant at its place in `variantIds`, like the violations of the body itself (422). Every other failure is
 * left to the problem details subscriber (409 with their type, 404).
 */
final class PromotionWriteFailures
{
    /**
     * @param list<mixed> $variantIds the variant ids of the body, in its order
     */
    public static function translate(HandlerFailedException $failure, object $body, array $variantIds): \Throwable
    {
        $wrapped = $failure->getWrappedExceptions();
        $cause = 1 === \count($wrapped) ? reset($wrapped) : null;

        $violations = new ConstraintViolationList();
        if ($cause instanceof InvalidPromotion) {
            $violations->add(new ConstraintViolation($cause->getMessage(), null, [], $body, $cause->field, null));
        } elseif ($cause instanceof InvalidPromotionVariants) {
            foreach ($variantIds as $index => $variantId) {
                $reason = \is_string($variantId) ? $cause->reasonFor(strtolower($variantId)) : null;
                if (null !== $reason) {
                    $violations->add(new ConstraintViolation($reason, null, [], $body, 'variantIds['.$index.']', null));
                }
            }
        }

        if (0 === $violations->count()) {
            return $failure;
        }

        return new UnprocessableEntityHttpException(previous: new ValidationFailedException($body, $violations));
    }
}
