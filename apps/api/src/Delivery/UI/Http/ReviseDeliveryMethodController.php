<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Application\Command\ReviseDeliveryMethod;
use App\Shared\UI\Http\RequiredKeysBody;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Changes a delivery method. The checks run in this order: the token (401), the role (403), the method (404, before
 * the body, #[ExistingDeliveryMethod]), the body (400, 415, 422), then the version, the limit and the name (409).
 */
final readonly class ReviseDeliveryMethodController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private DeliveryMethodResponder $responder,
    ) {
    }

    #[Route('/api/v1/admin/delivery-methods/{id}', name: 'delivery_revise_method', methods: ['PUT'])]
    #[Security(name: 'backoffice')]
    #[OA\Put(
        operationId: 'reviseDeliveryMethod',
        summary: 'Change a delivery method',
        description: 'Sends the whole method as it should be, based on the `version` seen. Administrators only. Activating a '
            .'method when 20 are active is a 409; a change that does not activate the method is never refused for the limit. '
            .'Placed orders keep their copy of the method. An unknown method is a 404 before the body is read.',
        tags: ['Delivery'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Delivery method id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReviseDeliveryMethodRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'The method as saved.', content: new OA\JsonContent(ref: new Model(type: DeliveryMethodResponse::class))),
            new OA\Response(response: 400, description: 'The body is not valid JSON, also a text that is not valid UTF-8 (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member is no administrator (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such method, also an id that is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The method changed since the given version (`about:blank`), another method has the name (`delivery-method-name-taken`) or the method would be the 21st active one (`delivery-method-limit-reached`); see `DeliveryConflictProblem`. Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/DeliveryConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A field is invalid, a key is missing or of a wrong type (problem details with `violations`, each at its field, with a Polish `title`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(DeliveryAttributes::MANAGE_DELIVERY_METHODS)]
    #[ExistingDeliveryMethod]
    public function __invoke(string $id, #[RequiredKeysBody] ReviseDeliveryMethodRequest $method): JsonResponse
    {
        // Validated by the payload mapping.
        \assert(\is_string($method->name) && \is_int($method->priceGross) && \is_bool($method->active) && \is_int($method->position) && \is_int($method->version));
        \assert((null === $method->description || \is_string($method->description)) && (null === $method->freeFromGross || \is_int($method->freeFromGross)));
        \assert(null === $method->trackingUrlTemplate || \is_string($method->trackingUrlTemplate));

        $this->commandBus->dispatch(new ReviseDeliveryMethod(
            $id,
            $method->version,
            $method->name,
            $method->description,
            $method->priceGross,
            $method->freeFromGross,
            $method->active,
            $method->position,
            $method->trackingUrlTemplate,
        ));

        return $this->responder->respond($id);
    }
}
