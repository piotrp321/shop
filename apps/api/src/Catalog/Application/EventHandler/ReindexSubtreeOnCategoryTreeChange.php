<?php

declare(strict_types=1);

namespace App\Catalog\Application\EventHandler;

use App\Catalog\Application\Command\StartCategoryReindex;
use App\Catalog\Application\Event\CategoryTreeChanged;
use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Domain\Category\CategoryId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A new name (and slug) or a new parent of a category changes category_names and category_ancestor_ids of every
 * product of its subtree, so it starts a reindexing run on command.bus (injected by the parameter name), whose
 * batches the worker takes from the transport `jobs` (spec ADMIN-FEATURES, 4 "Odświeżenie sklepu i reindeksacja").
 * The event does not tell a new name from a new slug alone; a slug is in no document, so such a run rewrites the
 * documents unchanged. A new category, a deleted (empty) one and a new place among the same siblings change no
 * document. A repeated event starts another run, which only writes the same documents again.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class ReindexSubtreeOnCategoryTreeChange
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    /**
     * @throws UnrecoverableMessageHandlingException when the event names no category
     */
    public function __invoke(CategoryTreeChanged $event): void
    {
        if (CategoryTreeChangeKind::Revised !== $event->kind && CategoryTreeChangeKind::Moved !== $event->kind) {
            return;
        }

        try {
            $categoryId = CategoryId::fromString($event->categoryId);
        } catch (\InvalidArgumentException $e) {
            // Catalog publishes only categories it has, so a retry cannot fix this.
            throw new UnrecoverableMessageHandlingException('CategoryTreeChanged does not name a category.', previous: $e);
        }

        $this->commandBus->dispatch(new StartCategoryReindex($categoryId->toString()));
    }
}
