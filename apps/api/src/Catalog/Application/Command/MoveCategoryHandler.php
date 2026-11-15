<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Application\Port\CategoryTreeLock;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Category\CategoryCycle;
use App\Catalog\Domain\Category\CategoryModifiedConcurrently;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRedirectRepository;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Catalog\Domain\Category\CategoryTooDeep;
use App\Catalog\Domain\Category\ParentCategoryNotFound;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moves a category (with its subtree) under another parent or to another place among its siblings, in the transaction
 * of command.bus under the lock of the tree: two concurrent moves run one after another, so they never form a cycle.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class MoveCategoryHandler
{
    private CategoryTreeWrite $write;

    public function __construct(
        CategoryRepository $categories,
        CategoryRedirectRepository $redirects,
        private CategoryTreeLock $lock,
        DomainEventPublisher $events,
        Clock $clock,
    ) {
        $this->write = new CategoryTreeWrite($categories, $redirects, $events, $clock);
    }

    /**
     * @throws CategoryNotFound
     * @throws CategoryModifiedConcurrently
     * @throws ParentCategoryNotFound
     * @throws CategoryCycle
     * @throws CategoryTooDeep
     * @throws CategorySlugTaken
     */
    public function __invoke(MoveCategory $command): void
    {
        $id = CategoryTreeWrite::existingId($command->categoryId);
        $parentId = CategoryTreeWrite::parentId($command->parentId);

        $this->lock->exclusive();
        $change = $this->write->tree()->move($id, $command->expectedVersion, $parentId, $command->position);
        $this->write->save($change, null === $change->previousPath ? CategoryTreeChangeKind::Reordered : CategoryTreeChangeKind::Moved);
    }
}
