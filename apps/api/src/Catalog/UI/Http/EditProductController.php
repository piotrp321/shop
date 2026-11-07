<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Catalog\Application\Command\EditProduct;
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

final class EditProductController
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

    #[Route('/api/v1/admin/products/{id}', name: 'catalog_edit_product', requirements: ['id' => self::UUID], methods: ['PUT'])]
    #[Security(name: 'backoffice')]
    #[OA\Put(
        operationId: 'editProduct',
        summary: 'Edit a product',
        description: 'Replaces the whole data of the product (a form save). Administrators and marketing only. Send the `version` of the view the edit is based on: another current version is a 409 and nothing is overwritten. '
            .'A successful save raises the version even when the data did not change. A row of `variants` with the `variantId` of the product keeps that variant, a row with `null` is a new one; a variant missing from the rows is removed. '
            .'An empty `slug` derives it from the name, or keeps the slug once the product was published. The product records the staff member of the token and the time of the change.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Product id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateProductRequest::class))),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The product as saved, with its new version.',
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
                description: 'The `type` names the cause: product-modified (the version is not the current one), slug-taken, slug-locked (the slug of a product that was published cannot change), product-not-publishable (the last image of a published product that had images cannot be removed), variant-locked (after the first publication an axis or the meaning of a variant cannot change), variant-in-use (a removed variant has stock or reservations).',
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
        string $id,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        UpdateProductRequest $product,
    ): JsonResponse {
        // Validated by the payload mapping (ValidProductWrite): a positive integer.
        \assert(\is_int($product->version));
        $data = $product->data();

        try {
            $this->commandBus->dispatch(new EditProduct(
                $id,
                $product->version,
                $staffMember->id(),
                $data->name,
                $data->slug,
                $data->shortDescription,
                $data->mainCategoryId,
                $data->additionalCategoryIds,
                $data->taxClass,
                $data->images,
                $data->variantAxes,
                $data->variants,
            ));
        } catch (HandlerFailedException $failure) {
            throw ProductWriteFailures::translate($failure, $product);
        }

        return $this->responder->respond($id);
    }
}
