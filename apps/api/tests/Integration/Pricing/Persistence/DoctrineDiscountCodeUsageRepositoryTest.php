<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\OrderId;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeUsageRepository;
use App\Tests\Factory\DiscountCodeFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * DoctrineDiscountCodeUsageRepository on PostgreSQL (shop_test, rolled back after each test).
 */
final class DoctrineDiscountCodeUsageRepositoryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineDiscountCodeUsageRepository $usages;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->usages = new DoctrineDiscountCodeUsageRepository($entityManager);
    }

    public function testAUsageIsStoredInItsColumns(): void
    {
        $code = $this->storedCode();
        $usage = DiscountCodeUsage::record(self::usageId(), $code->id(), $orderId = self::orderId(), new \DateTimeImmutable('2030-01-01T10:00:00Z'));

        $this->usages->add($usage);

        $this->connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");
        self::assertSame(
            ['code_id' => $code->id()->toString(), 'order_id' => $orderId->toString(), 'used_at' => '2030-01-01 10:00:00+00', 'released_at' => null],
            $this->connection->fetchAssociative('SELECT code_id, order_id, used_at, released_at FROM pricing_discount_code_usage WHERE id = ?', [$usage->id()->toString()]) ?: null,
        );
    }

    public function testActiveUsesAreTheUsagesOfTheCodeNotReleased(): void
    {
        $code = $this->storedCode();
        $other = $this->storedCode();
        for ($i = 0; $i < 3; ++$i) {
            $this->usages->add(DiscountCodeUsage::record(self::usageId(), $code->id(), self::orderId(), new \DateTimeImmutable()));
        }
        $this->usages->add(DiscountCodeUsage::record(self::usageId(), $other->id(), self::orderId(), new \DateTimeImmutable()));
        $this->usages->add(DiscountCodeUsage::reconstitute(self::usageId(), $code->id(), self::orderId(), new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable()));

        self::assertSame(3, $this->usages->activeUsesOf($code->id()));
        self::assertSame(1, $this->usages->activeUsesOf($other->id()));
        self::assertSame(0, $this->usages->activeUsesOf(DiscountCodeId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testActiveUsesAreCountedWithThePartialIndex(): void
    {
        $code = $this->storedCode();
        $this->usages->add(DiscountCodeUsage::record(self::usageId(), $code->id(), self::orderId(), new \DateTimeImmutable()));
        // Too few rows for the planner to prefer an index on its own.
        $this->connection->executeStatement('SET LOCAL enable_seqscan = off');

        /** @var list<string> $lines */
        $lines = $this->connection->fetchFirstColumn('EXPLAIN '.DoctrineDiscountCodeUsageRepository::ACTIVE_USES, ['codeId' => $code->id()->toString()]);
        $plan = implode("\n", $lines);

        self::assertStringContainsString('pricing_discount_code_usage_active_idx', $plan);
    }

    public function testAnOrderHasAtMostOneUsage(): void
    {
        $code = $this->storedCode();
        $orderId = self::orderId();
        $this->usages->add(DiscountCodeUsage::record(self::usageId(), $code->id(), $orderId, new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->usages->add(DiscountCodeUsage::record(self::usageId(), $this->storedCode()->id(), $orderId, new \DateTimeImmutable()));
    }

    public function testAUsageOfAnUnknownCodeIsRefused(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->usages->add(DiscountCodeUsage::record(self::usageId(), DiscountCodeId::fromString(Uuid::v7()->toRfc4122()), self::orderId(), new \DateTimeImmutable()));
    }

    private function storedCode(): DiscountCode
    {
        $code = DiscountCodeFactory::createOne();
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return $code;
    }

    private static function usageId(): DiscountCodeUsageId
    {
        return DiscountCodeUsageId::fromString(Uuid::v7()->toRfc4122());
    }

    private static function orderId(): OrderId
    {
        return OrderId::fromString(Uuid::v7()->toRfc4122());
    }
}
