<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
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
        $child = CategoryFactory::createOne(['parent' => $root]);
        $this->repository->save($root);
        $this->repository->save($child);
        $this->entityManager->clear();

        $loaded = $this->repository->get($child->id());

        self::assertTrue($loaded->id()->equals($child->id()));
        self::assertSame($child->name()->value(), $loaded->name()->value());
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
            'path' => $category->path()->toString(),
        ]);

        try {
            $this->repository->save($category);
            self::fail('Expected CategoryAlreadyExists.');
        } catch (CategoryAlreadyExists) {
        }

        self::assertSame('Competing', $this->committedValue($this->entityManager, 'SELECT name FROM catalog_category WHERE id = ?', $category->id()->toString()));
    }
}
