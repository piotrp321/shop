<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Application\Command\CreateDiscountCode;
use App\Pricing\Application\Port\Clock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates the demo discount codes through CreateDiscountCode (the `command.bus`, injected by the parameter name)
 * without an actor, enabled and without a usage limit, with the period moved from the moment of the seed by the days of
 * the file. The list is already validated; the codes must not exist yet (see PricingReset).
 */
final readonly class DemoDiscountCodeSeeder
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private Clock $clock,
    ) {
    }

    /**
     * Each code is created in its own transaction.
     *
     * @return int the number of codes created
     */
    public function seed(DemoDiscountCodeList $codes): int
    {
        $now = $this->clock->now();
        foreach ($codes->codes() as $code) {
            $this->commandBus->dispatch(new CreateDiscountCode(
                Uuid::v7()->toRfc4122(),
                $code->code,
                $code->kind,
                $code->value,
                $code->minimumOrderGross,
                $now->modify(\sprintf('%+d days', $code->startsInDays)),
                null === $code->endsInDays ? null : $now->modify(\sprintf('%+d days', $code->endsInDays)),
                null,
                true,
                null,
                null,
            ));
        }

        return \count($codes->codes());
    }
}
