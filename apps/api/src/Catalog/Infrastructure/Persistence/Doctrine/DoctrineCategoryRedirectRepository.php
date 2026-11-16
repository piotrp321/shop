<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryRedirectRepository;
use App\Catalog\Domain\Category\CategorySlugPath;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\CategoryRedirectRecord;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The redirects through their records. Every write runs under the lock of the tree, so a find before the write sees
 * every redirect another change committed.
 */
final readonly class DoctrineCategoryRedirectRepository implements CategoryRedirectRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(array $redirects): void
    {
        foreach ($redirects as $path => $categoryId) {
            $category = $this->entityManager->getReference(CategoryRecord::class, $categoryId->toString());
            \assert($category instanceof CategoryRecord);
            $record = $this->entityManager->find(CategoryRedirectRecord::class, (string) $path);
            if (null === $record) {
                $this->entityManager->persist(new CategoryRedirectRecord((string) $path, $category));
            } else {
                $record->category = $category;
            }
        }
        $this->entityManager->flush();
    }

    public function forget(array $paths): void
    {
        foreach ($paths as $path) {
            $record = $this->entityManager->find(CategoryRedirectRecord::class, $path);
            if (null !== $record) {
                $this->entityManager->remove($record);
            }
        }
        $this->entityManager->flush();
    }

    public function find(CategorySlugPath $path): ?CategoryId
    {
        $categoryId = $this->entityManager->getConnection()->fetchOne(
            'SELECT category_id FROM catalog_category_redirect WHERE old_path = ?',
            [$path->toString()],
        );

        return \is_string($categoryId) ? CategoryId::fromString($categoryId) : null;
    }
}
