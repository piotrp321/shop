<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use App\Catalog\Domain\Product\ImageNotFound;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\UnknownCategory;
use App\Catalog\Domain\Product\UnknownVariant;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * What a failed product write means for the response. The rules of the domain that name a place in the body (an
 * image without an original, a variant or a category that does not exist, a slug nobody can derive) become a 422 with a
 * violation at that field, like the violations of the body itself. An outage of the image storage is a 503. Every other
 * failure is left to the problem details subscriber (409 with their type, 404, 422 with a detail).
 */
final class ProductWriteFailures
{
    public static function translate(HandlerFailedException $failure, object $body): \Throwable
    {
        $wrapped = $failure->getWrappedExceptions();

        return self::forCause(1 === \count($wrapped) ? reset($wrapped) : null, $failure, $body);
    }

    /**
     * @param \Throwable|null $cause   the one exception the failed handler threw
     * @param \Throwable      $failure what to throw when the cause has no meaning of its own
     */
    public static function forCause(?\Throwable $cause, \Throwable $failure, object $body): \Throwable
    {
        $violation = match (true) {
            $cause instanceof ImageNotFound => ['images['.$cause->index.'].key', $cause->getMessage()],
            $cause instanceof UnknownVariant => ['variants['.$cause->index.'].variantId', $cause->getMessage()],
            $cause instanceof UnknownCategory => [null === $cause->index ? 'mainCategoryId' : 'additionalCategoryIds['.$cause->index.']', $cause->getMessage()],
            $cause instanceof SlugRequired => ['slug', $cause->getMessage()],
            default => null,
        };
        if (null !== $violation) {
            [$path, $message] = $violation;

            return self::unprocessable($body, $path, $message);
        }
        if ($cause instanceof ProductImageStorageUnavailable) {
            return new ServiceUnavailableHttpException(previous: $failure);
        }

        return $failure;
    }

    public static function unprocessable(object $body, string $path, string $message): UnprocessableEntityHttpException
    {
        return new UnprocessableEntityHttpException(previous: new ValidationFailedException($body, new ConstraintViolationList([
            new ConstraintViolation($message, null, [], $body, $path, null),
        ])));
    }
}
