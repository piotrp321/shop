<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Pricing\Application\Command\RevisePromotion;
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
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

final readonly class RevisePromotionController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private PromotionReader $reader,
    ) {
    }

    #[Route('/api/v1/admin/promotions/{id}', name: 'pricing_revise_promotion', methods: ['PUT'])]
    #[Security(name: 'backoffice')]
    #[OA\Put(
        operationId: 'revisePromotion',
        summary: 'Change a promotion',
        description: 'Sends the whole promotion as it should be, based on the `version` seen. Administrators and marketing only. '
            .'A planned promotion changes everything. An active one changes only its name and its end: any other value different '
            .'from the stored one is a 409 `promotion-not-editable`, and an end at or before the time of the server ends it at once '
            .'(the next whole second; "end now" sends the time of the browser). An ended promotion changes no more. A period '
            .'overlapping another promotion with a common variant is a 409 `promotion-overlap`; a variant the promotion gains must '
            .'exist in the catalog and have a current price.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Promotion id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RevisePromotionRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'The promotion as saved.', content: new OA\JsonContent(ref: new Model(type: PromotionResponse::class))),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not change promotions (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such promotion, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The promotion changed since the given version (`about:blank`), its status does not allow the change (`promotion-not-editable`), or variants are in another promotion of an overlapping period (`promotion-overlap`); see `PricingConflictProblem`. Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PricingConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A field is invalid, a key is missing or of a wrong type, or an added variant does not exist or has no current price (problem details with `violations`, a variant at `variantIds[<index>]`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(PromotionAttributes::MANAGE_PROMOTIONS)]
    public function __invoke(
        string $id,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true],
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        RevisePromotionRequest $promotion,
    ): JsonResponse {
        // Validated by the payload mapping.
        \assert(\is_string($promotion->name) && \is_int($promotion->percentOff) && \is_array($promotion->variantIds) && \is_int($promotion->version));
        /** @var list<string> $variantIds */
        $variantIds = $promotion->variantIds;

        try {
            $this->commandBus->dispatch(new RevisePromotion(
                $id,
                $promotion->version,
                $promotion->name,
                $promotion->percentOff,
                Rfc3339::parse($promotion->startsAt),
                Rfc3339::parse($promotion->endsAt) ?? throw new \LogicException('Validated by the payload mapping.'),
                $variantIds,
                $staffMember->id(),
                $staffMember->username(),
            ));
        } catch (HandlerFailedException $failure) {
            throw PromotionWriteFailures::translate($failure, $promotion, $variantIds);
        }

        return $this->reader->respond($id);
    }
}
