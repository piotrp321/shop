<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use App\Pricing\Application\Port\ListedDiscountCode;
use App\Pricing\Application\Query\DiscountCodeCursor;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\OrderId;
use App\Pricing\Infrastructure\Persistence\Doctrine\DbalDiscountCodeReader;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeUsageRepository;
use App\Tests\Factory\DiscountCodeFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * DbalDiscountCodeReader on PostgreSQL (shop_test, rolled back after each test).
 */
final class DbalDiscountCodeReaderTest extends KernelTestCase
{
    use Factories;

    private const string NOW = '2030-06-01T12:00:00Z';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DbalDiscountCodeReader $reader;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('DELETE FROM pricing_discount_code_usage');
        $this->connection->executeStatement('DELETE FROM pricing_discount_code');
        $this->reader = new DbalDiscountCodeReader($this->connection);
    }

    public function testACodeIsListedWithItsValuesAndActiveUses(): void
    {
        $code = $this->stored([
            'code' => 'MINUS50', 'kind' => DiscountKind::Amount, 'value' => 5000, 'minimumOrderGross' => 30000, 'usageLimit' => 10,
            'startsAt' => '2030-05-01T00:00:00Z', 'endsAt' => '2030-07-01T00:00:00Z', 'createdAt' => '2030-04-01T10:00:00Z',
        ]);
        $this->use($code, 2, 1);

        self::assertEquals(
            [new ListedDiscountCode(
                $code->id()->toString(), 'MINUS50', DiscountKind::Amount, 5000, 30000,
                new \DateTimeImmutable('2030-05-01T00:00:00Z'), new \DateTimeImmutable('2030-07-01T00:00:00Z'),
                10, true, 2, 1, new \DateTimeImmutable('2030-04-01T10:00:00Z'),
            )],
            $this->reader->page(null, null, new \DateTimeImmutable(self::NOW), null, 10),
        );
    }

    public function testAPercentCodeWithoutEndMinimumAndLimitIsListedWithNulls(): void
    {
        $code = $this->stored(['code' => 'WITAJ10', 'value' => 10, 'createdAt' => '2030-04-01T10:00:00Z', 'startsAt' => '2030-05-01T00:00:00Z']);

        [$listed] = $this->reader->page(null, null, new \DateTimeImmutable(self::NOW), null, 10);

        self::assertSame([$code->id()->toString(), DiscountKind::Percent, 10, null, null, null, 0], [$listed->id, $listed->kind, $listed->value, $listed->minimumOrderGross, $listed->endsAt, $listed->usageLimit, $listed->activeUses]);
    }

    public function testTheListIsNewestFirstThenByIdAndPagesAfterTheCursor(): void
    {
        $oldest = $this->stored(['createdAt' => '2030-04-01T10:00:00Z']);
        $sameSecond = [$this->stored(['createdAt' => '2030-04-02T10:00:00Z']), $this->stored(['createdAt' => '2030-04-02T10:00:00Z'])];
        usort($sameSecond, static fn (DiscountCode $a, DiscountCode $b): int => strcmp($a->id()->toString(), $b->id()->toString()));
        $newest = $this->stored(['createdAt' => '2030-04-03T10:00:00Z']);
        $now = new \DateTimeImmutable(self::NOW);

        $first = $this->reader->page(null, null, $now, null, 2);
        self::assertSame([$newest->id()->toString(), $sameSecond[0]->id()->toString()], self::ids($first));

        $second = $this->reader->page(null, null, $now, DiscountCodeCursor::after($first[1]), 2);
        self::assertSame([$sameSecond[1]->id()->toString(), $oldest->id()->toString()], self::ids($second));
    }

    public function testTheStatusFilterIsTheStatusAtNow(): void
    {
        $disabled = $this->stored(['enabled' => false, 'startsAt' => '2030-05-01T00:00:00Z']);
        $planned = $this->stored(['startsAt' => '2030-06-01T12:00:01Z']);
        $active = $this->stored(['startsAt' => '2030-06-01T12:00:00Z', 'endsAt' => '2030-06-01T12:00:01Z']);
        $activeForever = $this->stored(['startsAt' => '2030-05-01T00:00:00Z']);
        $expired = $this->stored(['startsAt' => '2030-05-01T00:00:00Z', 'endsAt' => '2030-06-01T12:00:00Z']);
        $now = new \DateTimeImmutable(self::NOW);

        $ids = fn (DiscountCodeStatus $status): array => self::sorted(self::ids($this->reader->page($status, null, $now, null, 10)));

        self::assertSame([$disabled->id()->toString()], $ids(DiscountCodeStatus::Disabled));
        self::assertSame([$planned->id()->toString()], $ids(DiscountCodeStatus::Planned));
        self::assertSame(self::sorted([$active->id()->toString(), $activeForever->id()->toString()]), $ids(DiscountCodeStatus::Active));
        self::assertSame([$expired->id()->toString()], $ids(DiscountCodeStatus::Expired));
    }

    public function testTheCodePrefixMatchesTheBeginningOfTheText(): void
    {
        $witaj = $this->stored(['code' => 'WITAJ10']);
        $wit = $this->stored(['code' => 'WIT-1']);
        $this->stored(['code' => 'AWIT']);
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(self::sorted([$witaj->id()->toString(), $wit->id()->toString()]), self::sorted(self::ids($this->reader->page(null, 'WIT', $now, null, 10))));
        self::assertSame([$wit->id()->toString()], self::ids($this->reader->page(null, 'WIT-', $now, null, 10)));
        self::assertSame([$witaj->id()->toString()], self::ids($this->reader->page(DiscountCodeStatus::Active, 'WITAJ10', $now, null, 10)));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function stored(array $attributes): DiscountCode
    {
        $code = DiscountCodeFactory::createOne($attributes);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return $code;
    }

    private function use(DiscountCode $code, int $active, int $released): void
    {
        $usages = new DoctrineDiscountCodeUsageRepository($this->entityManager);
        for ($i = 0; $i < $active + $released; ++$i) {
            $usages->add(DiscountCodeUsage::reconstitute(
                DiscountCodeUsageId::fromString(Uuid::v7()->toRfc4122()),
                $code->id(),
                OrderId::fromString(Uuid::v7()->toRfc4122()),
                new \DateTimeImmutable('2030-05-02T00:00:00Z'),
                $i < $active ? null : new \DateTimeImmutable('2030-05-02T00:35:00Z'),
            ));
        }
    }

    /**
     * @param list<ListedDiscountCode> $codes
     *
     * @return list<string>
     */
    private static function ids(array $codes): array
    {
        return array_map(static fn (ListedDiscountCode $code): string => $code->id, $codes);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
