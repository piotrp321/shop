<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryCycle;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryModifiedConcurrently;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotEmpty;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Catalog\Domain\Category\CategoryTooDeep;
use App\Catalog\Domain\Category\CategoryTree;
use App\Catalog\Domain\Category\CategoryTreeChange;
use App\Catalog\Domain\Category\ParentCategoryNotFound;
use App\Shared\Domain\BoundedCount;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\ExtendedProblem;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\TypedProblem;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the category tree (spec ADMIN-FEATURES, 4 "Reguły", "Przekierowania"): the order of the siblings, the
 * place of a new or moved category, the depth, cycles, empty deletion, versions and the redirects of changed paths.
 */
final class CategoryTreeTest extends TestCase
{
    private const string SPORT = '00000000-0000-4000-8000-000000000001';
    private const string RUN = '00000000-0000-4000-8000-000000000002';
    private const string SHOES = '00000000-0000-4000-8000-000000000003';
    private const string TRAIL = '00000000-0000-4000-8000-000000000004';
    private const string SPIKES = '00000000-0000-4000-8000-000000000005';
    private const string TOURISM = '00000000-0000-4000-8000-000000000006';
    private const string NEW = '00000000-0000-4000-8000-000000000099';
    private const string PREFIX = 'tag:piotrp321.github.io,2026:shop/problems/';

    public function testSiblingsAreOrderedByPositionThenPolishNameThenId(): void
    {
        $tree = CategoryTree::of([
            self::root('00000000-0000-4000-8000-000000000011', 'Łyżwy', 'lyzwy'),
            self::root('00000000-0000-4000-8000-000000000012', 'Lody', 'lody'),
            self::root('00000000-0000-4000-8000-000000000014', 'Zima', 'zima-b'),
            self::root('00000000-0000-4000-8000-000000000013', 'Zima', 'zima-a'),
            self::root('00000000-0000-4000-8000-000000000015', 'Akcesoria', 'akcesoria', position: 2),
            self::root('00000000-0000-4000-8000-000000000016', 'Ćwiczenia', 'cwiczenia', position: 1),
        ]);

        self::assertSame(
            ['lody', 'lyzwy', 'zima-a', 'zima-b', 'cwiczenia', 'akcesoria'],
            self::slugs($tree->children(null)),
        );
    }

    public function testTheSlugPathJoinsTheSlugsFromTheRoot(): void
    {
        $tree = $this->sportTree();

        self::assertSame('sport/bieganie/buty/trail', $tree->slugPath(self::id(self::TRAIL))->toString());
        self::assertSame('sport', $tree->slugPath(self::id(self::SPORT))->toString());
    }

    public function testANewCategoryGoesToTheEndOfItsSiblingsWhichAreNumberedInTheirCurrentOrder(): void
    {
        $tree = CategoryTree::of([
            self::root(self::SPORT, 'Sport', 'sport'),
            self::root(self::TOURISM, 'Turystyka', 'turystyka'),
            self::root(self::RUN, 'Bieganie', 'bieganie'),
        ]);

        $change = $tree->add(self::id(self::NEW), CategoryName::of('Akcesoria'), new CategorySlug('akcesoria'), null);

        self::assertSame(self::NEW, $change->category->id()->toString());
        self::assertSame(3, $change->category->position());
        self::assertSame(1, $change->category->version());
        self::assertSame(['bieganie' => 0, 'sport' => 1, 'turystyka' => 2, 'akcesoria' => 3], self::positions($tree->children(null)));
        // Only the categories whose position changed are saved.
        self::assertSame(['sport' => 1, 'turystyka' => 2, 'akcesoria' => 3], self::positions($change->saved));
        self::assertSame([], $change->redirects);
        self::assertSame(['akcesoria'], $change->restoredPaths);
    }

