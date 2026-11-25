<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Application\Query\DeliveryMethodView;
use App\Delivery\Application\Query\GetDeliveryMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The responses of the delivery methods: read after the write (or for the read itself), `private, no-store`.
 */
final class DeliveryMethodResponder
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function respond(string $id, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return self::private(new JsonResponse(DeliveryMethodResponse::of($this->find($id)), $status));
    }

    /**
     * @throws HandlerFailedException of DeliveryMethodNotFound (a 404) for an unknown method, also an id that is no UUID
     */
    public function find(string $id): DeliveryMethodView
    {
        /** @var DeliveryMethodView */
        return $this->handle(new GetDeliveryMethod($id));
    }

    public static function private(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
