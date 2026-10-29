<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Query\CategoryNode;
use App\Catalog\Application\Query\GetCategoryTree;
use App\Catalog\Application\Query\GetCategoryTreeHandler;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
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
        $sport = $this->root('00000000-0000-4000-8000-000000000001', 'Sport');
        $running = $this->child('00000000-0000-4000-8000-000000000002', 'Bieganie', $sport);
        $shoes = $this->child('00000000-0000-4000-8000-000000000003', 'Buty', $running);

        $tree = ($this->handler)(new GetCategoryTree());

        self::assertEquals([
            new CategoryNode($sport->id()->toString(), 'Sport', [
                new CategoryNode($running->id()->toString(), 'Bieganie', [
                    new CategoryNode($shoes->id()->toString(), 'Buty', []),
                ]),
            ]),
        ], $tree);
    }

    public function testBuildsTreeRegardlessOfRepositoryOrder(): void
    {
        $sport = Category::createRoot(CategoryId::fromString('00000000-0000-4000-8000-000000000001'), new CategoryName('Sport'));
        $running = Category::createChild(CategoryId::fromString('00000000-0000-4000-8000-000000000002'), new CategoryName('Bieganie'), $sport);
        $shoes = Category::createChild(CategoryId::fromString('00000000-0000-4000-8000-000000000003'), new CategoryName('Buty'), $running);
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

    private function root(string $id, string $name): Category
    {
        $category = Category::createRoot(CategoryId::fromString($id), new CategoryName($name));
        $this->categories->save($category);

        return $category;
    }

    private function child(string $id, string $name, Category $parent): Category
    {
        $category = Category::createChild(CategoryId::fromString($id), new CategoryName($name), $parent);
        $this->categories->save($category);

        return $category;
    }
}
