<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Command\DeletePromotion;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class DeletePromotionController
{
    /** A positive integer that fits a bigint. */
    private const string VERSION = '/^[1-9][0-9]{0,17}$/D';

    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    #[Route('/api/v1/admin/promotions/{id}', name: 'pricing_delete_promotion', methods: ['DELETE'])]
    #[Security(name: 'backoffice')]
    #[OA\Delete(
        operationId: 'deletePromotion',
        summary: 'Delete a planned promotion',
        description: 'Deletes a promotion that has not started, based on the `version` seen. Administrators and marketing only. '
            .'An active or ended promotion is kept for ever (409 `promotion-not-editable`); end an active one with a change of its end.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Promotion id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'version', in: 'query', required: true, description: 'The `version` of the promotion the deletion is based on.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 400, description: 'The version is missing or not a positive integer (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not change promotions (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such promotion, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The promotion changed since the given version (`about:blank`) or is not planned (`promotion-not-editable`); see `PricingConflictProblem`. Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PricingConflictProblem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(PromotionAttributes::MANAGE_PROMOTIONS)]
    public function __invoke(string $id, Request $request): Response
    {
        $version = $request->query->get('version');
        if (!\is_string($version) || 1 !== preg_match(self::VERSION, $version)) {
            throw new ValidationFailedException($request, new ConstraintViolationList([new ConstraintViolation('Wersja musi być dodatnią liczbą całkowitą.', null, [], $request, 'version', null)]));
        }

        $this->commandBus->dispatch(new DeletePromotion($id, (int) $version));

        return PromotionResponder::private(new JsonResponse(null, Response::HTTP_NO_CONTENT));
    }
}
