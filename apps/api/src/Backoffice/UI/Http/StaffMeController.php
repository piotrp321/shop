<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class StaffMeController
{
    #[Route('/api/v1/admin/me', name: 'backoffice_me', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getStaffMe',
        summary: 'Current staff member',
        description: 'The staff member identified by the access token of the `backoffice` realm, with any staff role.',
        tags: ['Staff'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The staff member.',
                content: new OA\JsonContent(ref: new Model(type: StaffMeResponse::class)),
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
    public function __invoke(#[CurrentUser] AuthenticatedStaffMember $staffMember): JsonResponse
    {
        $response = new JsonResponse(StaffMeResponse::of($staffMember));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
