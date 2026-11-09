<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Pricing\Application\Command\CreateDiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\UI\Http\Promotion\Rfc3339;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final readonly class CreateDiscountCodeController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private DiscountCodeResponder $responder,
        private DiscountCodeBodyReader $reader,
    ) {
    }

    #[Route('/api/v1/admin/discount-codes', name: 'pricing_create_discount_code', methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'createDiscountCode',
        summary: 'Create a discount code',
        description: 'Creates a percent or an amount code with its period, minimum order and usage limit. Administrators and '
            .'marketing only. The text is unique among all codes for ever, without regard to case. The code names the staff '
            .'member of the token.',
        tags: ['Pricing'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateDiscountCodeRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'The created code (`Location` names it).', content: new OA\JsonContent(ref: new Model(type: DiscountCodeResponse::class))),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not change discount codes (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'Another code has the same text (`discount-code-taken`, see `PricingConflictProblem`). Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PricingConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A field is invalid, a key is missing or of a wrong type (problem details with `violations`, each at its field, with a Polish `title`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(DiscountCodeAttributes::MANAGE_DISCOUNT_CODES)]
    public function __invoke(
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        Request $request,
    ): JsonResponse {
        $body = $this->reader->read($request, CreateDiscountCodeRequest::class);
        // Validated by the reader.
        \assert(\is_string($body->code) && \is_string($body->kind) && \is_int($body->value) && \is_bool($body->enabled));
        \assert((null === $body->minimumOrderGross || \is_int($body->minimumOrderGross)) && (null === $body->usageLimit || \is_int($body->usageLimit)));
        $id = Uuid::v7()->toRfc4122();

        try {
            $this->commandBus->dispatch(new CreateDiscountCode(
                $id,
                $body->code,
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

        $response = $this->responder->respond($id, Response::HTTP_CREATED);
        $response->headers->set('Location', '/api/v1/admin/discount-codes/'.$id);

        return $response;
    }
}
