<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Application\Command\CreateDeliveryMethod;
use App\Shared\UI\Http\RequiredKeysBody;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final readonly class CreateDeliveryMethodController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private DeliveryMethodResponder $responder,
    ) {
    }

    #[Route('/api/v1/admin/delivery-methods', name: 'delivery_create_method', methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'createDeliveryMethod',
        summary: 'Create a delivery method',
        description: 'Administrators only. The name is unique among all methods (also the inactive ones) without regard to case; '
            .'at most 20 methods are active. A method is never deleted; it is disabled with `active` false.',
        tags: ['Delivery'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateDeliveryMethodRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'The created method (`Location` names it).', content: new OA\JsonContent(ref: new Model(type: DeliveryMethodResponse::class))),
            new OA\Response(response: 400, description: 'The body is not valid JSON, also a text that is not valid UTF-8 (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member is no administrator (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'Another method has the name (`delivery-method-name-taken`) or an active method would be the 21st (`delivery-method-limit-reached`); see `DeliveryConflictProblem`. Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/DeliveryConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A field is invalid, a key is missing or of a wrong type (problem details with `violations`, each at its field, with a Polish `title`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(DeliveryAttributes::MANAGE_DELIVERY_METHODS)]
    public function __invoke(#[RequiredKeysBody] CreateDeliveryMethodRequest $method): JsonResponse
    {
        // Validated by the payload mapping.
        \assert(\is_string($method->name) && \is_int($method->priceGross) && \is_bool($method->active) && \is_int($method->position));
        \assert((null === $method->description || \is_string($method->description)) && (null === $method->freeFromGross || \is_int($method->freeFromGross)));
        \assert(null === $method->trackingUrlTemplate || \is_string($method->trackingUrlTemplate));

        $id = Uuid::v7()->toRfc4122();
        $this->commandBus->dispatch(new CreateDeliveryMethod(
            $id,
            $method->name,
            $method->description,
            $method->priceGross,
            $method->freeFromGross,
            $method->active,
            $method->position,
            $method->trackingUrlTemplate,
        ));

        $response = $this->responder->respond($id, Response::HTTP_CREATED);
        $response->headers->set('Location', '/api/v1/admin/delivery-methods/'.$id);

        return $response;
    }
}
