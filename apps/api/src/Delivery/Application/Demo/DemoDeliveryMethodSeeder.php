<?php

declare(strict_types=1);

namespace App\Delivery\Application\Demo;

use App\Delivery\Application\Command\CreateDeliveryMethod;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates the demo delivery methods through CreateDeliveryMethod (the `command.bus`, injected by the parameter name),
 * all active. The list is already validated; the methods must not exist yet (see DeliveryReset).
 */
final readonly class DemoDeliveryMethodSeeder
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    /**
     * Each method is created in its own transaction.
     *
     * @return int the number of methods created
     */
    public function seed(DemoDeliveryMethodList $methods): int
    {
        foreach ($methods->methods() as $method) {
            $this->commandBus->dispatch(new CreateDeliveryMethod(
                Uuid::v7()->toRfc4122(),
                $method->name,
                $method->description,
                $method->priceGross,
                $method->freeFromGross,
                true,
                $method->position,
                $method->trackingUrlTemplate,
            ));
        }

        return \count($methods->methods());
    }
}
