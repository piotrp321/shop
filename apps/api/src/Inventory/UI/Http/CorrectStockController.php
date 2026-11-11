<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Inventory\Application\Command\CorrectStock;
use App\Inventory\Application\Command\StockView;
use App\Shared\UI\Http\RequiredKeysBody;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CorrectStockController
{
    use HandleTrait;

    /** Attribute of StockVoter (Infrastructure): the controller only knows its name. */
    private const string MANAGE_STOCK = 'MANAGE_STOCK';

    /** A UUID in any letter case; anything else matches no route (404), like an unknown variant. */
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(#[Autowire(service: 'command.bus')] MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    #[Route('/api/v1/admin/variants/{variantId}/stock/corrections', name: 'inventory_correct_stock', requirements: ['variantId' => self::UUID], methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'correctStock',
        summary: 'Correct the stock of a product variant',
        description: 'Sets the physical stock to the counted units, with a reason (a note is required for `other`). '
            .'Administrators and the warehouse only. Send the `version` of the stock you saw (null for a variant without a stock '
            .'record): a stock changed since then is a 409 and nothing is overwritten, so repeating a correction that succeeded '
            .'is a 409 too. Counting what the stock has changes nothing: the version stays and no history entry is recorded. '
            .'The history entry names the staff member of the token.',
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'variantId', in: 'path', required: true, description: 'Variant id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The stock as saved, or as it was when nothing changed.',
                content: new OA\JsonContent(ref: new Model(type: StockStateResponse::class)),
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
                description: 'The staff member is neither an administrator nor in the warehouse (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'No such variant, or the id is not a UUID (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 409,
                description: 'The stock was changed since the given version, or created since the null version; or less was counted than is reserved, `detail` names the reserved units (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 415,
                description: 'The body is not JSON (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 422,
                description: 'The count is out of range, the reason unknown, the note too long or missing for `other`, or a key is missing or of a wrong type (problem details with `violations`).',
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
        string $variantId,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[RequiredKeysBody]
        CorrectStockRequest $correction,
    ): JsonResponse {
        // Validated by the payload mapping: integers (the version may be null) and strings (the note may be null).
        \assert(\is_int($correction->physical) && (null === $correction->version || \is_int($correction->version)));
        \assert(\is_string($correction->reason) && (null === $correction->note || \is_string($correction->note)));

        $stock = $this->handle(CorrectStock::byStaff(
            $variantId,
            $correction->physical,
            $correction->version,
            $correction->reason,
            $correction->note,
            $staffMember->id(),
            $staffMember->username(),
        ));
        \assert($stock instanceof StockView);

        $response = new JsonResponse(StockStateResponse::of($stock));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
