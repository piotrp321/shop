<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Concurrency;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A product write holds a key share lock on the row of each of its categories until the end of its transaction, so the
 * delete of a category (FOR UPDATE on the row) cannot commit between the check of the category and the insert of the
 * product (which would end in a foreign key violation, a 500). The category commits through a second connection, so it
 * is deleted after the test.
 */
final class CategoryAssignmentLockTest extends KernelTestCase
{
    private Connection $kernelConnection;
    private Connection $control;
    private DoctrineCategoryRepository $categories;
    private ?string $categoryId = null;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->kernelConnection = $entityManager->getConnection();
        $this->categories = new DoctrineCategoryRepository($entityManager);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
    }

    protected function tearDown(): void
    {
        if (null !== $this->categoryId) {
            $this->control->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$this->categoryId]);
        }
        $this->control->close();
        parent::tearDown();
    }

    public function testAssigningToACategoryBlocksItsDeleteUntilTheTransactionEnds(): void
    {
        $id = $this->committedCategory();

        $this->kernelConnection->beginTransaction();
        try {
            $this->categories->lockForAssignment(CategoryId::fromString($id));

            try {
                $this->control->fetchOne('SELECT id FROM catalog_category WHERE id = ? FOR UPDATE NOWAIT', [$id]);
                self::fail('The row of the category is not locked.');
            } catch (DriverException $e) {
                // The delete (FOR UPDATE) has to wait for the product write: 55P03 is lock_not_available.
                self::assertSame('55P03', $e->getSQLState());
            }
        } finally {
            $this->kernelConnection->rollBack();
        }

        self::assertSame($id, $this->control->fetchOne('SELECT id FROM catalog_category WHERE id = ? FOR UPDATE NOWAIT', [$id]));
    }

    public function testTwoAssignmentsToTheSameCategoryDoNotWaitForEachOther(): void
    {
        $id = $this->committedCategory();

        $this->kernelConnection->beginTransaction();
        $this->control->beginTransaction();
        try {
            $this->categories->lockForAssignment(CategoryId::fromString($id));

            self::assertSame($id, $this->control->fetchOne('SELECT id FROM catalog_category WHERE id = ? FOR KEY SHARE NOWAIT', [$id]));
        } finally {
            $this->control->rollBack();
            $this->kernelConnection->rollBack();
        }
    }

    public function testAssigningToACategoryThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);

        $this->categories->lockForAssignment(CategoryId::fromString(Uuid::v7()->toRfc4122()));
    }

    private function committedCategory(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->control->executeStatement(
            'INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)',
            [$id, 'Kategoria', 'k-'.substr($id, -12), '/'.$id.'/'],
        );
        $this->categoryId = $id;

        return $id;
    }
}
