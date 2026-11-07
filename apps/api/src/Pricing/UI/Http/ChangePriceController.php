<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Pricing\Application\Command\SetVariantPrice;
use App\Pricing\Application\Query\ListedPrice;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

final class ChangePriceController
{
    use HandleTrait;

    /** Attribute of PriceVoter (Infrastructure): the controller only knows its name. */
    private const string CHANGE_PRICE = 'CHANGE_PRICE';

    /** A UUID in any letter case; anything else matches no route (404), like an unknown variant. */
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(#[Autowire(service: 'command.bus')] MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    #[Route('/api/v1/admin/variants/{variantId}/price', name: 'pricing_change_price', requirements: ['variantId' => self::UUID], methods: ['PUT'])]
    #[Security(name: 'backoffice')]
    #[OA\Put(
        operationId: 'changePrice',
        summary: 'Change the price of a product variant',
        description: 'Sets the gross price of one variant; the API computes the net and the VAT at the current rate of the price\'s '
            .'tax class (the class of the variant\'s product for a first price). Administrators only. Send the `version` of the '
            .'price you saw (null for a variant without a price): a price changed since then is a 409 and nothing is overwritten. '
            .'The same gross at the same rate changes nothing: the version stays and no history entry is recorded. '
            .'The history entry names the staff member of the token. The price of a variant in an active promotion does not change.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'variantId', in: 'path', required: true, description: 'Variant id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The price as saved, or as it was when nothing changed.',
                content: new OA\JsonContent(ref: new Model(type: ListedPriceResponse::class)),
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
                description: 'The staff member is not an administrator (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'No such variant, or the id is not a UUID (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 409,
                description: 'The variant is in an active promotion (`price-locked-by-promotion`, whatever the version: end the promotion '
                    .'first), or the price was changed since the given version, or created since the null version (`about:blank`); see '
                    .'`PricingConflictProblem`. Nothing is changed.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PricingConflictProblem')),
            ),
            new OA\Response(
                response: 415,
                description: 'The body is not JSON (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 422,
                description: 'The gross is out of range or a key is missing or of a wrong type (problem details with `violations`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    #[IsGranted(self::CHANGE_PRICE)]
    public function __invoke(
        string $variantId,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true],
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        ChangePriceRequest $change,
    ): JsonResponse {
        // Validated by the payload mapping: an integer, and an integer or null.
        \assert(\is_int($change->gross) && (null === $change->version || \is_int($change->version)));

        /** @var ListedPrice $price */
        $price = $this->handle(SetVariantPrice::byStaff($variantId, $change->gross, $change->version, $staffMember->id(), $staffMember->username()));

        $response = new JsonResponse(ListedPriceResponse::of($price));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
