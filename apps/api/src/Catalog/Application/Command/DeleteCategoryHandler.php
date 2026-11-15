<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\CategoryTreeChanged;
use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Application\Port\CategoryProductCounts;
use App\Catalog\Application\Port\CategoryTreeLock;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Category\CategoryModifiedConcurrently;
use App\Catalog\Domain\Category\CategoryNotEmpty;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategoryTree;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes an empty category in the transaction of command.bus, under the lock of the tree. The row of the category is
 * locked before its products are counted: a product write locks the row of each of its categories with a key share
 * lock (CategoryRepository::lockForAssignment) before it is saved, so a product saved meanwhile is either counted or
 * waits for this delete and then fails on the missing category (UnknownCategory, 422), never on the foreign key.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteCategoryHandler
{
    /** The counts of the 409 stop here (spec ADMIN-FEATURES, 4: BoundedCount). */
    public const int LIMIT = 1000;

    public function __construct(
        private CategoryRepository $categories,
        private CategoryProductCounts $products,
        private CategoryTreeLock $lock,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CategoryNotFound
     * @throws CategoryModifiedConcurrently
     * @throws CategoryNotEmpty
     */
    public function __invoke(DeleteCategory $command): void
    {
        $id = CategoryTreeWrite::existingId($command->categoryId);

        $this->lock->exclusive();
        $tree = CategoryTree::of($this->categories->all());
        $tree->get($id);
        $this->categories->lock($id);
        $removed = $tree->remove($id, $command->expectedVersion, $this->products->count($id, self::LIMIT));
        $this->categories->remove($removed);
        $this->events->publish(new CategoryTreeChanged($id->toString(), CategoryTreeChangeKind::Deleted, $this->clock->now()));
    }
}
