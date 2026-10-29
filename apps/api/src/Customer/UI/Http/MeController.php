<?php

declare(strict_types=1);

namespace App\Customer\UI\Http;

use App\Customer\Application\Security\AuthenticatedCustomer;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController
{
    #[Route('/api/v1/me', name: 'customer_me', methods: ['GET'])]
    #[Security(name: 'shop')]
    #[OA\Get(
        operationId: 'getMe',
        summary: 'Current customer',
        description: 'The customer identified by the access token of the `shop` realm.',
        tags: ['Customer'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The customer.',
                content: new OA\JsonContent(ref: new Model(type: MeResponse::class)),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(#[CurrentUser] AuthenticatedCustomer $customer): JsonResponse
    {
        $response = new JsonResponse(MeResponse::of($customer));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
