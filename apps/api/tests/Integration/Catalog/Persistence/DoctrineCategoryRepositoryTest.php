<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class DoctrineCategoryRepositoryTest extends KernelTestCase
{
    use CommitsRowsOnSecondConnection;
    use Factories;

    private EntityManagerInterface $entityManager;
    private DoctrineCategoryRepository $repository;

    protected function setUp(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->repository = new DoctrineCategoryRepository($this->entityManager);
    }

    public function testSavedChildIsReadBackWithPathAndParent(): void
    {
        $root = CategoryFactory::createOne();
        $child = CategoryFactory::createOne(['parent' => $root, 'slug' => 'buty-szosowe']);
        $this->repository->save($root);
        $this->repository->save($child);
        $this->entityManager->clear();

        $loaded = $this->repository->get($child->id());

        self::assertTrue($loaded->id()->equals($child->id()));
        self::assertSame($child->name()->value(), $loaded->name()->value());
        self::assertSame('buty-szosowe', $loaded->slug()->value());
        self::assertSame($root->id()->toString(), $loaded->parentId()?->toString());
        self::assertSame($child->path()->toString(), $loaded->path()->toString());
    }

    public function testSavedRootHasNoParent(): void
    {
        $root = CategoryFactory::createOne();
        $this->repository->save($root);
        $this->entityManager->clear();

        self::assertNull($this->repository->get($root->id())->parentId());
    }

    public function testGetOfUnknownIdThrowsCategoryNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);

        $this->repository->get(CategoryId::fromString('99999999-9999-4999-8999-999999999999'));
    }

    public function testAllAndCountReturnSavedCategories(): void
    {
        $root = CategoryFactory::createOne();
        $this->repository->save($root);
        $this->repository->save(CategoryFactory::createOne(['parent' => $root]));
        $this->entityManager->clear();

        self::assertSame(2, $this->repository->count());
        self::assertCount(2, $this->repository->all());
    }

    public function testPathIndexUsesTextPatternOps(): void
    {
        $definitions = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'catalog_category'",
        );

        self::assertNotEmpty(array_filter(
            $definitions,
            static fn (mixed $definition): bool => \is_string($definition) && str_contains($definition, '(path text_pattern_ops)'),
        ));
    }

    public function testParentMustExist(): void
    {
        $orphan = CategoryFactory::createOne(['parent' => CategoryFactory::createOne()]);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->repository->save($orphan);
    }

    public function testPrimaryKeyViolationOnInsertThrowsCategoryAlreadyExists(): void
    {
        $category = CategoryFactory::createOne();
        // The competing request commits the same id after this repository read the id and found nothing.
        $this->commitRowBeforeNextInsert($this->entityManager, 'catalog_category', [
            'id' => $category->id()->toString(),
            'name' => 'Competing',
            'slug' => 'competing',
            'path' => $category->path()->toString(),
        ]);

        try {
            $this->repository->save($category);
            self::fail('Expected CategoryAlreadyExists.');
        } catch (CategoryAlreadyExists) {
        }

        self::assertSame('Competing', $this->committedValue($this->entityManager, 'SELECT name FROM catalog_category WHERE id = ?', $category->id()->toString()));
    }

    public function testSlugTakenAmongSiblingsLooksOnlyAtTheChildrenOfTheParent(): void
    {
        $sport = CategoryFactory::createOne(['slug' => 'sport']);
        $other = CategoryFactory::createOne(['slug' => 'turystyka']);
        $this->repository->save($sport);
        $this->repository->save($other);
        $this->repository->save(CategoryFactory::createOne(['parent' => $sport, 'slug' => 'buty']));

        self::assertTrue($this->repository->slugTakenAmongSiblings(null, new CategorySlug('sport')));
        self::assertFalse($this->repository->slugTakenAmongSiblings(null, new CategorySlug('buty')));
        self::assertTrue($this->repository->slugTakenAmongSiblings($sport->id(), new CategorySlug('buty')));
        self::assertFalse($this->repository->slugTakenAmongSiblings($sport->id(), new CategorySlug('sport')));
        self::assertFalse($this->repository->slugTakenAmongSiblings($other->id(), new CategorySlug('buty')));
    }

    public function testSiblingSlugViolationOnInsertThrowsCategorySlugTaken(): void
    {
        $root = CategoryFactory::createOne();
        $child = CategoryFactory::createOne(['parent' => $root, 'slug' => 'buty']);
        $competingId = '99999999-9999-4999-8999-999999999999';
        // The parent is committed on the second connection, so the competing sibling can refer to it. Then the
        // competing request commits a sibling with the same slug after the handler found the slug free.
        $this->commitRow($this->entityManager, 'catalog_category', [
            'id' => $root->id()->toString(),
            'name' => 'Committed parent',
            'slug' => $root->slug()->value(),
            'path' => $root->path()->toString(),
        ]);
        $this->commitRowBeforeNextInsert($this->entityManager, 'catalog_category', [
            'id' => $competingId,
            'name' => 'Competing',
            'slug' => 'buty',
            'parent_id' => $root->id()->toString(),
            'path' => $root->path()->toString().$competingId.'/',
        ]);

        $this->expectException(CategorySlugTaken::class);

        $this->repository->save($child);
    }

    public function testDirectInsertOfASecondRootWithTheSameSlugIsRejected(): void
    {
        $this->insertRow('00000000-0000-4000-8000-000000000001', null, 'sport');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertRow('00000000-0000-4000-8000-000000000002', null, 'sport');
    }

    public function testDirectInsertOfASiblingWithTheSameSlugIsRejected(): void
    {
        $this->insertRow('00000000-0000-4000-8000-000000000001', null, 'sport');
        $this->insertRow('00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000001', 'buty');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertRow('00000000-0000-4000-8000-000000000003', '00000000-0000-4000-8000-000000000001', 'buty');
    }

    public function testDirectInsertOfTheSameSlugUnderAnotherParentOrLevelIsAccepted(): void
    {
        $this->insertRow('00000000-0000-4000-8000-000000000001', null, 'buty');
        $this->insertRow('00000000-0000-4000-8000-000000000002', null, 'sport');
        $this->insertRow('00000000-0000-4000-8000-000000000003', '00000000-0000-4000-8000-000000000001', 'buty');
        $this->insertRow('00000000-0000-4000-8000-000000000004', '00000000-0000-4000-8000-000000000002', 'buty');

        self::assertEquals(3, $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM catalog_category WHERE slug = 'buty'"));
    }

    private function insertRow(string $id, ?string $parentId, string $slug): void
    {
        $path = null === $parentId ? '/'.$id.'/' : '/'.$parentId.'/'.$id.'/';
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO catalog_category (id, name, slug, parent_id, path) VALUES (?, ?, ?, ?, ?)',
            [$id, 'Category '.$id, $slug, $parentId, $path],
        );
    }
}
