<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Application\Port\CategoryTreeLock;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Category\CategoryModifiedConcurrently;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRedirectRepository;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A new name and slug of a category, in the transaction of command.bus under the lock of the tree. A new slug
 * redirects the old addresses of the category and its descendants.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReviseCategoryHandler
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
     * @throws CategorySlugTaken
     */
    public function __invoke(ReviseCategory $command): void
    {
        $id = CategoryTreeWrite::existingId($command->categoryId);
        $name = CategoryName::of($command->name);
        $slug = new CategorySlug($command->slug);

        $this->lock->exclusive();
        $change = $this->write->tree()->revise($id, $command->expectedVersion, $name, $slug);
        $this->write->save($change, CategoryTreeChangeKind::Revised);
    }
}
