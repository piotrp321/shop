<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Application\Command\MoveCategory;
use App\Shared\UI\Http\RequiredKeysBody;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class MoveCategoryController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private CategoryResponder $responder,
    ) {
    }

    #[Route('/api/v1/admin/categories/{id}/move', name: 'catalog_move_category', methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'moveCategory',
        summary: 'Move a category or change its place among its siblings',
        description: 'Moves the category with its subcategories under a parent (null: among the roots) at a position among the children of that '
            .'parent, based on the `version` seen; the current parent changes only the order. Administrators and marketing only. The children '
            .'of the parent are numbered in their current order first, so the others keep their relative order. A new parent changes the '
            .'addresses of the subtree; the old ones redirect to the current ones.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Category id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MoveCategoryRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'The category as saved.', content: new OA\JsonContent(ref: new Model(type: BackofficeCategoryResponse::class))),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not change the category tree (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such category, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The category changed since the given version (`category-modified`), the parent is the category or one of its descendants (`category-cycle`), the subtree would end below the fourth level (`category-too-deep`) or a child of the parent has the slug (`category-slug-taken`); see `CategoryConflictProblem`. Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/CategoryConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A field is invalid, a key is missing or of a wrong type, or the parent does not exist (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(CategoryAttributes::MANAGE_CATEGORIES)]
    public function __invoke(string $id, #[RequiredKeysBody] MoveCategoryRequest $move): JsonResponse
    {
        // Validated by the payload mapping.
        \assert((null === $move->parentId || \is_string($move->parentId)) && \is_int($move->position) && \is_int($move->version));

        try {
            $this->commandBus->dispatch(new MoveCategory($id, $move->version, $move->parentId, $move->position));
        } catch (HandlerFailedException $failure) {
            throw CategoryResponder::translate($failure, $move);
        }

        return $this->responder->respond($id);
    }
}
