<?php

declare(strict_types=1);

namespace App\Delivery\Application\Command;

use App\Delivery\Application\Port\Clock;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodLimitReached;
use App\Delivery\Domain\DeliveryMethodModifiedConcurrently;
use App\Delivery\Domain\DeliveryMethodNameTaken;
use App\Delivery\Domain\DeliveryMethodNotFound;
use App\Delivery\Domain\DeliveryMethodRepository;
use App\Delivery\Domain\InvalidDeliveryMethod;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Changes a method in the transaction of command.bus, without a lock (A8): the method (404), the values (422), the
 * version the change is based on (409), the limit of active methods when the change activates the method (409), then
 * the save, whose versioned UPDATE checks the version once more and whose unique index checks the name.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReviseDeliveryMethodHandler
{
    public function __construct(
        private DeliveryMethodRepository $methods,
        private Clock $clock,
    ) {
    }

    /**
     * @throws DeliveryMethodNotFound             also for an id that is no UUID
     * @throws InvalidDeliveryMethod
     * @throws DeliveryMethodModifiedConcurrently
     * @throws DeliveryMethodLimitReached
     * @throws DeliveryMethodNameTaken
     */
    public function __invoke(ReviseDeliveryMethod $command): void
    {
        try {
            $id = DeliveryMethodId::fromString($command->id);
        } catch (\InvalidArgumentException) {
            throw new DeliveryMethodNotFound();
        }
        $method = $this->methods->find($id) ?? throw new DeliveryMethodNotFound();

        $terms = DeliveryMethodTerms::of($command->name, $command->description, $command->priceGross, $command->freeFromGross, $command->position, $command->trackingUrlTemplate);
        if ($method->version() !== $command->expectedVersion) {
            throw new DeliveryMethodModifiedConcurrently();
        }
        $method->revise(
            $terms->name,
            $terms->description,
            $terms->price,
            $terms->freeFrom,
            $command->active,
            $terms->position,
            $terms->trackingUrlTemplate,
            $command->active && !$method->isActive() ? $this->methods->countActive() : 0,
            $this->clock->now(),
        );

        $this->methods->save($method);
    }
}
