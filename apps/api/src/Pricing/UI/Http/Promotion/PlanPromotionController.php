<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Pricing\Application\Command\PlanPromotion;
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
use Symfony\Component\Uid\Uuid;

final readonly class PlanPromotionController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private PromotionResponder $responder,
    ) {
    }

    #[Route('/api/v1/admin/promotions', name: 'pricing_plan_promotion', methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'planPromotion',
        summary: 'Plan a promotion',
        description: 'Plans a percent off on a list of variants for a period. Administrators and marketing only. A variant is in at '
            .'most one promotion at any moment: a period overlapping another promotion with a common variant is a 409 that lists '
            .'the variants. Every variant must exist in the catalog and have a current price. The promotion names the staff '
            .'member of the token.',
        tags: ['Pricing'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PromotionRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'The planned promotion (`Location` names it).', content: new OA\JsonContent(ref: new Model(type: PromotionResponse::class))),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not change promotions (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'Variants are in another promotion of an overlapping period (`promotion-overlap` with `conflictingVariantIds`, see `PricingConflictProblem`). Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PricingConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A field is invalid, a key is missing or of a wrong type, or a variant does not exist or has no current price (problem details with `violations`, each at the path of its field, a variant at `variantIds[<index>]`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(PromotionAttributes::MANAGE_PROMOTIONS)]
    public function __invoke(
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true],
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        PromotionRequest $promotion,
    ): JsonResponse {
        // Validated by the payload mapping.
        \assert(\is_string($promotion->name) && \is_int($promotion->percentOff) && \is_array($promotion->variantIds));
        /** @var list<string> $variantIds */
        $variantIds = $promotion->variantIds;
        $id = Uuid::v7()->toRfc4122();

        try {
            $this->commandBus->dispatch(PlanPromotion::byStaff(
                $id,
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

        $response = $this->responder->respond($id, Response::HTTP_CREATED);
        $response->headers->set('Location', '/api/v1/admin/promotions/'.$id);

        return $response;
    }
}
