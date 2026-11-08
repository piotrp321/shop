<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\UnknownVariant;
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
use Symfony\Component\Uid\Uuid;

final class CreateProductController
{
    /** Attribute of ProductVoter (Infrastructure): the controller only knows its name. */
    private const string MANAGE_PRODUCTS = 'MANAGE_PRODUCTS';

    public function __construct(
        #[Autowire(service: 'command.bus')] private readonly MessageBusInterface $commandBus,
        private readonly ProductViewResponder $responder,
    ) {
    }

    #[Route('/api/v1/admin/products', name: 'catalog_create_product', methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'createProduct',
        summary: 'Create a product',
        description: 'Creates a product as a draft (it is not in the store until it is published). Administrators and marketing only. The API gives the product and its new variants their ids and, for an empty `slug`, derives it from the name. '
            .'A `variantId` in this body names no variant of the new product, so it is a 422. The product records the staff member of the token and the time of the change.',
        tags: ['Catalog'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateProductRequest::class))),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The created product.',
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
                response: 409,
                description: 'The slug belongs to another product (`type` slug-taken).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/ProductConflictProblem')),
            ),
            new OA\Response(
                response: 415,
                description: 'The body is not JSON (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 422,
                description: 'A field is invalid, a category or a variant does not exist, an original of an image is not stored, or no slug can be derived from the name (problem details with `violations`, each at the path of its field).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The image storage (Garage) is unavailable, or the keys of the token issuer cannot be fetched (problem details without detail). Nothing was saved; the request can be repeated.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    #[IsGranted(self::MANAGE_PRODUCTS)]
    public function __invoke(
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        CreateProductRequest $product,
    ): JsonResponse {
        $data = $product->data();

        $variants = [];
        foreach ($data->variants as $index => $row) {
            // A new product has no variants to keep.
            if (null !== $row['variantId']) {
                throw ProductWriteFailures::unprocessable($product, \sprintf('variants[%d].variantId', $index), new UnknownVariant($index)->getMessage());
            }
            $variants[] = ['id' => Uuid::v7()->toRfc4122(), 'values' => $row['values']];
        }

        $id = Uuid::v7()->toRfc4122();
        try {
            $this->commandBus->dispatch(new CreateProduct(
                $id,
                $data->name,
                $data->slug,
                $data->shortDescription,
                $data->mainCategoryId,
                $data->additionalCategoryIds,
                $data->taxClass,
                $data->images,
                $data->variantAxes,
                $variants,
                ProductStatus::Draft,
                $staffMember->id(),
            ));
        } catch (HandlerFailedException $failure) {
            throw ProductWriteFailures::translate($failure, $product);
        }

        return $this->responder->respond($id, Response::HTTP_CREATED);
    }
}
