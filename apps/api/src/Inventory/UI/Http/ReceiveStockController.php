<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Application\Command\ReceiveStockResult;
use App\Inventory\Domain\Stock\InvalidReceiptKey;
use App\Inventory\Domain\Stock\ReceiptKey;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ReceiveStockController
{
    use HandleTrait;

    /** Attribute of StockVoter (Infrastructure): the controller only knows its name. */
    private const string MANAGE_STOCK = 'MANAGE_STOCK';

    /** A UUID in any letter case; anything else matches no route (404), like an unknown product. */
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private const string IDEMPOTENCY_KEY = 'Idempotency-Key';

    public function __construct(#[Autowire(service: 'command.bus')] MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    #[Route('/api/v1/admin/products/{productId}/stock/receipts', name: 'inventory_receive_stock', requirements: ['productId' => self::UUID], methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'receiveStock',
        summary: 'Receive stock of a product',
        description: 'Adds delivered units to the physical stock. Administrators and the warehouse only. The `Idempotency-Key` '
            .'identifies the receipt: sending it again with the same product, quantity and note adds nothing and answers 200 with '
            .'the current stock, so a receipt can be retried safely; the same key with other content or another product is a 409. '
            .'The history entry names the staff member of the token.',
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'productId', in: 'path', required: true, description: 'Product id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Key of the receipt, chosen by the client (e.g. a UUID made when the dialog opens): 1 to 100 characters, letters, digits, ".", "_", ":" or "-".', schema: new OA\Schema(type: 'string', maxLength: ReceiptKey::MAX_LENGTH, minLength: 1, pattern: '^[A-Za-z0-9._:-]{1,100}$')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Received: the stock after the receipt.',
                content: new OA\JsonContent(ref: new Model(type: StockStateResponse::class)),
            ),
            new OA\Response(
                response: 200,
                description: 'The receipt was received before with this key and the same content: nothing changed, the current stock.',
                content: new OA\JsonContent(ref: new Model(type: StockStateResponse::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'The `Idempotency-Key` header is missing or malformed (problem details with `violations`), or the body is not valid JSON (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 403,
                description: 'The staff member is neither an administrator nor in the warehouse (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'No such product, or the id is not a UUID (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 409,
                description: 'The key was used for a receipt with other content or of another product (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 415,
                description: 'The body is not JSON (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 422,
                description: 'The quantity or the note is out of range, a key is missing or of a wrong type (problem details with `violations`), or the physical stock would exceed 1 000 000 (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    #[IsGranted(self::MANAGE_STOCK)]
    public function __invoke(
        string $productId,
        Request $request,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true],
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        ReceiveStockRequest $receipt,
    ): JsonResponse {
        // Validated by the payload mapping: an integer, and a string or null.
        \assert(\is_int($receipt->quantity) && (null === $receipt->note || \is_string($receipt->note)));
        $key = self::idempotencyKey($request);

        $result = $this->handle(ReceiveStock::byStaff($productId, $receipt->quantity, $key, $receipt->note, $staffMember->id(), $staffMember->username()));
        \assert($result instanceof ReceiveStockResult);

        $response = new JsonResponse(StockStateResponse::of($result->stock), $result->repeated ? Response::HTTP_OK : Response::HTTP_CREATED);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * A missing or malformed key is a 400 with a violation of the header, like a bad query parameter.
     */
    private static function idempotencyKey(Request $request): string
    {
        $key = $request->headers->get(self::IDEMPOTENCY_KEY);
        if (null === $key) {
            throw self::badKey($key, \sprintf('The %s header is required.', self::IDEMPOTENCY_KEY));
        }
        try {
            return ReceiptKey::fromString($key)->toString();
        } catch (InvalidReceiptKey $invalid) {
            throw self::badKey($key, $invalid->getMessage());
        }
    }

    private static function badKey(?string $key, string $message): BadRequestHttpException
    {
        $violations = new ConstraintViolationList([new ConstraintViolation($message, $message, [], $key, self::IDEMPOTENCY_KEY, $key)]);

        return new BadRequestHttpException($message, new ValidationFailedException($key, $violations));
    }
}
