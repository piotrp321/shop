<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateCategoryHandler;
use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use PHPUnit\Framework\TestCase;

final class CreateCategoryHandlerTest extends TestCase
{
    private const string ROOT_ID = '00000000-0000-4000-8000-000000000001';
    private const string CHILD_ID = '00000000-0000-4000-8000-000000000002';

    private InMemoryCategoryRepository $categories;
    private CreateCategoryHandler $handler;

    protected function setUp(): void
    {
        $this->categories = new InMemoryCategoryRepository();
        $this->handler = new CreateCategoryHandler($this->categories);
    }

    public function testRootCategoryIsSaved(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', null));

        $root = $this->categories->get(CategoryId::fromString(self::ROOT_ID));
        self::assertSame('Sport', $root->name()->value());
        self::assertNull($root->parentId());
        self::assertSame('/'.self::ROOT_ID.'/', $root->path()->toString());
    }

    public function testChildPathIsParentPathPlusOwnId(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', null));

        ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', self::ROOT_ID));

        $child = $this->categories->get(CategoryId::fromString(self::CHILD_ID));
        self::assertSame(self::ROOT_ID, $child->parentId()?->toString());
        self::assertSame('/'.self::ROOT_ID.'/'.self::CHILD_ID.'/', $child->path()->toString());
    }

    public function testUnknownParentThrowsCategoryNotFoundAndSavesNothing(): void
    {
        try {
            ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', self::ROOT_ID));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
        }

        self::assertSame(0, $this->categories->count());
    }

    public function testExistingIdThrowsCategoryAlreadyExistsAndKeepsTheSavedCategory(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', null));
        ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', self::ROOT_ID));

        try {
            ($this->handler)(new CreateCategory(self::CHILD_ID, 'Inna nazwa', null));
            self::fail('Expected CategoryAlreadyExists.');
        } catch (CategoryAlreadyExists) {
        }

        $kept = $this->categories->get(CategoryId::fromString(self::CHILD_ID));
        self::assertSame('Bieganie', $kept->name()->value());
        self::assertSame(self::ROOT_ID, $kept->parentId()?->toString());
        self::assertSame(2, $this->categories->count());
    }
}
