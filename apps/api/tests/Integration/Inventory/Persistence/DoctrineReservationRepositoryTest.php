<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use App\Inventory\Domain\Reservation\Reservation;
use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Reservation\ReservationLine;
use App\Inventory\Domain\Reservation\ReservationStatus;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineReservationRepository;
use App\Tests\Integration\Inventory\IndependentConnections;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The reservation repository on PostgreSQL: shop_test, rolled back after each test, and independent connections that
 * commit where a test needs a second transaction (deleted after the test).
 */
final class DoctrineReservationRepositoryTest extends KernelTestCase
{
    use IndependentConnections;

    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    private Connection $connection;
    private DoctrineReservationRepository $repository;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->repository = new DoctrineReservationRepository($connection);
    }

    public function testAddedReservationIsFoundWithItsLines(): void
    {
        $key = $this->newKey();

        self::assertTrue($this->repository->add($this->reservation($key, [self::B => 3, self::A => 2])));

        $found = $this->repository->find(ReservationKey::fromString($key));
        self::assertNotNull($found);
        self::assertSame(ReservationStatus::Active, $found->status());
        self::assertSame([[self::A, 2], [self::B, 3]], $this->lines($found));
        self::assertSame(
            ['status' => 'active', 'stamped' => true],
            $this->connection->fetchAssociative('SELECT status, created_at IS NOT NULL AND updated_at = created_at AS stamped FROM inventory_reservation WHERE reservation_key = ?', [$key]),
        );
        self::assertNull($this->repository->find(ReservationKey::fromString($this->newKey())));
    }

    public function testATakenKeyIsNotAddedAgainAndKeepsTheStoredLines(): void
    {
        $key = $this->newKey();
        $this->repository->add($this->reservation($key, [self::A => 2]));

        self::assertFalse($this->repository->add($this->reservation($key, [self::A => 5, self::B => 1])));

        self::assertSame([[self::A, 2]], $this->lines($this->repository->find(ReservationKey::fromString($key))));
    }

    /**
     * ON CONFLICT waits for the uncommitted insert of the same key; once that transaction rolls back, the key is free.
     */
    public function testAddWaitsForAConcurrentTransactionAddingTheSameKeyAndProceedsWhenItRollsBack(): void
    {
        $key = $this->newKey();
        $this->committedReservationKeys = [$key];
        $first = $this->independentConnection();
        $second = $this->independentConnection();

        $first->beginTransaction();
        self::assertTrue(new DoctrineReservationRepository($first)->add($this->reservation($key, [self::A => 1])));

        $second->executeStatement("SET lock_timeout = '300ms'");
        self::assertLockNotAvailable(fn () => $second->transactional(fn () => new DoctrineReservationRepository($second)->add($this->reservation($key, [self::B => 1]))));

        $first->rollBack();
        self::assertTrue($second->transactional(fn (): bool => new DoctrineReservationRepository($second)->add($this->reservation($key, [self::B => 1]))));
        self::assertSame([[self::B, 1]], $this->lines(new DoctrineReservationRepository($first)->find(ReservationKey::fromString($key))));
    }

    public function testAddAfterAConcurrentTransactionCommittedTheSameKeyIsRefused(): void
    {
        $key = $this->newKey();
        $this->committedReservationKeys = [$key];
        $first = $this->independentConnection();
        $second = $this->independentConnection();

        $first->transactional(fn (): bool => new DoctrineReservationRepository($first)->add($this->reservation($key, [self::A => 1])));

        self::assertFalse($second->transactional(fn (): bool => new DoctrineReservationRepository($second)->add($this->reservation($key, [self::A => 1]))));
        self::assertSame(1, $second->fetchOne('SELECT COUNT(*) FROM inventory_reservation_line WHERE reservation_key = ?', [$key]));
    }

    public function testLockHoldsTheReservationRowUntilTheEndOfTheTransaction(): void
    {
        $key = $this->newKey();
        $this->committedReservationKeys = [$key];
        $setup = $this->independentConnection();
        $setup->transactional(fn (): bool => new DoctrineReservationRepository($setup)->add($this->reservation($key, [self::A => 1])));
        $holder = $this->independentConnection();
        $other = $this->independentConnection();

        $holder->beginTransaction();
        $locked = new DoctrineReservationRepository($holder)->lock(ReservationKey::fromString($key));
        self::assertNotNull($locked);
        self::assertSame([[self::A, 1]], $this->lines($locked));

        self::assertLockNotAvailable(static fn () => $other->fetchOne('SELECT 1 FROM inventory_reservation WHERE reservation_key = ? FOR UPDATE NOWAIT', [$key]));

        $holder->commit();
        self::assertSame(1, $other->fetchOne('SELECT 1 FROM inventory_reservation WHERE reservation_key = ? FOR UPDATE NOWAIT', [$key]));
        self::assertNull(new DoctrineReservationRepository($holder)->lock(ReservationKey::fromString($this->newKey())));
    }

    public function testSaveWritesTheStatus(): void
    {
        $key = $this->newKey();
        $this->repository->add($this->reservation($key, [self::A => 1]));

        $this->connection->transactional(function () use ($key): void {
            $reservation = $this->repository->lock(ReservationKey::fromString($key));
            self::assertNotNull($reservation);
            $reservation->commit();
            $this->repository->save($reservation);
        });

        self::assertSame(ReservationStatus::Committed, $this->repository->find(ReservationKey::fromString($key))?->status());
    }

    public function testSaveOfAnUnstoredReservationIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->save($this->reservation($this->newKey(), [self::A => 1]));
    }

    private function newKey(): string
    {
        return 'test:'.Uuid::v7()->toRfc4122();
    }

    /**
     * @param array<string, int> $lines
     */
    private function reservation(string $key, array $lines): Reservation
    {
        return Reservation::place(
            ReservationKey::fromString($key),
            array_map(static fn (string $id, int $quantity): ReservationLine => ReservationLine::of(ProductId::fromString($id), $quantity), array_keys($lines), array_values($lines)),
        );
    }

    /**
     * @return list<array{string, int}>
     */
    private function lines(?Reservation $reservation): array
    {
        self::assertNotNull($reservation);

        return array_map(static fn (ReservationLine $l): array => [$l->productId()->toString(), $l->quantity()], $reservation->lines());
    }
}
