<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class DeliveryMethodController
{
    public function __construct(private DeliveryMethodResponder $responder)
    {
    }

    #[Route('/api/v1/admin/delivery-methods/{id}', name: 'delivery_method', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getDeliveryMethod',
        summary: 'A delivery method',
        description: 'Administrators only.',
        tags: ['Delivery'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Delivery method id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The method.', content: new OA\JsonContent(ref: new Model(type: DeliveryMethodResponse::class))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member is no administrator (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such method, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(DeliveryAttributes::MANAGE_DELIVERY_METHODS)]
    public function __invoke(string $id): JsonResponse
    {
        return $this->responder->respond($id);
    }
}
