<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The outbox row is written in the transaction of the aggregate: both are committed or neither is.
 */
final class OutboxAtomicityTest extends MessagingKernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->category = CategoryFactory::createOne();
        (new DoctrineCategoryRepository($entityManager))->save($this->category);
    }

    public function testRollbackLeavesNeitherTheProductNorItsEvent(): void
    {
        $failure = null;
        try {
            $this->entityManager->wrapInTransaction(function (): void {
                $this->saveProductAndPublishItsEvents();

                throw new \RuntimeException('Failure before commit.');
            });
        } catch (\RuntimeException $e) {
            $failure = $e;
        }

        self::assertSame('Failure before commit.', $failure->getMessage());

        self::assertSame(0, $this->products());
        self::assertSame(0, $this->rows('outbox'));
        self::assertSame(0, $this->eventsInQueue());
    }

    public function testCommitKeepsTheProductAndItsEventInTheOutbox(): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $this->saveProductAndPublishItsEvents();
        });

        self::assertSame(1, $this->products());
        self::assertSame(1, $this->rows('outbox'));
        // Not relayed yet: only the worker sends it to RabbitMQ.
        self::assertSame(0, $this->eventsInQueue());
    }

    private function saveProductAndPublishItsEvents(): void
    {
        $product = ProductFactory::createOne(['mainCategory' => $this->category]);
        (new DoctrineProductRepository($this->entityManager))->save($product);
        $this->publisher()->publish(...$product->releaseEvents());
    }

    private function products(): int
    {
        // The same connection as the transaction, so the count sees exactly what it left behind.
        $count = $this->connection()->fetchOne('SELECT count(*) FROM catalog_product');
        self::assertIsInt($count);

        return $count;
    }
}