    public function testTheSeedAddsACategoryAtPositionZeroWithoutRenumberingTheSiblings(): void
    {
        $tree = CategoryTree::of([self::root(self::SPORT, 'Sport', 'sport'), self::root(self::TOURISM, 'Turystyka', 'turystyka')]);

        $change = $tree->add(self::id(self::NEW), CategoryName::of('Akcesoria'), new CategorySlug('akcesoria'), null, append: false);

        self::assertSame(0, $change->category->position());
        self::assertSame(['akcesoria' => 0], self::positions($change->saved));
    }

    public function testAChildGetsThePathOfItsParent(): void
    {
        $tree = $this->sportTree();

        $change = $tree->add(self::id(self::NEW), CategoryName::of('Kolce'), new CategorySlug('kolce'), self::id(self::RUN));

        self::assertSame('/'.self::SPORT.'/'.self::RUN.'/'.self::NEW.'/', $change->category->path()->toString());
        self::assertSame(['sport/bieganie/kolce'], $change->restoredPaths);
    }

    public function testAChildOfTheFourthLevelIsTooDeep(): void
    {
        $tree = $this->sportTree();

        $this->expectExceptionObject(new CategoryTooDeep());
        $tree->add(self::id(self::NEW), CategoryName::of('Za głęboko'), new CategorySlug('za-gleboko'), self::id(self::TRAIL));
    }

    public function testAChildOfTheThirdLevelIsAllowed(): void
    {
        $tree = $this->sportTree();

        $change = $tree->add(self::id(self::NEW), CategoryName::of('Kolce'), new CategorySlug('kolce'), self::id(self::SHOES));

        self::assertSame(4, \count($change->category->path()->ancestorIds()));
    }

    public function testAnUnknownParentIsInvalidInput(): void
    {
        $tree = $this->sportTree();

        try {
            $tree->add(self::id(self::NEW), CategoryName::of('Kolce'), new CategorySlug('kolce'), self::id(self::NEW));
            self::fail('Expected ParentCategoryNotFound.');
        } catch (ParentCategoryNotFound $e) {
            self::assertContains(InvalidInput::class, class_implements($e));
            self::assertSame('Kategoria nadrzędna nie istnieje.', $e->getMessage());
        }
    }

    public function testASlugOfASiblingIsTaken(): void
    {
        $tree = $this->sportTree();

        try {
            $tree->add(self::id(self::NEW), CategoryName::of('Bieg'), new CategorySlug('bieganie'), self::id(self::SPORT));
            self::fail('Expected CategorySlugTaken.');
        } catch (CategorySlugTaken $e) {
            self::assertContains(Conflict::class, class_implements($e));
            self::assertContains(TypedProblem::class, class_implements($e));
            self::assertSame(self::PREFIX.'category-slug-taken', $e->problemType());
            self::assertSame('Inna kategoria w tym miejscu drzewa ma już ten slug.', $e->getMessage());
        }
    }

    public function testAnExistingIdCannotBeAddedAgain(): void
    {
        $tree = $this->sportTree();

        $this->expectException(CategoryAlreadyExists::class);
        $tree->add(self::id(self::RUN), CategoryName::of('Inna'), new CategorySlug('inna'), null);
    }

    public function testARenameWithoutASlugChangeRaisesTheVersionAndRedirectsNothing(): void
    {
        $tree = $this->sportTree();

        $change = $tree->revise(self::id(self::RUN), 1, CategoryName::of('Bieganie i trail'), new CategorySlug('bieganie'));

        self::assertSame('Bieganie i trail', $change->category->name()->value());
        self::assertSame(2, $change->category->version());
        self::assertSame([], $change->redirects);
        self::assertSame([], $change->restoredPaths);
        self::assertNull($change->previousPath);
    }

    public function testASlugChangeRedirectsTheOldPathsOfTheCategoryAndItsDescendants(): void
    {
        $tree = $this->sportTree();

        $change = $tree->revise(self::id(self::RUN), 1, CategoryName::of('Bieganie'), new CategorySlug('biegi'));

        self::assertSame([
            'sport/bieganie' => self::RUN,
            'sport/bieganie/buty' => self::SHOES,
            'sport/bieganie/buty/trail' => self::TRAIL,
        ], self::redirects($change));
        self::assertEqualsCanonicalizing(['sport/biegi', 'sport/biegi/buty', 'sport/biegi/buty/trail'], $change->restoredPaths);
        self::assertSame(['biegi' => 0], self::positions($change->saved));
    }

