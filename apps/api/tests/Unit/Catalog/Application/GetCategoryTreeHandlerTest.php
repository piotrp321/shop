<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Query\CategoryNode;
use App\Catalog\Application\Query\GetCategoryTree;
use App\Catalog\Application\Query\GetCategoryTreeHandler;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategorySlug;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use PHPUnit\Framework\TestCase;

final class GetCategoryTreeHandlerTest extends TestCase
{
    private InMemoryCategoryRepository $categories;
    private GetCategoryTreeHandler $handler;

    protected function setUp(): void
    {
        $this->categories = new InMemoryCategoryRepository();
        $this->handler = new GetCategoryTreeHandler($this->categories);
    }

    public function testEmptyCatalogueGivesEmptyTree(): void
    {
        self::assertSame([], ($this->handler)(new GetCategoryTree()));
    }

    public function testBuildsNestedTreeThreeLevelsDeep(): void
    {
        $sport = $this->root('00000000-0000-4000-8000-000000000001', 'Sport', 'sport');
        $running = $this->child('00000000-0000-4000-8000-000000000002', 'Bieganie', $sport, 'bieganie');
        $shoes = $this->child('00000000-0000-4000-8000-000000000003', 'Buty', $running, 'buty');

        $tree = ($this->handler)(new GetCategoryTree());

        self::assertEquals([
            new CategoryNode($sport->id()->toString(), 'Sport', 'sport', 'sport', [
                new CategoryNode($running->id()->toString(), 'Bieganie', 'bieganie', 'sport/bieganie', [
                    new CategoryNode($shoes->id()->toString(), 'Buty', 'buty', 'sport/bieganie/buty', []),
                ]),
            ]),
        ], $tree);
    }

    public function testPathJoinsTheSlugsFromTheRootInEveryBranch(): void
    {
        $running = $this->root('00000000-0000-4000-8000-000000000001', 'Bieganie', 'bieganie');
        $hiking = $this->root('00000000-0000-4000-8000-000000000002', 'Turystyka', 'turystyka');
        $this->child('00000000-0000-4000-8000-000000000003', 'Buty', $running, 'buty');
        $hikingShoes = $this->child('00000000-0000-4000-8000-000000000004', 'Buty', $hiking, 'buty');
        $this->child('00000000-0000-4000-8000-000000000005', 'Buty', $hikingShoes, 'buty');

        $tree = ($this->handler)(new GetCategoryTree());

        self::assertSame(['bieganie', 'turystyka'], array_map(static fn (CategoryNode $node): string => $node->path, $tree));
        self::assertSame('bieganie/buty', $tree[0]->children[0]->path);
        self::assertSame('turystyka/buty', $tree[1]->children[0]->path);
        self::assertSame('buty', $tree[1]->children[0]->children[0]->slug);
        self::assertSame('turystyka/buty/buty', $tree[1]->children[0]->children[0]->path);
    }

    public function testBuildsTreeRegardlessOfRepositoryOrder(): void
    {
        $sport = Category::createRoot(CategoryId::fromString('00000000-0000-4000-8000-000000000001'), CategoryName::of('Sport'), new CategorySlug('sport'));
        $running = Category::createChild(CategoryId::fromString('00000000-0000-4000-8000-000000000002'), CategoryName::of('Bieganie'), new CategorySlug('bieganie'), $sport);
        $shoes = Category::createChild(CategoryId::fromString('00000000-0000-4000-8000-000000000003'), CategoryName::of('Buty'), new CategorySlug('buty'), $running);
        foreach ([$shoes, $running, $sport] as $category) {
            $this->categories->save($category);
        }

        $tree = ($this->handler)(new GetCategoryTree());

        self::assertCount(1, $tree);
        self::assertSame('Sport', $tree[0]->name);
        self::assertSame('Bieganie', $tree[0]->children[0]->name);
        self::assertSame('Buty', $tree[0]->children[0]->children[0]->name);
    }

