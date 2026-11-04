<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart\Persistence;

use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\ProductOffer;
use App\Cart\Domain\Quantity;
use App\Cart\Infrastructure\Persistence\Doctrine\DbalCartReader;
use App\Cart\Infrastructure\Persistence\Doctrine\DoctrineCartRepository;
use App\Tests\Integration\Cart\CartFixture;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cart tables, the mapping of the records and the locks, on shop_test. Most tests run in the DAMA transaction and
 * are rolled back; the tests of a record already in the identity map change rows on a connection of their own, which
 * commits, and delete them afterwards.
 */
final class DoctrineCartRepositoryTest extends KernelTestCase
{
    private const string P1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string P2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';
    private const string P3 = '0192f0c4-7c1a-7c4e-9a6e-000000000003';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineCartRepository $repository;
    private DbalCartReader $reader;
    private ?Connection $committing = null;
    /** @var list<string> */
    private array $committedCarts = [];

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->repository = new DoctrineCartRepository($entityManager);
        $this->reader = new DbalCartReader($this->connection);
    }

    protected function tearDown(): void
    {
        if (null !== $this->committing) {
            CartFixture::delete($this->committing, $this->committedCarts);
            $this->committing->close();
        }
        parent::tearDown();
    }

    public function testANewCartIsStoredAndReadBackWithItsLinesInTheOrderTheyWereAdded(): void
    {
        $now = new \DateTimeImmutable('2026-11-03 12:00:00+00:00');
        $cart = Cart::createForGuest(CartId::generate(), $now);
        $this->repository->add($cart);
        $this->entityManager->clear();

        $this->connection->beginTransaction();
        $locked = $this->repository->lock($cart->id());
        self::assertNotNull($locked);
        // Same second: the order comes from the time-ordered line ids.
        $locked->add(ProductId::fromString(self::P2), Quantity::of(2), self::offer(), $now);
        $locked->add(ProductId::fromString(self::P1), Quantity::of(1), self::offer(), $now);
        $this->repository->save($locked);
        $this->connection->commit();
        $this->entityManager->clear();

        $read = $this->reader->find($cart->id());
        self::assertNotNull($read);
        self::assertTrue($read->owner()->isGuest());
        self::assertSame(CartStatus::Open, $read->status());
        self::assertSame([[self::P2, 2], [self::P1, 1]], self::lines($read));
        self::assertEquals($now, $read->updatedAt());
        self::assertEquals($now->modify('+30 days'), $read->expiresAt());
        self::assertEquals($now, $read->createdAt());
        self::assertSame([[self::P2, 2], [self::P1, 1]], self::lines($this->lockedCart($cart->id())));
    }

    public function testSavingUpdatesLinesInPlaceRemovesTheMissingOnesAndReAddsAProductAfterAClear(): void
    {
        $id = CartFixture::cart($this->connection, customerId: 'kc-sub-1', expiresAt: '-1 day', lines: [[self::P1, 3], [self::P2, 1]]);
        $now = new \DateTimeImmutable();

        $this->connection->beginTransaction();
        $cart = $this->repository->lockForCustomer('kc-sub-1');
        self::assertNotNull($cart);
        self::assertSame($id, $cart->id()->toString());
        // Expired: the lines are cleared, then P1 comes back as a new line.
        $cart->add(ProductId::fromString(self::P1), Quantity::of(5), self::offer(), $now);
        $this->repository->save($cart);
        $this->connection->commit();

        self::assertSame([[self::P1, 5]], CartFixture::lines($this->connection, $id));
        $this->entityManager->clear();
        self::assertEquals($now->modify('+180 days')->format('U'), $this->reader->findForCustomer('kc-sub-1')?->expiresAt()->format('U'));
    }

    public function testLockOrCreateForCustomerCreatesOneCartAndThenFindsIt(): void
    {
        $now = new \DateTimeImmutable('2026-11-03 12:00:00+00:00');
        $this->connection->beginTransaction();

        $first = $this->repository->lockOrCreateForCustomer('kc-sub-2', $now);
        $second = $this->repository->lockOrCreateForCustomer('kc-sub-2', $now->modify('+1 hour'));
        $this->connection->commit();

        self::assertTrue($first->id()->equals($second->id()));
        self::assertSame('kc-sub-2', $first->owner()->customerId());
        self::assertEquals($now->modify('+180 days'), $first->expiresAt());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', ['kc-sub-2']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4/', $first->id()->toString());
    }

    public function testLockForCustomerWithoutACartIsNullAndCreatesNothing(): void
    {
        $this->connection->beginTransaction();
        self::assertNull($this->repository->lockForCustomer('kc-sub-none'));
        self::assertNull($this->repository->lock(CartId::generate()));
        $this->connection->commit();

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', ['kc-sub-none']));
    }

    public function testTheReaderSeesNoCartForAnUnknownIdOrCustomer(): void
    {
        self::assertNull($this->reader->find(CartId::generate()));
        self::assertNull($this->reader->findForCustomer('kc-sub-none'));
    }

    public function testTheReaderGivesAMergedGuestCartAsStored(): void
    {
        $id = CartFixture::cart($this->connection, status: 'merged');

        $cart = $this->reader->find(CartId::fromString($id));

        self::assertNotNull($cart);
        self::assertSame(CartStatus::Merged, $cart->status());
        self::assertNotNull($cart->mergedInto());
        self::assertNotNull($cart->mergedAt());
    }

    public function testDeletingACartDeletesItsLines(): void
    {
        $id = CartFixture::cart($this->connection, lines: [[self::P1, 1], [self::P2, 2]]);

        $this->connection->executeStatement('DELETE FROM cart_cart WHERE id = ?', [$id]);

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_line WHERE cart_id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rowsBreakingTheRules(): iterable
    {
        $guest = "INSERT INTO cart_cart (id, owner_type, customer_id, status, created_at, updated_at, expires_at) VALUES ('%s', %s, %s, %s, now(), now(), now())";
        yield 'customer cart without a customer id' => [\sprintf($guest, '00000000-0000-4000-8000-000000000001', "'customer'", 'NULL', "'open'"), '23514'];
        yield 'guest cart with a customer id' => [\sprintf($guest, '00000000-0000-4000-8000-000000000001', "'guest'", "'kc-sub-1'", "'open'"), '23514'];
        yield 'merged customer cart' => [\sprintf($guest, '00000000-0000-4000-8000-000000000001', "'customer'", "'kc-sub-1'", "'merged'"), '23514'];
        yield 'second cart of a customer' => [\sprintf($guest, '00000000-0000-4000-8000-000000000001', "'customer'", "'kc-sub-taken'", "'open'"), '23505'];
        yield 'line of 0 units' => ["INSERT INTO cart_line (id, cart_id, product_id, quantity, added_at) VALUES ('00000000-0000-4000-8000-0000000000aa', '{cart}', '".self::P3."', 0, now())", '23514'];
        yield 'line of 100 units' => ["INSERT INTO cart_line (id, cart_id, product_id, quantity, added_at) VALUES ('00000000-0000-4000-8000-0000000000aa', '{cart}', '".self::P3."', 100, now())", '23514'];
        yield 'second line of a product' => ["INSERT INTO cart_line (id, cart_id, product_id, quantity, added_at) VALUES ('00000000-0000-4000-8000-0000000000aa', '{cart}', '".self::P1."', 1, now())", '23505'];
        yield 'line of no cart' => ["INSERT INTO cart_line (id, cart_id, product_id, quantity, added_at) VALUES ('00000000-0000-4000-8000-0000000000aa', '00000000-0000-4000-8000-0000000000bb', '".self::P3."', 1, now())", '23503'];
    }

    #[DataProvider('rowsBreakingTheRules')]
    public function testTheTablesRefuseARowBreakingTheRules(string $sql, string $sqlState): void
    {
        CartFixture::cart($this->connection, customerId: 'kc-sub-taken');
        $cart = CartFixture::cart($this->connection, lines: [[self::P1, 1]]);

        try {
            $this->connection->executeStatement(str_replace('{cart}', $cart, $sql));
            self::fail('Expected the database to refuse the row.');
        } catch (DriverException $refused) {
            self::assertSame($sqlState, $refused->getSQLState(), $refused->getMessage());
        }
    }

    public function testTheBoundaryQuantitiesAreAccepted(): void
    {
        $cart = CartFixture::cart($this->connection, lines: [[self::P1, 1], [self::P2, 99]]);

        self::assertSame([[self::P1, 1], [self::P2, 99]], CartFixture::lines($this->connection, $cart));
    }

    public function testALockOfACartAlreadyInTheIdentityMapReadsItAsCommittedByOthers(): void
    {
        $other = $this->committingConnection();
        $id = CartFixture::cart($other, lines: [[self::P1, 1], [self::P2, 2]]);
        $this->committedCarts[] = $id;
        $cartId = CartId::fromString($id);

        $this->connection->beginTransaction();
        self::assertSame([[self::P1, 1], [self::P2, 2]], self::lines($this->repository->lock($cartId) ?? self::fail()));
        $this->connection->rollBack();

        // Another transaction changes a quantity, removes a line, adds one and moves the expiry.
        $other->executeStatement('UPDATE cart_line SET quantity = 7 WHERE cart_id = ? AND product_id = ?', [$id, self::P1]);
        $other->executeStatement('DELETE FROM cart_line WHERE cart_id = ? AND product_id = ?', [$id, self::P2]);
        CartFixture::line($other, $id, self::P3, 4, new \DateTimeImmutable('+1 minute'));
        $other->executeStatement("UPDATE cart_cart SET expires_at = '2030-01-01 00:00:00+00' WHERE id = ?", [$id]);

        $this->connection->beginTransaction();
        $locked = $this->repository->lock($cartId);
        self::assertNotNull($locked);
        self::assertSame([[self::P1, 7], [self::P3, 4]], self::lines($locked));
        self::assertEquals(new \DateTimeImmutable('2030-01-01 00:00:00+00:00'), $locked->expiresAt());
        // The lock is held: another transaction cannot lock the row.
        try {
            $other->executeStatement('SELECT 1 FROM cart_cart WHERE id = ? FOR UPDATE NOWAIT', [$id]);
            self::fail('Expected the cart row to be locked.');
        } catch (DriverException $refused) {
            self::assertSame('55P03', $refused->getSQLState());
        }

        // And the save writes on the refreshed records (seen inside the transaction, then rolled back, so the rows
        // of the other connection are free again for its clean-up).
        $locked->changeQuantity(ProductId::fromString(self::P3), Quantity::of(1), null, new \DateTimeImmutable());
        $this->repository->save($locked);
        self::assertSame([[self::P1, 7], [self::P3, 1]], CartFixture::lines($this->connection, $id));
        $this->connection->rollBack();
    }

    public function testALockOfACartInTheIdentityMapThatWasDeletedIsNull(): void
    {
        $other = $this->committingConnection();
        $id = CartFixture::cart($other, lines: [[self::P1, 1]]);
        $this->committedCarts[] = $id;

        $this->connection->beginTransaction();
        self::assertNotNull($this->repository->lock(CartId::fromString($id)));
        $this->connection->rollBack();
        $other->executeStatement('DELETE FROM cart_cart WHERE id = ?', [$id]);

        $this->connection->beginTransaction();
        self::assertNull($this->repository->lock(CartId::fromString($id)));
        $this->connection->rollBack();
    }

    /**
     * The expired cart of the customer is removed by the purge (committed) after the INSERT … ON CONFLICT skipped
     * and the id was read, and before the lock is taken. The change of the customer must still succeed: the
     * insert is repeated, and it adds a new cart.
     */
    public function testLockOrCreateForCustomerCreatesANewCartWhenThePurgeRemovedTheFoundOneBeforeTheLock(): void
    {
        $other = $this->committingConnection();
        $purged = CartFixture::cart($other, customerId: 'kc-sub-purged', expiresAt: '-1 day', lines: [[self::P1, 2]]);
        $this->committedCarts[] = $purged;
        $removeOnFirstLock = true;
        $entityManager = new class($this->entityManager, static function () use (&$removeOnFirstLock, $other, $purged): void {
            if ($removeOnFirstLock) {
                $removeOnFirstLock = false;
                $other->executeStatement('DELETE FROM cart_cart WHERE id = ?', [$purged]);
            }
        }) extends EntityManagerDecorator {
            public function __construct(EntityManagerInterface $wrapped, private readonly \Closure $beforeFind)
            {
                parent::__construct($wrapped);
            }

            public function find(string $className, mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?object
            {
                ($this->beforeFind)();

                return parent::find($className, $id, $lockMode, $lockVersion);
            }
        };
        $now = new \DateTimeImmutable('2026-11-03 12:00:00+00:00');

        $this->connection->beginTransaction();
        $cart = new DoctrineCartRepository($entityManager)->lockOrCreateForCustomer('kc-sub-purged', $now);
        $this->connection->commit();

        self::assertFalse($removeOnFirstLock, 'The cart was removed between the read of its id and the lock.');
        self::assertFalse($cart->id()->equals(CartId::fromString($purged)));
        self::assertSame('kc-sub-purged', $cart->owner()->customerId());
        self::assertSame([], self::lines($cart));
        self::assertSame([$cart->id()->toString()], $this->connection->fetchFirstColumn('SELECT id FROM cart_cart WHERE customer_id = ?', ['kc-sub-purged']));
    }

    private function lockedCart(CartId $id): Cart
    {
        $this->connection->beginTransaction();
        try {
            return $this->repository->lock($id) ?? self::fail('The cart is not stored.');
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * A connection outside the DAMA transaction of the test, which commits.
     */
    private function committingConnection(): Connection
    {
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        $this->committing = DriverManager::getConnection($params, new DbalConfiguration());
        $this->committing->executeStatement("SET lock_timeout = '10s'");

        return $this->committing;
    }

    private static function offer(): ProductOffer
    {
        return new ProductOffer(true, 1000);
    }

    /**
     * @return list<array{string, int}>
     */
    private static function lines(Cart $cart): array
    {
        return array_map(static fn (CartLine $line): array => [$line->productId()->toString(), $line->quantity()->value()], $cart->lines());
    }
}
