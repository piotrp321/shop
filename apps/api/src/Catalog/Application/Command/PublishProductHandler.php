<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductNotPublishable;
use App\Catalog\Domain\Product\ProductRepository;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class PublishProductHandler
{
    public function __construct(
        private ProductRepository $products,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * Publishes a draft (new version, ProductChanged). A published product with the expected version is left as it is:
     * nothing is saved or published.
     *
     * @throws ProductNotFound
     * @throws ProductModifiedConcurrently when the expected version is not the stored one, also for a published product
     * @throws ProductNotPublishable       when the draft has no image
     */
    public function __invoke(PublishProduct $command): void
    {
        $product = $this->products->get(ProductId::fromString($command->productId));
        if ($command->expectedVersion !== $product->version()) {
            throw new ProductModifiedConcurrently();
        }

        $now = $this->clock->now();
        $product->publish($now);
        $events = $product->releaseEvents();
        if ([] === $events) {
            return;
        }

        $this->products->save($product, $command->expectedVersion, $command->actorId, $now);
        $this->events->publish(...ProductChanged::translate($events));
    }
}
