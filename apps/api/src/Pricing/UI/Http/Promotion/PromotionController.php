<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PromotionController
{
    public function __construct(private PromotionResponder $responder)
    {
    }

    #[Route('/api/v1/admin/promotions/{id}', name: 'pricing_promotion', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getPromotion',
        summary: 'A promotion',
        description: 'A promotion with its variants (product, name and label from the catalog) and their catalog and promotional '
            .'prices. Every staff member may read it.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Promotion id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The promotion.', content: new OA\JsonContent(ref: new Model(type: PromotionResponse::class))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such promotion, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(string $id): JsonResponse
    {
        return $this->responder->respond($id);
    }
}