    public function testSiblingsAreSortedWithPolishCollationAtEveryLevel(): void
    {
        $zimowe = $this->root('00000000-0000-4000-8000-000000000001', 'Zimowe');
        $this->root('00000000-0000-4000-8000-000000000002', 'Łyżwy');
        $this->root('00000000-0000-4000-8000-000000000003', 'Lody');
        $this->root('00000000-0000-4000-8000-000000000004', 'Akcesoria');
        $this->child('00000000-0000-4000-8000-000000000005', 'Żel', $zimowe);
        $this->child('00000000-0000-4000-8000-000000000006', 'Łopaty', $zimowe);
        $this->child('00000000-0000-4000-8000-000000000007', 'Lampy', $zimowe);
        $this->child('00000000-0000-4000-8000-000000000008', 'Zegary', $zimowe);

        $tree = ($this->handler)(new GetCategoryTree());

        self::assertSame(['Akcesoria', 'Lody', 'Łyżwy', 'Zimowe'], array_map(static fn (CategoryNode $node): string => $node->name, $tree));
        $winter = $tree[3];
        self::assertSame(['Lampy', 'Łopaty', 'Zegary', 'Żel'], array_map(static fn (CategoryNode $node): string => $node->name, $winter->children));
    }

    public function testPolishTailoringDiffersFromRootCollation(): void
    {
        $this->root('00000000-0000-4000-8000-000000000001', 'Śruby');
        $this->root('00000000-0000-4000-8000-000000000002', 'Szafy');
        $this->root('00000000-0000-4000-8000-000000000003', 'Sanki');
        $this->root('00000000-0000-4000-8000-000000000004', 'Ćwiczenia');
        $this->root('00000000-0000-4000-8000-000000000005', 'Czapki');
        $this->root('00000000-0000-4000-8000-000000000006', 'Cegły');

        $tree = ($this->handler)(new GetCategoryTree());

        // Root/en_US collation would give Cegły, Ćwiczenia, Czapki, Sanki, Śruby, Szafy.
        self::assertSame(
            ['Cegły', 'Czapki', 'Ćwiczenia', 'Sanki', 'Szafy', 'Śruby'],
            array_map(static fn (CategoryNode $node): string => $node->name, $tree),
        );
    }

    public function testSiblingsWithTheSameNameKeepAStableOrderById(): void
    {
        $this->root('00000000-0000-4000-8000-00000000000b', 'Buty');
        $this->root('00000000-0000-4000-8000-00000000000a', 'Buty');

        $tree = ($this->handler)(new GetCategoryTree());

        self::assertSame(
            ['00000000-0000-4000-8000-00000000000a', '00000000-0000-4000-8000-00000000000b'],
            array_map(static fn (CategoryNode $node): string => $node->id, $tree),
        );
    }

    /**
     * The order a staff member set (position) comes first; name and id only order equal positions, so with all
     * positions 0 (after the migration and the seed) the order is the alphabetical one.
     */
    public function testThePositionOrdersTheSiblingsBeforeTheName(): void
    {
        $this->root('00000000-0000-4000-8000-000000000001', 'Akcesoria', 'akcesoria', 1);
        $sport = $this->root('00000000-0000-4000-8000-000000000002', 'Sport', 'sport', 0);
        $this->root('00000000-0000-4000-8000-000000000003', 'Bieganie', 'bieganie', 1);
        $this->child('00000000-0000-4000-8000-000000000004', 'Zima', $sport, 'zima', 0);
        $this->child('00000000-0000-4000-8000-000000000005', 'Lato', $sport, 'lato', 3);

        $tree = ($this->handler)(new GetCategoryTree());

        self::assertSame(['sport', 'akcesoria', 'bieganie'], array_map(static fn (CategoryNode $node): string => $node->slug, $tree));
        self::assertSame(['zima', 'lato'], array_map(static fn (CategoryNode $node): string => $node->slug, $tree[0]->children));
    }

    private function root(string $id, string $name, ?string $slug = null, int $position = 0): Category
    {
        $category = Category::createRoot(CategoryId::fromString($id), CategoryName::of($name), new CategorySlug($slug ?? $id), $position);
        $this->categories->save($category);

        return $category;
    }

    private function child(string $id, string $name, Category $parent, ?string $slug = null, int $position = 0): Category
    {
        $category = Category::createChild(CategoryId::fromString($id), CategoryName::of($name), new CategorySlug($slug ?? $id), $parent, $position);
        $this->categories->save($category);

        return $category;
    }
}
