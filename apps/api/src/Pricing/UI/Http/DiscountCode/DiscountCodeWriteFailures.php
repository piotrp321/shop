<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * What a failed write of a code means for the response. A rule of the code is shown at the field it names, like the
 * violations of the body itself (422). Every other failure is left to the problem details subscriber (409 with their
 * type, 404).
 */
final class DiscountCodeWriteFailures
{
    public static function translate(HandlerFailedException $failure, object $body): \Throwable
    {
        $wrapped = $failure->getWrappedExceptions();
        $cause = 1 === \count($wrapped) ? reset($wrapped) : null;
        if (!$cause instanceof InvalidDiscountCode) {
            return $failure;
        }

        return new UnprocessableEntityHttpException(previous: new ValidationFailedException($body, new ConstraintViolationList([
            new ConstraintViolation($cause->getMessage(), null, [], $body, $cause->field, null),
        ])));
    }
}
