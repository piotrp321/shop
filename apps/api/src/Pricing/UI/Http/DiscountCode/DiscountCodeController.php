<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscountCodeController
{
    public function __construct(private DiscountCodeResponder $responder)
    {
    }

    #[Route('/api/v1/admin/discount-codes/{id}', name: 'pricing_discount_code', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getDiscountCode',
        summary: 'A discount code',
        description: 'A discount code with its status at the time of the server and the number of its active usages. Every staff '
            .'member may read it.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Discount code id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The discount code.', content: new OA\JsonContent(ref: new Model(type: DiscountCodeResponse::class))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such discount code, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(string $id): JsonResponse
    {
        return $this->responder->respond($id);
    }
}