    public function testAStaleVersionIsRefusedBeforeAnyOtherRule(): void
    {
        $tree = $this->sportTree();

        try {
            $tree->revise(self::id(self::RUN), 2, CategoryName::of('Bieganie'), new CategorySlug('turystyka'));
            self::fail('Expected CategoryModifiedConcurrently.');
        } catch (CategoryModifiedConcurrently $e) {
            self::assertContains(Conflict::class, class_implements($e));
            self::assertSame(self::PREFIX.'category-modified', $e->problemType());
            self::assertSame('Kategoria została w międzyczasie zmieniona. Odśwież drzewo i spróbuj ponownie.', $e->getMessage());
        }
    }

    public function testARevisedSlugMustNotBeTakenByASiblingButMayStayTheSame(): void
    {
        $tree = CategoryTree::of([self::root(self::SPORT, 'Sport', 'sport'), self::root(self::TOURISM, 'Turystyka', 'turystyka')]);

        $tree->revise(self::id(self::SPORT), 1, CategoryName::of('Sporty'), new CategorySlug('sport'));

        $this->expectException(CategorySlugTaken::class);
        $tree->revise(self::id(self::SPORT), 2, CategoryName::of('Sporty'), new CategorySlug('turystyka'));
    }

    public function testAnUnknownCategoryIsNotFound(): void
    {
        $tree = $this->sportTree();

        try {
            $tree->revise(self::id(self::NEW), 1, CategoryName::of('X'), new CategorySlug('x'));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound $e) {
            self::assertSame('Kategoria nie istnieje.', $e->getMessage());
        }
    }

    public function testTheFirstManualReorderMovesOnlyTheChosenCategory(): void
    {
        $tree = CategoryTree::of([
            self::root(self::SPORT, 'Sport', 'sport'),
            self::root(self::TOURISM, 'Turystyka', 'turystyka'),
            self::root(self::RUN, 'Bieganie', 'bieganie'),
            self::root(self::SHOES, 'Obuwie', 'obuwie'),
        ]);

        $change = $tree->move(self::id(self::TOURISM), 1, null, 0);

        self::assertSame(['turystyka' => 0, 'bieganie' => 1, 'obuwie' => 2, 'sport' => 3], self::positions($change->saved));
        self::assertSame(['turystyka', 'bieganie', 'obuwie', 'sport'], self::slugs(CategoryTree::of($this->all($tree))->children(null)));
        self::assertSame([], $change->redirects);
        self::assertNull($change->previousPath);
        self::assertSame(2, $change->category->version());
    }

    public function testAReorderBeyondTheLastSiblingPutsTheCategoryAtTheEnd(): void
    {
        $tree = CategoryTree::of([self::root(self::SPORT, 'Sport', 'sport'), self::root(self::TOURISM, 'Turystyka', 'turystyka'), self::root(self::RUN, 'Bieganie', 'bieganie')]);

        $tree->move(self::id(self::RUN), 1, null, 99);

        self::assertSame(['sport', 'turystyka', 'bieganie'], self::slugs(CategoryTree::of($this->all($tree))->children(null)));
    }

    public function testAMoveToAnotherParentChangesThePathAndRedirectsTheSubtree(): void
    {
        $tree = $this->sportTree();

        $change = $tree->move(self::id(self::SHOES), 1, self::id(self::TOURISM), 0);

        self::assertSame('/'.self::TOURISM.'/'.self::SHOES.'/', $change->category->path()->toString());
        self::assertSame(self::TOURISM, $change->category->parentId()?->toString());
        self::assertSame('/'.self::SPORT.'/'.self::RUN.'/'.self::SHOES.'/', $change->previousPath?->toString());
        self::assertSame([
            'sport/bieganie/buty' => self::SHOES,
            'sport/bieganie/buty/trail' => self::TRAIL,
        ], self::redirects($change));
        self::assertEqualsCanonicalizing(['turystyka/buty', 'turystyka/buty/trail'], $change->restoredPaths);
        self::assertSame(2, $change->category->version());
    }

