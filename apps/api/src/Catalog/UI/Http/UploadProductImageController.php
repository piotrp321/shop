<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Command\StoredProductImage;
use App\Catalog\Application\Command\StoreProductImage;
use App\Catalog\Application\Image\InvalidImageUpload;
use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UploadProductImageController
{
    use HandleTrait;

    /** Attribute of ProductVoter (Infrastructure): the controller only knows its name. */
    private const string MANAGE_PRODUCTS = 'MANAGE_PRODUCTS';

    public function __construct(#[Autowire(service: 'command.bus')] MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    #[Route('/api/v1/admin/product-images', name: 'catalog_upload_product_image', methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'uploadProductImage',
        summary: 'Upload the original of a product image',
        description: 'Stores one image file in the image storage and answers with its key and a preview. Administrators and marketing only. One file per request, in the `file` field of a `multipart/form-data` body; send several files one after another. '
            .'JPEG, PNG, WebP, AVIF and HEIC are accepted, decided by the content and not by the name, up to 64 MB and 50 megapixels. The key follows the content, so the same file always gives the same key and is stored once. '
            .'Name the key in `images[].key` of a product write; an original that no product names stays in the storage. The `detail` of a 422 is a Polish text for the staff member.',
        tags: ['Catalog'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
            type: 'object',
            required: ['file'],
            properties: [new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'The image file.')],
        ))),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The original is stored.',
                content: new OA\JsonContent(ref: new Model(type: ImageUploadResponse::class)),
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
                response: 422,
                description: 'No file was sent, the file is too large, of an unsupported type, of unreadable dimensions or of too high a resolution (problem details with a Polish `detail`). The request is too large when its `Content-Length` is above `post_max_size` (70 MB) or the file above `upload_max_filesize` (64 MB).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The image storage (Garage) is unavailable, or the keys of the token issuer cannot be fetched (problem details without detail). Nothing was stored; the upload can be repeated.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    #[IsGranted(self::MANAGE_PRODUCTS)]
    public function __invoke(Request $request): JsonResponse
    {
        // PHP drops the whole body of a request above post_max_size, so the file would be missing: the header tells why.
        $limit = ini_parse_quantity((string) \ini_get('post_max_size'));
        $length = $request->headers->get('Content-Length');
        if ($limit > 0 && null !== $length && ctype_digit($length) && (int) $length > $limit) {
            throw InvalidImageUpload::tooLarge();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw InvalidImageUpload::missingFile();
        }
        if (!$file->isValid()) {
            throw \UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError() ? InvalidImageUpload::tooLarge() : InvalidImageUpload::notReceived();
        }
        $contents = file_get_contents($file->getPathname());
        if (false === $contents) {
            throw InvalidImageUpload::notReceived();
        }

        try {
            /** @var StoredProductImage $image */
            $image = $this->handle(new StoreProductImage($contents));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; an outage of the image storage is a 503.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof ProductImageStorageUnavailable) {
                throw new ServiceUnavailableHttpException(previous: $failure);
            }

            throw $failure;
        }

        $response = new JsonResponse(ImageUploadResponse::of($image), Response::HTTP_CREATED);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
