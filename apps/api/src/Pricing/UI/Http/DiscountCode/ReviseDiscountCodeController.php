<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Pricing\Application\Command\ReviseDiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\UI\Http\Promotion\Rfc3339;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ReviseDiscountCodeController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private DiscountCodeResponder $responder,
        private DiscountCodeBodyReader $reader,
    ) {
    }

    #[Route('/api/v1/admin/discount-codes/{id}', name: 'pricing_revise_discount_code', methods: ['PUT'])]
    #[Security(name: 'backoffice')]
    #[OA\Put(
        operationId: 'reviseDiscountCode',
        summary: 'Change a discount code',
        description: 'Sends the whole code as it should be (its text never changes), based on the `version` seen. Administrators '
            .'and marketing only. Until its start a code changes everything. Once started (also disabled or expired) only its end, '
            .'`enabled` and an existing limit change: another kind, value, minimum or start, or a limit for a code without one, is a '
            .'409 `discount-code-not-editable`. A limit is never below the active usages, and a changed end is after the time of the '
            .'server (422; stopping a code at once is disabling it). An unknown code is a 404 before the body is read.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Discount code id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReviseDiscountCodeRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'The code as saved.', content: new OA\JsonContent(ref: new Model(type: DiscountCodeResponse::class))),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not change discount codes (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such discount code, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The code changed since the given version (`about:blank`) or the change is not allowed once the code started (`discount-code-not-editable`); see `PricingConflictProblem`. Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PricingConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A field is invalid, a key is missing or of a wrong type, the limit is below the active usages or a changed end is not after now (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(DiscountCodeAttributes::MANAGE_DISCOUNT_CODES)]
    public function __invoke(
        string $id,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        Request $request,
    ): JsonResponse {
        // An unknown code is a 404 whatever the body; the command looks it up again under its lock.
        $this->responder->find($id);
        $body = $this->reader->read($request, ReviseDiscountCodeRequest::class);
        // Validated by the reader.
        \assert(\is_string($body->kind) && \is_int($body->value) && \is_bool($body->enabled) && \is_int($body->version));
        \assert((null === $body->minimumOrderGross || \is_int($body->minimumOrderGross)) && (null === $body->usageLimit || \is_int($body->usageLimit)));

        try {
            $this->commandBus->dispatch(new ReviseDiscountCode(
                $id,
                $body->version,
                DiscountKind::from($body->kind),
                $body->value,
                $body->minimumOrderGross,
                Rfc3339::parse($body->startsAt) ?? throw new \LogicException('Validated by the reader.'),
                Rfc3339::parse($body->endsAt),
                $body->usageLimit,
                $body->enabled,
                $staffMember->id(),
                $staffMember->username(),
            ));
        } catch (HandlerFailedException $failure) {
            throw DiscountCodeWriteFailures::translate($failure, $body);
        }

        return $this->responder->respond($id);
    }
}
