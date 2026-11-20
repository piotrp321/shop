<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodNotFound;
use App\Delivery\Domain\DeliveryMethodRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDeliveryMethodHandler
{
    public function __construct(private DeliveryMethodRepository $methods)
    {
    }

    /**
     * @throws DeliveryMethodNotFound also for an id that is no UUID
     */
    public function __invoke(GetDeliveryMethod $query): DeliveryMethodView
    {
        try {
            $id = DeliveryMethodId::fromString($query->id);
        } catch (\InvalidArgumentException) {
            throw new DeliveryMethodNotFound();
        }

        return DeliveryMethodView::of($this->methods->find($id) ?? throw new DeliveryMethodNotFound());
    }
}
