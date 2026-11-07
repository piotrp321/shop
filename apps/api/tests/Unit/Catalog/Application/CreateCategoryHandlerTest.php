<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateCategoryHandler;
use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Shared\Domain\Exception\Conflict;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use PHPUnit\Framework\TestCase;

final class CreateCategoryHandlerTest extends TestCase
{
    private const string ROOT_ID = '00000000-0000-4000-8000-000000000001';
    private const string CHILD_ID = '00000000-0000-4000-8000-000000000002';
    private const string OTHER_ROOT_ID = '00000000-0000-4000-8000-000000000003';
    private const string OTHER_CHILD_ID = '00000000-0000-4000-8000-000000000004';

    private InMemoryCategoryRepository $categories;
    private CreateCategoryHandler $handler;

    protected function setUp(): void
    {
        $this->categories = new InMemoryCategoryRepository();
        $this->handler = new CreateCategoryHandler($this->categories);
    }

    public function testRootCategoryIsSaved(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', 'sport', null));

        $root = $this->categories->get(CategoryId::fromString(self::ROOT_ID));
        self::assertSame('Sport', $root->name()->value());
        self::assertSame('sport', $root->slug()->value());
        self::assertNull($root->parentId());
        self::assertSame('/'.self::ROOT_ID.'/', $root->path()->toString());
    }

    public function testChildPathIsParentPathPlusOwnId(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', 'sport', null));

        ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', 'bieganie', self::ROOT_ID));

        $child = $this->categories->get(CategoryId::fromString(self::CHILD_ID));
        self::assertSame(self::ROOT_ID, $child->parentId()?->toString());
        self::assertSame('bieganie', $child->slug()->value());
        self::assertSame('/'.self::ROOT_ID.'/'.self::CHILD_ID.'/', $child->path()->toString());
    }

    public function testSlugTakenByAnotherRootThrowsCategorySlugTakenAndSavesNothing(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', 'sport', null));

        try {
            ($this->handler)(new CreateCategory(self::OTHER_ROOT_ID, 'Sport 2', 'sport', null));
            self::fail('Expected CategorySlugTaken.');
        } catch (CategorySlugTaken $e) {
            self::assertContains(Conflict::class, class_implements($e));
            self::assertStringNotContainsString('sport', $e->getMessage());
        }

        self::assertSame(1, $this->categories->count());
    }

    public function testSlugTakenByASiblingThrowsCategorySlugTakenAndSavesNothing(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', 'sport', null));
        ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', 'bieganie', self::ROOT_ID));

        try {
            ($this->handler)(new CreateCategory(self::OTHER_CHILD_ID, 'Bieg', 'bieganie', self::ROOT_ID));
            self::fail('Expected CategorySlugTaken.');
        } catch (CategorySlugTaken) {
        }

        self::assertSame(2, $this->categories->count());
    }

    public function testTheSameSlugIsAllowedUnderAnotherParentAndBetweenLevels(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Bieganie', 'bieganie', null));
        ($this->handler)(new CreateCategory(self::OTHER_ROOT_ID, 'Turystyka', 'turystyka', null));
        // A child may share the slug of its parent (another level) and of a child of another parent.
        ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', 'bieganie', self::ROOT_ID));
        ($this->handler)(new CreateCategory(self::OTHER_CHILD_ID, 'Bieganie', 'bieganie', self::OTHER_ROOT_ID));

        self::assertSame(4, $this->categories->count());
        self::assertSame('bieganie', $this->categories->get(CategoryId::fromString(self::OTHER_CHILD_ID))->slug()->value());
    }

    public function testInvalidSlugIsRejectedBeforeAnythingIsSaved(): void
    {
        try {
            ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', 'Sport', null));
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('CategorySlug is invalid.', $e->getMessage());
        }

        self::assertSame(0, $this->categories->count());
    }

    public function testUnknownParentThrowsCategoryNotFoundAndSavesNothing(): void
    {
        try {
            ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', 'bieganie', self::ROOT_ID));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
        }

        self::assertSame(0, $this->categories->count());
    }

    public function testExistingIdThrowsCategoryAlreadyExistsAndKeepsTheSavedCategory(): void
    {
        ($this->handler)(new CreateCategory(self::ROOT_ID, 'Sport', 'sport', null));
        ($this->handler)(new CreateCategory(self::CHILD_ID, 'Bieganie', 'bieganie', self::ROOT_ID));

        try {
            ($this->handler)(new CreateCategory(self::CHILD_ID, 'Inna nazwa', 'inna-nazwa', null));
            self::fail('Expected CategoryAlreadyExists.');
        } catch (CategoryAlreadyExists) {
        }

        $kept = $this->categories->get(CategoryId::fromString(self::CHILD_ID));
        self::assertSame('Bieganie', $kept->name()->value());
        self::assertSame(self::ROOT_ID, $kept->parentId()?->toString());
        self::assertSame(2, $this->categories->count());
    }
}
