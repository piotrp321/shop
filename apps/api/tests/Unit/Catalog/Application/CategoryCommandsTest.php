<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateCategoryHandler;
use App\Catalog\Application\Command\DeleteCategory;
use App\Catalog\Application\Command\DeleteCategoryHandler;
use App\Catalog\Application\Command\MoveCategory;
use App\Catalog\Application\Command\MoveCategoryHandler;
use App\Catalog\Application\Command\ReviseCategory;
use App\Catalog\Application\Command\ReviseCategoryHandler;
use App\Catalog\Application\Event\CategoryTreeChanged;
use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Domain\Category\CategoryCycle;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryModifiedConcurrently;
use App\Catalog\Domain\Category\CategoryNotEmpty;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategorySlugPath;
use App\Catalog\Domain\Category\CategoryTooDeep;
use App\Shared\Domain\BoundedCount;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryCategoryProductCounts;
use App\Tests\Support\InMemory\InMemoryCategoryRedirectRepository;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use App\Tests\Support\InMemory\RecordingCategoryTreeLock;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * The four commands of the category tree on in-memory adapters: each takes the tree lock first, applies the rules of
 * CategoryTree, saves the categories and the redirects it changed and publishes CategoryTreeChanged.
 */
final class CategoryCommandsTest extends TestCase
{
    private const string SPORT = '00000000-0000-4000-8000-000000000001';
    private const string RUN = '00000000-0000-4000-8000-000000000002';
    private const string SHOES = '00000000-0000-4000-8000-000000000003';
    private const string TOURISM = '00000000-0000-4000-8000-000000000004';
    private const string WINTER = '00000000-0000-4000-8000-000000000005';
    private const string LEAF = '00000000-0000-4000-8000-000000000006';

    private InMemoryCategoryRepository $categories;
    private InMemoryCategoryRedirectRepository $redirects;
    private RecordingCategoryTreeLock $lock;
    private RecordingDomainEventPublisher $events;
    private FixedCatalogClock $clock;
    private InMemoryCategoryProductCounts $products;

    protected function setUp(): void
    {
        $this->categories = new InMemoryCategoryRepository();
        $this->redirects = new InMemoryCategoryRedirectRepository();
        $this->lock = new RecordingCategoryTreeLock($this->categories);
        $this->events = new RecordingDomainEventPublisher();
        $this->clock = new FixedCatalogClock();
        $this->products = new InMemoryCategoryProductCounts();

        $this->create(self::SPORT, 'Sport', 'sport', null);
        $this->create(self::RUN, 'Bieganie', 'bieganie', self::SPORT);
        $this->create(self::SHOES, 'Buty', 'buty', self::RUN);
        $this->create(self::TOURISM, 'Turystyka', 'turystyka', null);
        $this->events->published = [];
        $this->lock->taken = 0;
        $this->categories->reads = 0;
    }

    public function testCreationTakesTheLockPlacesTheCategoryLastAndPublishesTheChange(): void
    {
        $this->create(self::WINTER, 'Akcesoria', 'akcesoria', null);

        self::assertSame(1, $this->lock->taken);
        self::assertTrue($this->lock->takenBeforeTheFirstRead);
        self::assertSame(['sport' => 0, 'turystyka' => 1, 'akcesoria' => 2], $this->rootPositions());
        self::assertEquals([new CategoryTreeChanged(self::WINTER, CategoryTreeChangeKind::Created, $this->clock->now())], $this->events->published);
    }

    public function testTheSeedCreatesAtPositionZeroWithoutAnEvent(): void
    {
        $this->handlers()['create'](CreateCategory::bySeed(self::WINTER, 'Akcesoria', 'akcesoria', null));

        self::assertSame(['akcesoria' => 0, 'sport' => 0, 'turystyka' => 1], $this->rootPositions());
        self::assertSame([], $this->events->published);
    }

    public function testACreatedCategoryTakesItsAddressBackFromARedirect(): void
    {
        $this->revise(self::TOURISM, 1, 'Turystyka', 'gory');
        self::assertSame(self::TOURISM, $this->redirects->find(CategorySlugPath::fromString('turystyka'))?->toString());

        $this->create(self::WINTER, 'Turystyka', 'turystyka', null);

        self::assertNull($this->redirects->find(CategorySlugPath::fromString('turystyka')));
    }

    public function testACreationUnderTheFourthLevelIsTooDeep(): void
    {
        $this->create(self::LEAF, 'Trail', 'trail', self::SHOES);

        $this->expectException(CategoryTooDeep::class);
        $this->create(self::WINTER, 'Za głęboko', 'za-gleboko', self::LEAF);
    }

    public function testARevisionWithANewSlugRecordsTheRedirectsOfTheSubtree(): void
    {
        $this->revise(self::RUN, 1, 'Biegi', 'biegi');

        $run = $this->categories->get(CategoryId::fromString(self::RUN));
        self::assertSame('Biegi', $run->name()->value());
        self::assertSame(2, $run->version());
        self::assertSame(self::RUN, $this->redirects->find(CategorySlugPath::fromString('sport/bieganie'))?->toString());
        self::assertSame(self::SHOES, $this->redirects->find(CategorySlugPath::fromString('sport/bieganie/buty'))?->toString());
        self::assertEquals([new CategoryTreeChanged(self::RUN, CategoryTreeChangeKind::Revised, $this->clock->now())], $this->events->published);
        self::assertSame(1, $this->lock->taken);
    }

