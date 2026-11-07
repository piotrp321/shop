<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Catalog\Application\Command\PublishProduct;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PublishProductController
{
    /** Attribute of ProductVoter (Infrastructure): the controller only knows its name. */
    private const string MANAGE_PRODUCTS = 'MANAGE_PRODUCTS';

    /** A UUID in any letter case; anything else matches no route (404), like an unknown product. */
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        #[Autowire(service: 'command.bus')] private readonly MessageBusInterface $commandBus,
        private readonly ProductViewResponder $responder,
    ) {
    }

    #[Route('/api/v1/admin/products/{id}/publish', name: 'catalog_publish_product', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'publishProduct',
        summary: 'Publish a product',
        description: 'Makes a draft visible in the store; a draft needs at least one image. The first publication fixes the slug for good. Administrators and marketing only. Send the `version` of the view the action is based on: another current version is a 409 even when the product is already published. Publishing a published product changes nothing (200, same version).',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Product id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PublicationRequest::class))),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The product after the action, or unchanged (with the same version) when it already was in the wanted status.',
                content: new OA\JsonContent(ref: new Model(type: ProductEditViewResponse::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'The body is not valid JSON (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 403,
                description: 'The staff member is neither an administrator nor in marketing (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'No such product, or the id is not a UUID (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 409,
                description: 'The `type` names the cause: product-modified (the version is not the current one) or product-not-publishable (a draft without images).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/ProductConflictProblem')),
            ),
            new OA\Response(
                response: 415,
                description: 'The body is not JSON (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 422,
                description: 'The `version` is missing or not a positive integer (problem details with `violations`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    #[IsGranted(self::MANAGE_PRODUCTS)]
    public function __invoke(
        string $id,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        PublicationRequest $publication,
    ): JsonResponse {
        // Validated by the payload mapping: a positive integer.
        \assert(\is_int($publication->version));

        try {
            $this->commandBus->dispatch(new PublishProduct($id, $publication->version, $staffMember->id()));
        } catch (HandlerFailedException $failure) {
            throw ProductWriteFailures::translate($failure, $publication);
        }

        return $this->responder->respond($id);
    }
}
