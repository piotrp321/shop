<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Application\Port\CategoryTreeLock;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryRedirectRepository;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Catalog\Domain\Category\CategoryTooDeep;
use App\Catalog\Domain\Category\ParentCategoryNotFound;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Creates a category in the transaction of command.bus, under the lock of the tree.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateCategoryHandler
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
     * @throws CategoryAlreadyExists
     * @throws ParentCategoryNotFound
     * @throws CategoryTooDeep
     * @throws CategorySlugTaken      when a sibling (another root for a root) already has the slug
     */
    public function __invoke(CreateCategory $command): void
    {
        $id = CategoryId::fromString($command->id);
        $name = CategoryName::of($command->name);
        $slug = new CategorySlug($command->slug);
        $parentId = CategoryTreeWrite::parentId($command->parentId);

        $this->lock->exclusive();
        $change = $this->write->tree()->add($id, $name, $slug, $parentId, append: !$command->bySeed);
        $this->write->save($change, $command->bySeed ? null : CategoryTreeChangeKind::Created);
    }
}
