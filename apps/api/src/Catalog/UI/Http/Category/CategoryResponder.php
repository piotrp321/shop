<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Application\Query\BackofficeCategoryView;
use App\Catalog\Application\Query\GetBackofficeCategory;
use App\Catalog\Domain\Category\ParentCategoryNotFound;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The response of a category write (the category read after the write) and what a failed write means: a parent that
 * does not exist is a 422 at `parentId`, like the violations of the body; every other failure is left to the problem
 * details subscriber (409 with its type, 404).
 */
final class CategoryResponder
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function respond(string $id, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        /** @var BackofficeCategoryView $category */
        $category = $this->handle(new GetBackofficeCategory($id));

        return new JsonResponse(BackofficeCategoryResponse::of($category), $status);
    }

    public static function translate(HandlerFailedException $failure, object $body): \Throwable
    {
        $wrapped = $failure->getWrappedExceptions();
        $cause = 1 === \count($wrapped) ? reset($wrapped) : null;
        if ($cause instanceof ParentCategoryNotFound) {
            return new UnprocessableEntityHttpException(previous: new ValidationFailedException($body, new ConstraintViolationList([
                new ConstraintViolation($cause->getMessage(), null, [], $body, 'parentId', null),
            ])));
        }

        return $failure;
    }
}
