<?php

declare(strict_types=1);

namespace App\Delivery\Application\Command;

use App\Delivery\Application\Port\Clock;
use App\Delivery\Domain\DeliveryMethod;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodLimitReached;
use App\Delivery\Domain\DeliveryMethodNameTaken;
use App\Delivery\Domain\DeliveryMethodRepository;
use App\Delivery\Domain\InvalidDeliveryMethod;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Creates a method in the transaction of command.bus: the values (422), then the limit of active methods, counted
 * without a lock (A8), then the unique name, which is the repository's (its index), so two methods of the same name
 * created at once end with one of them and DeliveryMethodNameTaken.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateDeliveryMethodHandler
{
    public function __construct(
        private DeliveryMethodRepository $methods,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidDeliveryMethod
     * @throws DeliveryMethodLimitReached
     * @throws DeliveryMethodNameTaken
     */
    public function __invoke(CreateDeliveryMethod $command): void
    {
        $terms = DeliveryMethodTerms::of($command->name, $command->description, $command->priceGross, $command->freeFromGross, $command->position, $command->trackingUrlTemplate);

        $this->methods->save(DeliveryMethod::create(
            DeliveryMethodId::fromString($command->id),
            $terms->name,
            $terms->description,
            $terms->price,
            $terms->freeFrom,
            $command->active,
            $terms->position,
            $terms->trackingUrlTemplate,
            $command->active ? $this->methods->countActive() : 0,
            $this->clock->now(),
        ));
    }
}
