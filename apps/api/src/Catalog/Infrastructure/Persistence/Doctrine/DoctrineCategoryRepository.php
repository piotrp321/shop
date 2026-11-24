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
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\CategoryRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Categories through their records, by the general rule. One exception (apps/api/CLAUDE.md): the paths of a moved
 * subtree change with one DBAL UPDATE, and the records of the identity map are refreshed after it.
 */
final readonly class DoctrineCategoryRepository implements CategoryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Category $category): void
    {
        $this->saveAll([$category]);
    }

    /**
     * @throws CategoryAlreadyExists when a concurrent request inserted a category with this id after the read below
     * @throws CategorySlugTaken     when a concurrent request inserted a sibling with this slug after the handler's check
     */
    public function saveAll(array $categories): void
    {
        foreach ($categories as $category) {
            $this->entityManager->persist($this->record($category));
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), CategoryRecord::PRIMARY_KEY_CONSTRAINT)) {
                throw new CategoryAlreadyExists();
            }
            if (str_contains($e->getMessage(), CategoryRecord::SIBLING_SLUG_INDEX) || str_contains($e->getMessage(), CategoryRecord::ROOT_SLUG_INDEX)) {
                throw new CategorySlugTaken();
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

    /**
     * Refreshes the records the identity map already holds: a change of the tree reads under the lock what the
     * others committed, also in a process that read the tree before.
     */
    public function all(): array
    {
        /** @var list<CategoryRecord> $records */
        $records = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(CategoryRecord::class, 'c')
            ->orderBy('c.path', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_map($this->toDomain(...), $records);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(CategoryRecord::class)->count();
    }

    /**
     * One UPDATE of the category and its whole subtree on the path index (text_pattern_ops): a path has only hex
     * digits, dashes and slashes, so LIKE needs no escaping. The records of the subtree that the identity map holds
     * are refreshed, so the next flush does not write their old paths back.
     */
    public function movePaths(CategoryPath $from, CategoryPath $to): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE catalog_category SET path = :to || substr(path, length(:from) + 1) WHERE path LIKE :from || '%'",
            ['from' => $from->toString(), 'to' => $to->toString()],
        );

        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[CategoryRecord::class] ?? [] as $record) {
            if ($record instanceof CategoryRecord && str_starts_with($record->path, $from->toString())) {
                $this->entityManager->refresh($record);
            }
        }
    }

    public function lock(CategoryId $id): void
    {
        $this->entityManager->getConnection()->executeQuery('SELECT 1 FROM catalog_category WHERE id = ? FOR UPDATE', [$id->toString()]);
    }

    public function lockForAssignment(CategoryId $id): void
    {
        $found = $this->entityManager->getConnection()->fetchOne('SELECT id FROM catalog_category WHERE id = ? FOR KEY SHARE', [$id->toString()]);
        if (false === $found) {
            throw new CategoryNotFound();
        }
    }

    public function remove(Category $category): void
    {
        $record = $this->entityManager->find(CategoryRecord::class, $category->id()->toString());
        if (null === $record) {
            return;
        }
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    private function record(Category $category): CategoryRecord
    {
        $id = $category->id()->toString();
        $record = $this->entityManager->find(CategoryRecord::class, $id) ?? new CategoryRecord($id);
        $parentId = $category->parentId();
        $record->parent = null === $parentId
            ? null
            : $this->entityManager->getReference(CategoryRecord::class, $parentId->toString());
        $record->name = $category->name()->value();
        $record->slug = $category->slug()->value();
        $record->path = $category->path()->toString();
        $record->position = $category->position();
        $record->version = $category->version();

        return $record;
    }

    private function toDomain(CategoryRecord $record): Category
    {
        return Category::reconstitute(
            CategoryId::fromString($record->id),
            CategoryName::of($record->name),
            new CategorySlug($record->slug),
            null === $record->parent ? null : CategoryId::fromString($record->parent->id),
            CategoryPath::fromString($record->path),
            $record->position,
            $record->version,
        );
    }
}
