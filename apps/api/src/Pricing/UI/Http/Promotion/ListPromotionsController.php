<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\InvalidPromotionCursor;
use App\Pricing\Application\Query\ListPromotions;
use App\Pricing\Application\Query\PromotionList;
use App\Pricing\Domain\Promotion\PromotionStatus;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ListPromotionsController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/promotions', name: 'pricing_promotions', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'listPromotions',
        summary: 'Promotion list',
        description: 'A page of promotions, newest start first (then by id), with the status at the time of the server. Every staff '
            .'member may read it. Page with the opaque `next` cursor as `after`, with the same filter; the total is not counted.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Only the promotions in this status now.', schema: new OA\Schema(type: 'string', enum: PromotionListQuery::STATUSES)),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `next` cursor of the previous page.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: PromotionListQuery::MAX_CURSOR_LENGTH)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: ListPromotions::MAX_PER_PAGE, default: ListPromotions::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of the list. Possibly an empty one.', content: new OA\JsonContent(ref: new Model(type: PromotionListResponse::class))),
            new OA\Response(response: 400, description: 'Invalid query parameters or paging cursor (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] PromotionListQuery $query = new PromotionListQuery()): JsonResponse
    {
        $status = null === $query->status ? null : PromotionStatus::from($query->status);

        try {
            /** @var PromotionList $list */
            $list = $this->handle(new ListPromotions($status, $query->after, $query->perPage));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidPromotionCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation('The paging cursor is invalid.', null, [], $query, 'after', null)]));
            }

            throw $failure;
        }

        return PromotionResponder::private(new JsonResponse(PromotionListResponse::of($list)));
    }
}
