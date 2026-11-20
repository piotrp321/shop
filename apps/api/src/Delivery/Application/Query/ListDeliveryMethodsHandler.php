<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

use App\Delivery\Domain\DeliveryMethodOrder;
use App\Delivery\Domain\DeliveryMethodRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListDeliveryMethodsHandler
{
    public function __construct(private DeliveryMethodRepository $methods)
    {
    }

    /**
     * @return list<DeliveryMethodView> the active methods by position and name, then the inactive ones by name
     */
    public function __invoke(ListDeliveryMethods $query): array
    {
        return array_map(DeliveryMethodView::of(...), DeliveryMethodOrder::sort($this->methods->all()));
    }
}
