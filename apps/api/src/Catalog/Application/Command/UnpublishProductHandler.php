<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductRepository;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UnpublishProductHandler
{
    public function __construct(
        private ProductRepository $products,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * Makes a published product a draft again (new version, ProductChanged). A draft with the expected version is left
     * as it is: nothing is saved or published.
     *
     * @throws ProductNotFound
     * @throws ProductModifiedConcurrently when the expected version is not the stored one, also for a draft
     */
    public function __invoke(UnpublishProduct $command): void
    {
        $product = $this->products->get(ProductId::fromString($command->productId));
        if ($command->expectedVersion !== $product->version()) {
            throw new ProductModifiedConcurrently();
        }

        $now = $this->clock->now();
        $product->unpublish($now);
        $events = $product->releaseEvents();
        if ([] === $events) {
            return;
        }

        $this->products->save($product, $command->expectedVersion, $command->actorId, $now);
        $this->events->publish(...ProductChanged::translate($events));
    }
}
