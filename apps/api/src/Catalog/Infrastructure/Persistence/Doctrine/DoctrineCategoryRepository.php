<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\CategoryRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCategoryRepository implements CategoryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws CategoryAlreadyExists when a concurrent request inserted a category with this id after the read below
     */
    public function save(Category $category): void
    {
        $id = $category->id()->toString();
        $record = $this->entityManager->find(CategoryRecord::class, $id) ?? new CategoryRecord($id);
        $parentId = $category->parentId();
        $record->parent = null === $parentId
            ? null
            : $this->entityManager->getReference(CategoryRecord::class, $parentId->toString());
        $record->name = $category->name()->value();
        $record->path = $category->path()->toString();

        try {
            $this->entityManager->persist($record);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), CategoryRecord::PRIMARY_KEY_CONSTRAINT)) {
                throw new CategoryAlreadyExists();
            }

            throw $e;
        }
    }

    public function get(CategoryId $id): Category
    {
        $record = $this->entityManager->find(CategoryRecord::class, $id->toString());
        if (null === $record) {
            throw new CategoryNotFound();
        }

        return $this->toDomain($record);
    }

    public function all(): array
    {
        return array_map(
            $this->toDomain(...),
            $this->entityManager->getRepository(CategoryRecord::class)->findBy([], ['path' => 'ASC']),
        );
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(CategoryRecord::class)->count();
    }

    private function toDomain(CategoryRecord $record): Category
    {
        return Category::reconstitute(
            CategoryId::fromString($record->id),
            new CategoryName($record->name),
            null === $record->parent ? null : CategoryId::fromString($record->parent->id),
            CategoryPath::fromString($record->path),
        );
    }
}