    public function testAMoveToTheRootHasNoParent(): void
    {
        $tree = $this->sportTree();

        $change = $tree->move(self::id(self::RUN), 1, null, 0);

        self::assertNull($change->category->parentId());
        self::assertSame('/'.self::RUN.'/', $change->category->path()->toString());
        self::assertSame(['bieganie' => 0, 'sport' => 1, 'turystyka' => 2], self::positions($change->saved));
    }

    public function testAMoveIntoItselfOrItsSubtreeIsACycle(): void
    {
        $tree = $this->sportTree();

        foreach ([self::RUN, self::SHOES, self::TRAIL] as $target) {
            try {
                $tree->move(self::id(self::RUN), 1, self::id($target), 0);
                self::fail('Expected CategoryCycle.');
            } catch (CategoryCycle $e) {
                self::assertSame(self::PREFIX.'category-cycle', $e->problemType());
                self::assertSame('Nie można przenieść kategorii do niej samej ani do jej podkategorii.', $e->getMessage());
            }
        }
    }

    public function testAMoveCountsTheDepthOfTheWholeSubtree(): void
    {
        $tree = $this->sportTree();

        try {
            // bieganie has three levels (bieganie, buty, trail); under turystyka they would end on the fourth.
            $tree->move(self::id(self::RUN), 1, self::id(self::TOURISM), 0);
        } catch (CategoryTooDeep) {
            self::fail('Four levels are allowed.');
        }

        $tree = $this->sportTree();
        try {
            $tree->move(self::id(self::RUN), 1, self::id(self::SPIKES), 0);
            self::fail('Expected CategoryTooDeep.');
        } catch (CategoryTooDeep $e) {
            self::assertSame(self::PREFIX.'category-too-deep', $e->problemType());
            self::assertSame('Drzewo kategorii może mieć najwyżej 4 poziomy.', $e->getMessage());
        }
    }

    public function testAMoveUnderAParentWithTheSameSlugAmongItsChildrenIsRefused(): void
    {
        $tree = CategoryTree::of([
            self::root(self::SPORT, 'Sport', 'sport'),
            self::root(self::TOURISM, 'Turystyka', 'turystyka'),
            self::child(self::RUN, 'Buty', 'buty', self::SPORT),
            self::child(self::SHOES, 'Buty', 'buty', self::TOURISM),
        ]);

        $this->expectException(CategorySlugTaken::class);
        $tree->move(self::id(self::RUN), 1, self::id(self::TOURISM), 0);
    }

    public function testAMoveToAnUnknownParentIsInvalidInput(): void
    {
        $this->expectException(ParentCategoryNotFound::class);
        $this->sportTree()->move(self::id(self::RUN), 1, self::id(self::NEW), 0);
    }

    public function testAMoveWithAStaleVersionIsRefused(): void
    {
        $this->expectException(CategoryModifiedConcurrently::class);
        $this->sportTree()->move(self::id(self::RUN), 3, self::id(self::RUN), 0);
    }

    public function testOnlyAnEmptyCategoryIsRemoved(): void
    {
        $tree = $this->sportTree();

        $removed = $tree->remove(self::id(self::TRAIL), 1, new BoundedCount(0, false));

        self::assertSame(self::TRAIL, $removed->id()->toString());
    }

