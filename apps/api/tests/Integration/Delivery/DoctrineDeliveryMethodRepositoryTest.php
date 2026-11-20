<?php

declare(strict_types=1);

namespace App\Tests\Integration\Delivery;

use App\Delivery\Domain\DeliveryMethod;
use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodModifiedConcurrently;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryMethodNameTaken;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\TrackingUrlTemplate;
use App\Delivery\Infrastructure\Persistence\Doctrine\DoctrineDeliveryMethodRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * DoctrineDeliveryMethodRepository and the table delivery_method on PostgreSQL (shop_test, rolled back after each test).
 */
final class DoctrineDeliveryMethodRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineDeliveryMethodRepository $methods;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->methods = new DoctrineDeliveryMethodRepository($entityManager);
        $this->connection->executeStatement('DELETE FROM delivery_method');
    }

    public function testANewMethodIsStoredInItsColumns(): void
    {
        $method = $this->method('Kurier standardowy', freeFrom: 29_900, template: 'https://tracking.example.com/{numer}', description: 'Dostawa w 2–3 dni robocze');

        $this->methods->save($method);

        $this->connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");
        self::assertSame(
            ['name' => 'Kurier standardowy', 'description' => 'Dostawa w 2–3 dni robocze', 'price_gross' => 1999, 'free_from_gross' => 29_900, 'active' => true, 'position' => 10, 'tracking_url_template' => 'https://tracking.example.com/{numer}', 'created_at' => '2026-11-20 12:00:00+00', 'updated_at' => '2026-11-20 12:00:00+00', 'version' => 1],
            $this->connection->fetchAssociative('SELECT name, description, price_gross, free_from_gross, active, position, tracking_url_template, created_at, updated_at, version FROM delivery_method WHERE id = ?', [$method->id()->toString()]),
        );
    }

    public function testAStoredMethodIsReadBackAsItWasSaved(): void
    {
        $method = $this->method('Kurier', freeFrom: null, template: null, active: false);
        $this->methods->save($method);
        $this->entityManager->clear();

        $read = $this->methods->find($method->id());

        self::assertEquals($method, $read);
        self::assertEquals($method, $this->methods->find(DeliveryMethodId::fromString(strtoupper($method->id()->toString()))));
        self::assertNull($this->methods->find(DeliveryMethodId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testARevisedMethodIsWrittenAtItsNextVersion(): void
    {
        $method = $this->method('Kurier');
        $this->methods->save($method);

        $read = $this->methods->find($method->id());
        self::assertNotNull($read);
        $read->revise(DeliveryMethodName::of('Kurier ekspresowy'), DeliveryMethodDescription::of(null), DeliveryPrice::of(3999), null, false, DeliveryPosition::of(20), null, 0, new \DateTimeImmutable('2026-11-21T08:00:00Z'));
        $this->methods->save($read);
        $this->entityManager->clear();

        self::assertSame(
            ['name' => 'Kurier ekspresowy', 'price_gross' => 3999, 'active' => false, 'version' => 2],
            $this->connection->fetchAssociative('SELECT name, price_gross, active, version FROM delivery_method WHERE id = ?', [$method->id()->toString()]),
        );
    }

    public function testASaveOfAMethodChangedSinceItWasReadIsAConflict(): void
    {
        $method = $this->method('Kurier');
        $this->methods->save($method);
        $first = $this->methods->find($method->id());
        $second = $this->methods->find($method->id());
        self::assertNotNull($first);
        self::assertNotNull($second);
        $first->revise($first->name(), $first->description(), DeliveryPrice::of(1), null, true, $first->position(), null, 0, new \DateTimeImmutable());
        $this->methods->save($first);

        $second->revise($second->name(), $second->description(), DeliveryPrice::of(2), null, true, $second->position(), null, 0, new \DateTimeImmutable());

        $this->expectException(DeliveryMethodModifiedConcurrently::class);
        $this->methods->save($second);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namesTakenInAnotherCase(): iterable
    {
        yield 'upper case' => ['kurier', 'KURIER'];
        yield 'a Polish letter' => ['Łódź kurier', 'łódź KURIER'];
    }

    #[DataProvider('namesTakenInAnotherCase')]
    public function testANameTakenInAnotherCaseIsRefusedAlsoByAnInactiveMethod(string $stored, string $other): void
    {
        $this->methods->save($this->method($stored, active: false));

        $this->expectException(DeliveryMethodNameTaken::class);
        $this->methods->save($this->method($other));
    }

    public function testARenameToANameTakenIsRefused(): void
    {
        $this->methods->save($this->method('Kurier A'));
        $renamed = $this->method('Kurier B');
        $this->methods->save($renamed);
        $read = $this->methods->find($renamed->id());
        self::assertNotNull($read);
        $read->revise(DeliveryMethodName::of('kurier a'), $read->description(), $read->price(), null, true, $read->position(), null, 0, new \DateTimeImmutable());

        $this->expectException(DeliveryMethodNameTaken::class);
        $this->methods->save($read);
    }

    public function testTheActiveMethodsAreReadAndCounted(): void
    {
        $active = $this->method('Aktywna');
        $this->methods->save($active);
        $this->methods->save($this->method('Wyłączona', active: false));

        self::assertSame([$active->id()->toString()], array_map(static fn (DeliveryMethod $method): string => $method->id()->toString(), $this->methods->active()));
        self::assertSame(1, $this->methods->countActive());
        self::assertCount(2, $this->methods->all());
    }

    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $updates = array_values(array_filter(
            new SchemaTool($this->entityManager)->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'delivery_method'),
        ));

        self::assertSame([], $updates);
    }

    public function testTheTableHasItsIndexes(): void
    {
        self::assertSame(
            [
                'delivery_method_active_position_idx' => 'CREATE INDEX delivery_method_active_position_idx ON public.delivery_method USING btree (active, "position")',
                'delivery_method_name_key' => 'CREATE UNIQUE INDEX delivery_method_name_key ON public.delivery_method USING btree (lower((name)::text))',
            ],
            $this->connection->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'delivery_method' AND indexname NOT LIKE '%_pkey' ORDER BY indexname"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rowsTheChecksRefuse(): iterable
    {
        yield 'a negative price' => ['-1, NULL, 10'];
        yield 'a price of a million zloty' => ['100000000, NULL, 10'];
        yield 'a threshold of 0' => ['1999, 0, 10'];
        yield 'a threshold of a billion zloty' => ['1999, 100000000000, 10'];
        yield 'a negative position' => ['1999, NULL, -1'];
        yield 'a position of 1000' => ['1999, NULL, 1000'];
    }

    #[DataProvider('rowsTheChecksRefuse')]
    public function testTheChecksRefuseValuesOutOfTheirRanges(string $values): void
    {
        $this->connection->executeStatement('SAVEPOINT bad_row');
        try {
            $this->connection->executeStatement(
                "INSERT INTO delivery_method (id, name, description, price_gross, free_from_gross, position, active, created_at, updated_at, version) VALUES (gen_random_uuid(), 'Sprawdzana', '', ".$values.', true, now(), now(), 1)',
            );
            self::fail('The row must be refused.');
        } catch (DriverException $refused) {
            self::assertStringContainsString('check', strtolower($refused->getMessage()));
        } finally {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT bad_row');
        }
    }

    private function method(string $name, ?int $freeFrom = 29_900, ?string $template = null, bool $active = true, string $description = ''): DeliveryMethod
    {
        return DeliveryMethod::create(
            DeliveryMethodId::fromString(Uuid::v7()->toRfc4122()),
            DeliveryMethodName::of($name),
            DeliveryMethodDescription::of($description),
            DeliveryPrice::of(1999),
            FreeDeliveryThreshold::optional($freeFrom),
            $active,
            DeliveryPosition::of(10),
            TrackingUrlTemplate::optional($template),
            0,
            new \DateTimeImmutable('2026-11-20T12:00:00Z'),
        );
    }
}
