<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Application\Command\DeleteCategory;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class DeleteCategoryController
{
    /** A positive integer that fits a 32-bit column. */
    private const string VERSION = '/^[1-9][0-9]{0,8}$/D';

    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    #[Route('/api/v1/admin/categories/{id}', name: 'catalog_delete_category', methods: ['DELETE'])]
    #[Security(name: 'backoffice')]
    #[OA\Delete(
        operationId: 'deleteCategory',
        summary: 'Delete an empty category',
        description: 'Deletes a category without subcategories and without products (main or additional, drafts too), based on the `version` seen. '
            .'Administrators and marketing only. Its old addresses stop redirecting.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Category id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'version', in: 'query', required: true, description: 'The `version` of the category the deletion is based on.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 400, description: 'The version is missing or not a positive integer (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not change the category tree (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such category, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The category changed since the given version (`category-modified`) or has subcategories or products (`category-not-empty`, with `subcategoryCount` and `productCount`); see `CategoryConflictProblem`. Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/CategoryConflictProblem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(CategoryAttributes::MANAGE_CATEGORIES)]
    public function __invoke(string $id, Request $request): Response
    {
        // `all()`, not `get()`: a bracketed key (`version[]=1`) must be a violation, not an exception.
        $version = $request->query->all()['version'] ?? null;
        if (!\is_string($version) || 1 !== preg_match(self::VERSION, $version)) {
            throw new ValidationFailedException($request, new ConstraintViolationList([new ConstraintViolation(CategoryBodyRules::VERSION, null, [], $request, 'version', null)]));
        }

        $this->commandBus->dispatch(new DeleteCategory($id, (int) $version));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