    public function testACategoryWithSubcategoriesIsNotEmpty(): void
    {
        $tree = $this->sportTree();

        try {
            $tree->remove(self::id(self::RUN), 1, new BoundedCount(0, false));
            self::fail('Expected CategoryNotEmpty.');
        } catch (CategoryNotEmpty $e) {
            self::assertContains(Conflict::class, class_implements($e));
            self::assertContains(ExtendedProblem::class, class_implements($e));
            self::assertSame(self::PREFIX.'category-not-empty', $e->problemType());
            self::assertSame('Przenieś produkty do innej kategorii, zanim usuniesz tę.', $e->getMessage());
            self::assertSame([
                'subcategoryCount' => ['value' => 1, 'capped' => false],
                'productCount' => ['value' => 0, 'capped' => false],
            ], $e->problemExtensions());
        }
    }

    public function testACategoryWithProductsIsNotEmpty(): void
    {
        $tree = $this->sportTree();

        try {
            $tree->remove(self::id(self::TRAIL), 1, new BoundedCount(1000, true));
            self::fail('Expected CategoryNotEmpty.');
        } catch (CategoryNotEmpty $e) {
            self::assertSame([
                'subcategoryCount' => ['value' => 0, 'capped' => false],
                'productCount' => ['value' => 1000, 'capped' => true],
            ], $e->problemExtensions());
        }
    }

    public function testARemovalWithAStaleVersionIsRefused(): void
    {
        $this->expectException(CategoryModifiedConcurrently::class);
        $this->sportTree()->remove(self::id(self::TRAIL), 2, new BoundedCount(0, false));
    }

    /**
     * sport / bieganie / buty / trail, sport / kolce-parent (a leaf at the second level is not needed), turystyka;
     * spikes is a fourth-level leaf under turystyka / a / b.
     */
    private function sportTree(): CategoryTree
    {
        return CategoryTree::of([
            self::root(self::SPORT, 'Sport', 'sport'),
            self::child(self::RUN, 'Bieganie', 'bieganie', self::SPORT),
            self::child(self::SHOES, 'Buty', 'buty', self::RUN),
            self::child(self::TRAIL, 'Trail', 'trail', self::SHOES),
            self::root(self::TOURISM, 'Turystyka', 'turystyka'),
            self::child('00000000-0000-4000-8000-000000000021', 'A', 'a', self::TOURISM),
            self::child(self::SPIKES, 'B', 'b', '00000000-0000-4000-8000-000000000021'),
        ]);
    }

    private static function id(string $id): CategoryId
    {
        return CategoryId::fromString($id);
    }

    private static function root(string $id, string $name, string $slug, int $position = 0): Category
    {
        return Category::reconstitute(self::id($id), CategoryName::of($name), new CategorySlug($slug), null, CategoryPath::fromString('/'.$id.'/'), $position, 1);
    }

    /** @var array<string, string> the path of every category built by child() and root() */
    private static array $paths = [];

    private static function child(string $id, string $name, string $slug, string $parentId): Category
    {
        $parentPath = self::$paths[$parentId] ?? '/'.$parentId.'/';
        $path = $parentPath.$id.'/';
        self::$paths[$id] = $path;

        return Category::reconstitute(self::id($id), CategoryName::of($name), new CategorySlug($slug), self::id($parentId), CategoryPath::fromString($path), 0, 1);
    }

    /**
     * @param list<Category> $categories
     *
     * @return list<string>
     */
    private static function slugs(array $categories): array
    {
        return array_map(static fn (Category $category): string => $category->slug()->value(), $categories);
    }

    /**
     * @param list<Category> $categories
     *
     * @return array<string, int> slug => position, in the order given
     */
    private static function positions(array $categories): array
    {
        $positions = [];
        foreach ($categories as $category) {
            $positions[$category->slug()->value()] = $category->position();
        }
        asort($positions);

        return $positions;
    }

    /**
     * @return array<string, string> old path => category id
     */
    private static function redirects(CategoryTreeChange $change): array
    {
        $redirects = array_map(static fn (CategoryId $id): string => $id->toString(), $change->redirects);
        ksort($redirects);

        return $redirects;
    }

    /**
     * @return list<Category>
     */
    private function all(CategoryTree $tree): array
    {
        return $tree->all();
    }
}
