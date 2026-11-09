<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use App\Pricing\Domain\DiscountCode\DiscountCodeActor;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeModifiedConcurrently;
use App\Pricing\Domain\DiscountCode\DiscountCodeTaken;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * DoctrineDiscountCodeRepository on PostgreSQL (shop_test, rolled back after each test).
 */
final class DoctrineDiscountCodeRepositoryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineDiscountCodeRepository $codes;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->codes = new DoctrineDiscountCodeRepository($entityManager);
    }

    public function testANewAmountCodeIsStoredInItsColumns(): void
    {
        $code = DiscountCodeFactory::createOne([
            'code' => 'minus50', 'kind' => DiscountKind::Amount, 'value' => 5000, 'minimumOrderGross' => 30000,
            'startsAt' => '2030-02-01T10:00:00.700000Z', 'endsAt' => '2030-03-01T10:00:00Z', 'usageLimit' => 100,
            'actor' => DiscountCodeActor::staff('sub-1', 'Ola'), 'createdAt' => '2030-01-01T00:00:00Z',
        ]);

        $this->codes->save($code);

        $this->connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");
        self::assertSame(
            [
                'code' => 'MINUS50', 'kind' => 'amount', 'percent_off' => null, 'amount_gross' => 5000, 'minimum_order_gross' => 30000,
                'starts_at' => '2030-02-01 10:00:00+00', 'ends_at' => '2030-03-01 10:00:00+00', 'usage_limit' => 100, 'enabled' => true,
                'created_at' => '2030-01-01 00:00:00+00', 'created_by_id' => 'sub-1', 'created_by_name' => 'Ola',
                'updated_at' => '2030-01-01 00:00:00+00', 'updated_by_id' => 'sub-1', 'updated_by_name' => 'Ola', 'version' => 1,
            ],
            $this->connection->fetchAssociative('SELECT code, kind, percent_off, amount_gross, minimum_order_gross, starts_at, ends_at, usage_limit, enabled, created_at, created_by_id, created_by_name, updated_at, updated_by_id, updated_by_name, version FROM pricing_discount_code WHERE id = ?', [$code->id()->toString()]) ?: null,
        );
    }

    public function testAStoredPercentCodeIsReadBackAsItWasSaved(): void
    {
        $code = DiscountCodeFactory::createOne(['code' => 'WITAJ10', 'value' => 10, 'startsAt' => '2030-02-01T10:00:00Z', 'createdAt' => '2030-01-01T00:00:00Z', 'enabled' => false]);
        $this->codes->save($code);
        $this->entityManager->clear();

        $found = $this->codes->find($code->id());

        self::assertNotNull($found);
        self::assertSame('WITAJ10', $found->code()->toString());
        self::assertTrue($found->value()->equals(DiscountValue::of(DiscountKind::Percent, 10)));
        self::assertNull($found->minimumOrder());
        self::assertNull($found->usageLimit());
        self::assertEquals($code->period(), $found->period());
        self::assertFalse($found->isEnabled());
        self::assertNull($found->createdBy());
        self::assertSame(1, $found->version());
        self::assertSame('2030-01-01T00:00:00+00:00', $found->createdAt()->format(\DATE_ATOM));
        self::assertNull($this->codes->find(DiscountCodeId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testARevisedCodeIsWrittenAtItsNextVersion(): void
    {
        $code = DiscountCodeFactory::createOne(['startsAt' => '2030-02-01T10:00:00Z', 'createdAt' => '2030-01-01T00:00:00Z']);
        $this->codes->save($code);
        $this->entityManager->clear();
        $stored = $this->codes->find($code->id());
        self::assertNotNull($stored);

        $stored->revise(DiscountValue::of(DiscountKind::Amount, 2000), MinimumOrder::of(10000), ValidityPeriod::of(new \DateTimeImmutable('2030-02-02T10:00:00Z'), null), UsageLimit::of(5), false, 0, DiscountCodeActor::staff('sub-2', 'Bartek'), new \DateTimeImmutable('2030-01-05T00:00:00Z'));
        $this->codes->save($stored);

        self::assertSame(
            ['kind' => 'amount', 'percent_off' => null, 'amount_gross' => 2000, 'minimum_order_gross' => 10000, 'usage_limit' => 5, 'enabled' => false, 'created_by_id' => null, 'updated_by_id' => 'sub-2', 'updated_by_name' => 'Bartek', 'version' => 2],
            $this->connection->fetchAssociative('SELECT kind, percent_off, amount_gross, minimum_order_gross, usage_limit, enabled, created_by_id, updated_by_id, updated_by_name, version FROM pricing_discount_code WHERE id = ?', [$code->id()->toString()]) ?: null,
        );
    }

    public function testASaveOfACodeChangedSinceItWasReadIsAConflict(): void
    {
        $code = DiscountCodeFactory::createOne(['startsAt' => '2030-02-01T10:00:00Z', 'createdAt' => '2030-01-01T00:00:00Z']);
        $this->codes->save($code);
        $this->entityManager->clear();
        $stored = $this->codes->find($code->id());
        self::assertNotNull($stored);
        $this->connection->executeStatement('UPDATE pricing_discount_code SET version = version + 1 WHERE id = ?', [$code->id()->toString()]);

        $stored->revise($stored->value(), null, $stored->period(), null, false, 0, null, new \DateTimeImmutable('2030-01-05T00:00:00Z'));

        $this->expectException(DiscountCodeModifiedConcurrently::class);
        $this->codes->save($stored);
    }

    public function testATextTakenInAnotherCaseIsRefused(): void
    {
        $this->codes->save(DiscountCodeFactory::createOne(['code' => 'LATO-15']));

        $this->expectException(DiscountCodeTaken::class);
        $this->codes->save(DiscountCodeFactory::createOne(['code' => 'lato-15']));
    }

    public function testACodeIsFoundByItsNormalisedTextAsStoredNow(): void
    {
        $code = DiscountCodeFactory::createOne(['code' => 'Jesien-25']);
        $this->codes->save($code);
        // The record stays in the identity map; another transaction disables the code meanwhile.
        self::assertTrue($this->codes->find($code->id())?->isEnabled());
        $this->connection->executeStatement('UPDATE pricing_discount_code SET enabled = false, version = version + 1 WHERE id = ?', [$code->id()->toString()]);

        $found = $this->codes->findByCode(DiscountCodeValue::of(' jesien-25 '));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($code->id()));
        self::assertFalse($found->isEnabled());
        self::assertSame(2, $found->version());
        self::assertNull($this->codes->findByCode(DiscountCodeValue::of('JESIEN-26')));
    }

    public function testACodeIsFoundByItsIdAsStoredNow(): void
    {
        $code = DiscountCodeFactory::createOne();
        $this->codes->save($code);
        // The record stays in the identity map; another transaction disables the code meanwhile.
        $before = $this->codes->find($code->id());
        self::assertTrue($before?->isEnabled());
        $this->connection->executeStatement('UPDATE pricing_discount_code SET enabled = false, version = version + 1 WHERE id = ?', [$code->id()->toString()]);

        $found = $this->codes->find($code->id());

        self::assertNotNull($found);
        self::assertFalse($found->isEnabled());
        self::assertSame(2, $found->version());
        self::assertFalse($this->holdsARowLock(), 'a read takes no lock');
        self::assertNull($this->codes->find(DiscountCodeId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testALockReadsTheCodeAnewUnderTheLockOfItsRow(): void
    {
        $code = DiscountCodeFactory::createOne();
        $this->codes->save($code);
        // The record stays in the identity map; another transaction disables the code meanwhile.
        self::assertTrue($this->codes->find($code->id())?->isEnabled());
        $this->connection->executeStatement('UPDATE pricing_discount_code SET enabled = false, version = version + 1 WHERE id = ?', [$code->id()->toString()]);
        self::assertFalse($this->holdsARowLock());
        $this->connection->beginTransaction();

        $locked = $this->codes->lock($code->id());

        self::assertNotNull($locked);
        self::assertFalse($locked->isEnabled());
        self::assertSame(2, $locked->version());
        self::assertTrue($this->holdsARowLock());
        $this->connection->rollBack();
    }

    public function testALockOfACodeNotYetReadTakesTheLockToo(): void
    {
        $code = DiscountCodeFactory::createOne();
        $this->codes->save($code);
        $this->entityManager->clear();
        $this->connection->beginTransaction();

        self::assertSame(1, $this->codes->lock($code->id())?->version());
        self::assertTrue($this->holdsARowLock());
        self::assertNull($this->codes->lock(DiscountCodeId::fromString(Uuid::v7()->toRfc4122())));
        $this->connection->rollBack();
    }

    public function testALockOfACodeDeletedSinceItWasReadIsNull(): void
    {
        $code = DiscountCodeFactory::createOne();
        $this->codes->save($code);
        self::assertNotNull($this->codes->find($code->id()));
        $this->connection->executeStatement('DELETE FROM pricing_discount_code WHERE id = ?', [$code->id()->toString()]);
        $this->connection->beginTransaction();

        self::assertNull($this->codes->lock($code->id()));
        $this->connection->rollBack();
    }

    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $updates = array_values(array_filter(
            new SchemaTool($this->entityManager)->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'pricing_discount_code'),
        ));

        self::assertSame([], $updates);
    }

    public function testTheTablesHaveTheirIndexes(): void
    {
        self::assertSame(
            [
                'pricing_discount_code_code_key' => 'CREATE UNIQUE INDEX pricing_discount_code_code_key ON public.pricing_discount_code USING btree (code)',
                'pricing_discount_code_created_at_id_idx' => 'CREATE INDEX pricing_discount_code_created_at_id_idx ON public.pricing_discount_code USING btree (created_at DESC, id)',
                'pricing_discount_code_usage_active_idx' => 'CREATE INDEX pricing_discount_code_usage_active_idx ON public.pricing_discount_code_usage USING btree (code_id) WHERE (released_at IS NULL)',
                'pricing_discount_code_usage_code_id_idx' => 'CREATE INDEX pricing_discount_code_usage_code_id_idx ON public.pricing_discount_code_usage USING btree (code_id)',
                'pricing_discount_code_usage_order_id_key' => 'CREATE UNIQUE INDEX pricing_discount_code_usage_order_id_key ON public.pricing_discount_code_usage USING btree (order_id)',
            ],
            $this->connection->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE tablename LIKE 'pricing_discount_code%' AND indexname NOT LIKE '%_pkey' ORDER BY indexname"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rowsTheChecksRefuse(): iterable
    {
        $start = "'2030-02-01 10:00:00+00'";
        yield 'a percent code without a percent' => ["'percent', NULL, NULL, NULL, {$start}, NULL"];
        yield 'a percent code with an amount' => ["'percent', 10, 500, NULL, {$start}, NULL"];
        yield 'an amount code without an amount' => ["'amount', NULL, NULL, 1000, {$start}, NULL"];
        yield 'an amount code with a percent' => ["'amount', 10, 500, 1000, {$start}, NULL"];
        yield 'an unknown kind' => ["'free', 10, NULL, NULL, {$start}, NULL"];
        yield 'a percent of 0' => ["'percent', 0, NULL, NULL, {$start}, NULL"];
        yield 'a percent of 91' => ["'percent', 91, NULL, NULL, {$start}, NULL"];
        yield 'an amount code without a minimum' => ["'amount', NULL, 500, NULL, {$start}, NULL"];
        yield 'an amount code with the minimum equal to its amount' => ["'amount', NULL, 500, 500, {$start}, NULL"];
        yield 'the end at the start' => ["'percent', 10, NULL, NULL, {$start}, {$start}"];
        yield 'the end before the start' => ["'percent', 10, NULL, NULL, {$start}, '2030-02-01 09:00:00+00'"];
    }

    #[DataProvider('rowsTheChecksRefuse')]
    public function testTheChecksRefuseAnInconsistentCode(string $values): void
    {
        $this->connection->executeStatement('SAVEPOINT bad_row');
        try {
            $this->connection->executeStatement(
                "INSERT INTO pricing_discount_code (id, code, kind, percent_off, amount_gross, minimum_order_gross, starts_at, ends_at, enabled, created_at, updated_at, version) VALUES (gen_random_uuid(), 'CHECKED', ".$values.', true, now(), now(), 1)',
            );
            self::fail('The row must be refused.');
        } catch (DriverException $refused) {
            self::assertStringContainsString('check', strtolower($refused->getMessage()));
        } finally {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT bad_row');
        }
    }

    /**
     * `SELECT … FOR UPDATE` takes RowShareLock on the table (and the row lock on the row itself).
     */
    private function holdsARowLock(): bool
    {
        return true === $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'pricing_discount_code' AND l.pid = pg_backend_pid() AND l.mode = 'RowShareLock')",
        );
    }
}
