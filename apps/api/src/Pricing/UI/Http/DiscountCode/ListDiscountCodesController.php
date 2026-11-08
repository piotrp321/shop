<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Application\Query\DiscountCodeList;
use App\Pricing\Application\Query\InvalidDiscountCodeCursor;
use App\Pricing\Application\Query\ListDiscountCodes;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\UI\Http\Promotion\PromotionResponder;
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

final class ListDiscountCodesController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/discount-codes', name: 'pricing_discount_codes', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'listDiscountCodes',
        summary: 'Discount code list',
        description: 'A page of the discount codes, newest first (then by id), with the status at the time of the server and the '
            .'number of active usages. Every staff member may read it. Page with the opaque `next` cursor as `after`, with the same '
            .'filters; the total is not counted.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Only the codes in this status now.', schema: new OA\Schema(type: 'string', enum: DiscountCodeListQuery::STATUSES)),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'The beginning of the code, in any case; trimmed, 1 to 32 characters. One with a character no code has gives an empty page.', schema: new OA\Schema(type: 'string', maxLength: DiscountCodeValue::MAX_LENGTH, minLength: 1)),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `next` cursor of the previous page.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: DiscountCodeListQuery::MAX_CURSOR_LENGTH)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: ListDiscountCodes::MAX_PER_PAGE, default: ListDiscountCodes::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of the list. Possibly an empty one.', content: new OA\JsonContent(ref: new Model(type: DiscountCodeListResponse::class))),
            new OA\Response(response: 400, description: 'Invalid query parameters or paging cursor (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] DiscountCodeListQuery $query = new DiscountCodeListQuery()): JsonResponse
    {
        $status = null === $query->status ? null : DiscountCodeStatus::from($query->status);

        try {
            /** @var DiscountCodeList $list */
            $list = $this->handle(new ListDiscountCodes($status, $query->q, $query->after, $query->perPage));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidDiscountCodeCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation(DiscountCodeListQuery::CURSOR_MESSAGE, null, [], $query, 'after', null)]));
            }

            throw $failure;
        }

        return PromotionResponder::private(new JsonResponse(DiscountCodeListResponse::of($list)));
    }
}