    public function testARevisionWithAStaleVersionChangesNothing(): void
    {
        try {
            $this->revise(self::RUN, 2, 'Biegi', 'biegi');
            self::fail('Expected CategoryModifiedConcurrently.');
        } catch (CategoryModifiedConcurrently) {
        }

        self::assertSame('Bieganie', $this->categories->get(CategoryId::fromString(self::RUN))->name()->value());
        self::assertSame([], $this->redirects->all());
        self::assertSame([], $this->events->published);
    }

    public function testAMoveChangesThePathsOfTheSubtreeAndRedirectsItsAddresses(): void
    {
        $this->handlers()['move'](new MoveCategory(self::RUN, 1, self::TOURISM, 0));

        self::assertSame('/'.self::TOURISM.'/'.self::RUN.'/', $this->categories->get(CategoryId::fromString(self::RUN))->path()->toString());
        self::assertSame('/'.self::TOURISM.'/'.self::RUN.'/'.self::SHOES.'/', $this->categories->get(CategoryId::fromString(self::SHOES))->path()->toString());
        self::assertSame(self::SHOES, $this->redirects->find(CategorySlugPath::fromString('sport/bieganie/buty'))?->toString());
        self::assertEquals([new CategoryTreeChanged(self::RUN, CategoryTreeChangeKind::Moved, $this->clock->now())], $this->events->published);
    }

    public function testAMoveWithinTheSameParentIsAReorder(): void
    {
        $this->handlers()['move'](new MoveCategory(self::TOURISM, 1, null, 0));

        self::assertSame(['turystyka' => 0, 'sport' => 1], $this->rootPositions());
        self::assertSame([], $this->redirects->all());
        self::assertEquals([new CategoryTreeChanged(self::TOURISM, CategoryTreeChangeKind::Reordered, $this->clock->now())], $this->events->published);
    }

    public function testAMoveIntoTheOwnSubtreeChangesNothing(): void
    {
        try {
            $this->handlers()['move'](new MoveCategory(self::SPORT, 1, self::SHOES, 0));
            self::fail('Expected CategoryCycle.');
        } catch (CategoryCycle) {
        }

        self::assertNull($this->categories->get(CategoryId::fromString(self::SPORT))->parentId());
        self::assertSame([], $this->events->published);
    }

    public function testAnEmptyCategoryIsDeleted(): void
    {
        $this->handlers()['delete'](new DeleteCategory(self::TOURISM, 1));

        try {
            $this->categories->get(CategoryId::fromString(self::TOURISM));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
        }
        self::assertSame([self::TOURISM], $this->categories->lockedRows);
        self::assertEquals([new CategoryTreeChanged(self::TOURISM, CategoryTreeChangeKind::Deleted, $this->clock->now())], $this->events->published);
    }

    public function testACategoryWithProductsIsNotDeleted(): void
    {
        $this->products->counts[self::TOURISM] = new BoundedCount(3, false);

        try {
            $this->handlers()['delete'](new DeleteCategory(self::TOURISM, 1));
            self::fail('Expected CategoryNotEmpty.');
        } catch (CategoryNotEmpty $e) {
            self::assertEquals(new BoundedCount(3, false), $e->products);
            self::assertSame([[self::TOURISM, 1000]], $this->products->asked);
        }

        self::assertSame('turystyka', $this->categories->get(CategoryId::fromString(self::TOURISM))->slug()->value());
        self::assertSame([], $this->events->published);
    }

    public function testDeletingAnUnknownCategoryIsNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);
        $this->handlers()['delete'](new DeleteCategory(self::WINTER, 1));
    }

    private function create(string $id, string $name, string $slug, ?string $parentId): void
    {
        $this->handlers()['create'](new CreateCategory($id, $name, $slug, $parentId));
    }

    private function revise(string $id, int $version, string $name, string $slug): void
    {
        $this->handlers()['revise'](new ReviseCategory($id, $version, $name, $slug));
    }

    /**
     * @return array{create: CreateCategoryHandler, revise: ReviseCategoryHandler, move: MoveCategoryHandler, delete: DeleteCategoryHandler}
     */
    private function handlers(): array
    {
        return [
            'create' => new CreateCategoryHandler($this->categories, $this->redirects, $this->lock, $this->events, $this->clock),
            'revise' => new ReviseCategoryHandler($this->categories, $this->redirects, $this->lock, $this->events, $this->clock),
            'move' => new MoveCategoryHandler($this->categories, $this->redirects, $this->lock, $this->events, $this->clock),
            'delete' => new DeleteCategoryHandler($this->categories, $this->products, $this->lock, $this->events, $this->clock),
        ];
    }

    /**
     * @return array<string, int> slug => position of the roots, ordered by position then slug
     */
    private function rootPositions(): array
    {
        $positions = [];
        foreach ($this->categories->all() as $category) {
            if (null === $category->parentId()) {
                $positions[$category->slug()->value()] = $category->position();
            }
        }
        uksort($positions, static fn (string $a, string $b): int => $positions[$a] <=> $positions[$b] ?: strcmp($a, $b));

        return $positions;
    }
}
