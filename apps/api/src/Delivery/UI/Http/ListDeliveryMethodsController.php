<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Application\Query\DeliveryMethodView;
use App\Delivery\Application\Query\ListDeliveryMethods;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListDeliveryMethodsController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/delivery-methods', name: 'delivery_methods', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'listDeliveryMethods',
        summary: 'The delivery methods',
        description: 'Every delivery method, without pages: the active ones (at most 20) by position, then by name; then the '
            .'inactive ones by name (a method is never deleted, only disabled). Administrators only.',
        tags: ['Delivery'],
        responses: [
            new OA\Response(response: 200, description: 'The methods.', content: new OA\JsonContent(ref: new Model(type: DeliveryMethodListResponse::class))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member is no administrator (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(DeliveryAttributes::MANAGE_DELIVERY_METHODS)]
    public function __invoke(): JsonResponse
    {
        /** @var list<DeliveryMethodView> $methods */
        $methods = $this->handle(new ListDeliveryMethods());

        return DeliveryMethodResponder::private(new JsonResponse(DeliveryMethodListResponse::of($methods)));
    }
}
