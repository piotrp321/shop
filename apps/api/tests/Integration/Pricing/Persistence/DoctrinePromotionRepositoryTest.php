<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionActor;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrinePromotionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * DoctrinePromotionRepository on PostgreSQL (shop_test, rolled back after each test).
 */
final class DoctrinePromotionRepositoryTest extends KernelTestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03';
    /** The moment the promotions of the test are planned at; their periods are after it. */
    private const string PLANNED_AT = '2030-01-01T00:00:00Z';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrinePromotionRepository $promotions;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->promotions = new DoctrinePromotionRepository($entityManager);
    }

    public function testANewPromotionIsStoredWithItsVariants(): void
    {
        $promotion = $this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::B, self::A], PromotionActor::staff('sub-1', 'Anna'));

        $this->promotions->save($promotion);

        $id = $promotion->id()->toString();
        $this->connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");
        self::assertSame(
            [
                'name' => 'Promocja', 'percent_off' => 20, 'starts_at' => '2030-02-01 10:00:00+00', 'ends_at' => '2030-02-11 10:00:00+00',
                'created_at' => '2030-01-01 00:00:00+00', 'created_by_id' => 'sub-1', 'created_by_name' => 'Anna',
                'updated_at' => '2030-01-01 00:00:00+00', 'updated_by_id' => 'sub-1', 'updated_by_name' => 'Anna', 'version' => 1,
            ],
            $this->connection->fetchAssociative('SELECT name, percent_off, starts_at, ends_at, created_at, created_by_id, created_by_name, updated_at, updated_by_id, updated_by_name, version FROM pricing_promotion WHERE id = ?', [$id]) ?: null,
        );
        self::assertSame([self::A, self::B], $this->storedVariants($id));
    }

    public function testAStoredPromotionIsReadBackAsItWasSaved(): void
    {
        $promotion = $this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::B, self::A], null);
        $this->promotions->save($promotion);
        $this->entityManager->clear();

        $found = $this->promotions->find($promotion->id());

        self::assertNotNull($found);
        self::assertSame('Promocja', $found->name());
        self::assertSame(20, $found->percentOff()->value());
        self::assertEquals($promotion->period(), $found->period());
        self::assertSame([self::A, self::B], self::ids($found->variantIds()), 'the variants by id');
        self::assertNull($found->createdBy());
        self::assertNull($found->updatedBy());
        self::assertSame(1, $found->version());
        self::assertSame('2030-01-01T00:00:00+00:00', $found->createdAt()->format(\DATE_ATOM));
        self::assertNull($this->promotions->find(PromotionId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testARevisedPromotionIsWrittenAtItsNextVersionWithItsVariants(): void
    {
        $promotion = $this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::A, self::B], null);
        $this->promotions->save($promotion);
        $this->entityManager->clear();
        $stored = $this->promotions->find($promotion->id());
        self::assertNotNull($stored);

        $stored->revise('Zima', PercentOff::of(30), new \DateTimeImmutable('2030-02-02T10:00:00Z'), new \DateTimeImmutable('2030-02-12T10:00:00Z'), self::variants(self::C, self::B), PromotionActor::staff('sub-2', 'Bartek'), new \DateTimeImmutable('2030-01-05T00:00:00Z'));
        $this->promotions->save($stored);

        $id = $promotion->id()->toString();
        self::assertSame(
            ['name' => 'Zima', 'percent_off' => 30, 'created_by_id' => null, 'updated_by_id' => 'sub-2', 'updated_by_name' => 'Bartek', 'version' => 2],
            $this->connection->fetchAssociative('SELECT name, percent_off, created_by_id, updated_by_id, updated_by_name, version FROM pricing_promotion WHERE id = ?', [$id]) ?: null,
        );
        self::assertSame([self::B, self::C], $this->storedVariants($id));
    }

    public function testASaveOfAPromotionChangedSinceItWasReadIsAConflict(): void
    {
        $promotion = $this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::A], null);
        $this->promotions->save($promotion);
        $this->entityManager->clear();
        $stored = $this->promotions->find($promotion->id());
        self::assertNotNull($stored);
        $this->connection->executeStatement('UPDATE pricing_promotion SET version = version + 1 WHERE id = ?', [$promotion->id()->toString()]);

        $stored->revise('Zima', PercentOff::of(30), null, new \DateTimeImmutable('2030-02-12T10:00:00Z'), self::variants(self::A), null, new \DateTimeImmutable('2030-01-05T00:00:00Z'));

        $this->expectException(PromotionModifiedConcurrently::class);
        $this->promotions->save($stored);
    }

    public function testADeletedPromotionLeavesNoRows(): void
    {
        $promotion = $this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::A, self::B], null);
        $this->promotions->save($promotion);
        $this->entityManager->clear();
        $stored = $this->promotions->find($promotion->id());
        self::assertNotNull($stored);

        $this->promotions->delete($stored);

        $id = $promotion->id()->toString();
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_promotion WHERE id = ?', [$id]));
        self::assertSame([], $this->storedVariants($id));
    }

    public function testConflictingVariantsAreTheCommonOnesOfOtherPromotionsOfAnOverlappingPeriod(): void
    {
        $this->promotions->save($this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::A, self::B], null));
        $this->promotions->save($this->promotion('2030-02-11T10:00:00Z', '2030-02-20T10:00:00Z', [self::C], null));
        $this->promotions->save($this->promotion('2030-03-01T00:00:00Z', '2030-03-05T00:00:00Z', [self::A, self::B, self::C], null));

        $overlapping = $this->promotion('2030-02-10T00:00:00Z', '2030-02-12T00:00:00Z', [self::C, self::B, self::A], null);
        self::assertSame([self::A, self::B, self::C], self::ids($this->promotions->conflictingVariantIds($overlapping)));

        $afterTheFirst = $this->promotion('2030-02-11T10:00:00Z', '2030-02-12T00:00:00Z', [self::A, self::B], null);
        self::assertSame([], $this->promotions->conflictingVariantIds($afterTheFirst), 'the end is not part of a period');

        $beforeTheSecond = $this->promotion('2030-02-10T00:00:00Z', '2030-02-11T10:00:00Z', [self::C], null);
        self::assertSame([], $this->promotions->conflictingVariantIds($beforeTheSecond));
    }

    public function testAPromotionDoesNotConflictWithItself(): void
    {
        $promotion = $this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::A, self::B], null);
        $this->promotions->save($promotion);

        self::assertSame([], $this->promotions->conflictingVariantIds($promotion));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function moments(): iterable
    {
        yield 'before the start' => ['2030-02-01T09:59:59.999999Z', false];
        yield 'at the start' => ['2030-02-01T10:00:00Z', true];
        yield 'before the end' => ['2030-02-11T09:59:59.999999Z', true];
        yield 'at the end' => ['2030-02-11T10:00:00Z', false];
    }

    #[DataProvider('moments')]
    public function testAVariantIsInAnActivePromotionFromItsStartUntilBeforeItsEnd(string $moment, bool $active): void
    {
        $this->promotions->save($this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::A], null));

        self::assertSame($active, $this->promotions->isInActivePromotion(VariantId::fromString(self::A), new \DateTimeImmutable($moment)));
        self::assertFalse($this->promotions->isInActivePromotion(VariantId::fromString(self::B), new \DateTimeImmutable($moment)));
    }

    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $updates = array_values(array_filter(
            new SchemaTool($this->entityManager)->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'pricing_'),
        ));

        self::assertSame([], $updates);
    }

    public function testTheTablesHaveTheirIndexes(): void
    {
        self::assertSame(
            [
                'pricing_promotion_starts_at_id_idx' => 'CREATE INDEX pricing_promotion_starts_at_id_idx ON public.pricing_promotion USING btree (starts_at DESC, id)',
                'pricing_promotion_variant_variant_id_idx' => 'CREATE INDEX pricing_promotion_variant_variant_id_idx ON public.pricing_promotion_variant USING btree (variant_id)',
            ],
            $this->connection->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE indexname IN ('pricing_promotion_starts_at_id_idx', 'pricing_promotion_variant_variant_id_idx') ORDER BY indexname"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rowsTheChecksRefuse(): iterable
    {
        yield 'the end at the start' => ["'2030-02-01 10:00:00+00', '2030-02-01 10:00:00+00', 20"];
        yield 'the end before the start' => ["'2030-02-01 10:00:00+00', '2030-02-01 09:00:00+00', 20"];
        yield 'no discount' => ["'2030-02-01 10:00:00+00', '2030-02-02 10:00:00+00', 0"];
        yield '91 %' => ["'2030-02-01 10:00:00+00', '2030-02-02 10:00:00+00', 91"];
    }

    #[DataProvider('rowsTheChecksRefuse')]
    public function testTheChecksRefuseAnEmptyPeriodAndAPercentOutOfRange(string $values): void
    {
        $this->connection->executeStatement('SAVEPOINT bad_row');
        try {
            $this->connection->executeStatement(
                'INSERT INTO pricing_promotion (id, name, starts_at, ends_at, percent_off, created_at, updated_at, version) VALUES (gen_random_uuid(), \'X\', '.$values.', now(), now(), 1)',
            );
            self::fail('The row must be refused.');
        } catch (DriverException $refused) {
            self::assertStringContainsString('check', strtolower($refused->getMessage()));
        } finally {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT bad_row');
        }
    }

    public function testTheVariantsGoWithTheirPromotion(): void
    {
        $promotion = $this->promotion('2030-02-01T10:00:00Z', '2030-02-11T10:00:00Z', [self::A], null);
        $this->promotions->save($promotion);

        $this->connection->executeStatement('DELETE FROM pricing_promotion WHERE id = ?', [$promotion->id()->toString()]);

        self::assertSame([], $this->storedVariants($promotion->id()->toString()));
    }

    /**
     * @param list<string> $variants
     */
    private function promotion(string $startsAt, string $endsAt, array $variants, ?PromotionActor $actor): Promotion
    {
        return Promotion::plan(
            PromotionId::fromString(Uuid::v7()->toRfc4122()),
            'Promocja',
            PercentOff::of(20),
            new \DateTimeImmutable($startsAt),
            new \DateTimeImmutable($endsAt),
            self::variants(...$variants),
            $actor,
            new \DateTimeImmutable(self::PLANNED_AT),
        );
    }

    /**
     * @return list<string>
     */
    private function storedVariants(string $promotionId): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT variant_id FROM pricing_promotion_variant WHERE promotion_id = ? ORDER BY variant_id', [$promotionId]);
    }

    /**
     * @return list<VariantId>
     */
    private static function variants(string ...$ids): array
    {
        return array_values(array_map(VariantId::fromString(...), $ids));
    }

    /**
     * @param list<VariantId> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (VariantId $id): string => $id->toString(), $ids);
    }
}
