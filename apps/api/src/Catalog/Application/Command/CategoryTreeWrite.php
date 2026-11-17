<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\CategoryTreeChanged;
use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRedirectRepository;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategoryTree;
use App\Catalog\Domain\Category\CategoryTreeChange;
use App\Catalog\Domain\Category\ParentCategoryNotFound;
use App\Shared\Domain\Event\DomainEventPublisher;

/**
 * The steps every change of the tree shares: the tree read under the lock, and the save of a change with its
 * redirects and its event.
 */
final readonly class CategoryTreeWrite
{
    public function __construct(
        private CategoryRepository $categories,
        private CategoryRedirectRepository $redirects,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * An id that is not a UUID names no category.
     *
     * @throws CategoryNotFound
     */
    public static function existingId(string $id): CategoryId
    {
        try {
            return CategoryId::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new CategoryNotFound();
        }
    }

    /**
     * @throws ParentCategoryNotFound when the id is not a UUID
     */
    public static function parentId(?string $id): ?CategoryId
    {
        try {
            return null === $id ? null : CategoryId::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new ParentCategoryNotFound();
        }
    }

    /**
     * Must follow the lock of the tree.
     */
    public function tree(): CategoryTree
    {
        return CategoryTree::of($this->categories->all());
    }

    /**
     * The paths of a moved subtree first (one statement), then the category and its renumbered siblings, then the
     * redirects: the old addresses lead to the category, the current ones are no redirects.
     */
    public function save(CategoryTreeChange $change, ?CategoryTreeChangeKind $kind): void
    {
        if (null !== $change->previousPath) {
            $this->categories->movePaths($change->previousPath, $change->category->path());
        }
        $this->categories->saveAll($change->saved);
        if ([] !== $change->redirects) {
            $this->redirects->record($change->redirects);
        }
        if ([] !== $change->restoredPaths) {
            $this->redirects->forget($change->restoredPaths);
        }
        if (null !== $kind) {
            $this->events->publish(new CategoryTreeChanged($change->category->id()->toString(), $kind, $this->clock->now()));
        }
    }
}
