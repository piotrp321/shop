<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use App\Cart\Application\Command\MergeGuestCart;
use App\Cart\Application\Command\MergeResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * MergeGuestCart on command.bus and shop_test: both carts in one transaction. Rolled back after each test (DAMA), so
 * the trigger that forces a failure goes with it.
 */
final class MergeGuestCartTest extends KernelTestCase
{
    private const string CUSTOMER = 'kc-sub-merge';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-00000000000c';

    private Connection $connection;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->connection = $connection;
        $this->bus = $bus;
    }

    public function testAMergeStoresBothCarts(): void
    {
        $customer = CartFixture::cart($this->connection, customerId: self::CUSTOMER, expiresAt: '+10 days', lines: [[self::B, 1]]);
        $guest = CartFixture::cart($this->connection, lines: [[self::A, 2], [self::B, 3]]);

        $result = $this->merge($guest);

        self::assertSame('merged', $result->result);
        self::assertSame($customer, $result->cart->id);
        self::assertSame([[self::B, 4], [self::A, 2]], CartFixture::lines($this->connection, $customer));
        self::assertSame([], CartFixture::lines($this->connection, $guest));
        self::assertSame(['status' => 'merged', 'merged_into' => $customer, 'merged' => true], $this->guestState($guest));
        self::assertTrue($this->renewed($customer));
    }

    public function testAMergeWithAnExpiredCustomerCartStoresOnlyTheGuestLinesAndRenewsTheExpiry(): void
    {
        $customer = CartFixture::cart($this->connection, customerId: self::CUSTOMER, expiresAt: '-1 day', lines: [[self::B, 5], [self::C, 1]]);
        $guest = CartFixture::cart($this->connection, lines: [[self::A, 2], [self::B, 1]]);

        $result = $this->merge($guest);

        self::assertSame('merged', $result->result);
        self::assertSame([[self::A, 2], [self::B, 1]], CartFixture::lines($this->connection, $customer));
        self::assertTrue($this->renewed($customer));
        self::assertSame(['status' => 'merged', 'merged_into' => $customer, 'merged' => true], $this->guestState($guest));
    }

    public function testAFailureDuringTheMergeRollsBackBothCarts(): void
    {
        $customer = CartFixture::cart($this->connection, customerId: self::CUSTOMER, expiresAt: '+10 days', lines: [[self::B, 1]]);
        $guest = CartFixture::cart($this->connection, lines: [[self::A, 2], [self::B, 3]]);
        $expiresAt = $this->connection->fetchOne('SELECT expires_at FROM cart_cart WHERE id = ?', [$customer]);
        // The guest cart is written first; the new line of the customer cart then fails.
        $this->connection->executeStatement("CREATE FUNCTION cart_test_fail_merge() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of a merge'; END \$\$");
        $this->connection->executeStatement(\sprintf("CREATE TRIGGER cart_test_fail_merge BEFORE INSERT ON cart_line FOR EACH ROW WHEN (NEW.cart_id = '%s'::uuid) EXECUTE FUNCTION cart_test_fail_merge()", $customer));

        try {
            $this->merge($guest);
            self::fail('The merge must fail.');
        } catch (HandlerFailedException $failure) {
            $wrapped = array_values($failure->getWrappedExceptions());
            self::assertCount(1, $wrapped);
            self::assertInstanceOf(DriverException::class, $wrapped[0]);
            self::assertStringContainsString('forced failure of a merge', $wrapped[0]->getMessage());
        }

        self::assertSame(['status' => 'open', 'merged_into' => null, 'merged' => false], $this->guestState($guest));
        self::assertSame([[self::A, 2], [self::B, 3]], CartFixture::lines($this->connection, $guest));
        self::assertSame([[self::B, 1]], CartFixture::lines($this->connection, $customer));
        self::assertSame($expiresAt, $this->connection->fetchOne('SELECT expires_at FROM cart_cart WHERE id = ?', [$customer]));
    }

    public function testAFailureDuringTheFirstMergeOfACustomerLeavesNoCustomerCart(): void
    {
        $guest = CartFixture::cart($this->connection, lines: [[self::A, 2]]);
        $this->connection->executeStatement("CREATE FUNCTION cart_test_fail_merge() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of a merge'; END \$\$");
        $this->connection->executeStatement("CREATE TRIGGER cart_test_fail_merge BEFORE INSERT ON cart_line FOR EACH ROW WHEN (NEW.cart_id <> '00000000-0000-0000-0000-000000000000'::uuid) EXECUTE FUNCTION cart_test_fail_merge()");

        try {
            $this->merge($guest);
            self::fail('The merge must fail.');
        } catch (HandlerFailedException) {
        }

        self::assertSame(['status' => 'open', 'merged_into' => null, 'merged' => false], $this->guestState($guest));
        self::assertSame([[self::A, 2]], CartFixture::lines($this->connection, $guest));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [self::CUSTOMER]));
    }

    private function merge(string $guestCartId): MergeResult
    {
        $result = $this->bus->dispatch(new MergeGuestCart(self::CUSTOMER, $guestCartId))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(MergeResult::class, $result);

        return $result;
    }

    /**
     * @return array{status: mixed, merged_into: mixed, merged: bool}
     */
    private function guestState(string $guest): array
    {
        $row = $this->connection->fetchAssociative('SELECT status, merged_into, merged_at FROM cart_cart WHERE id = ?', [$guest]);
        self::assertIsArray($row);

        return ['status' => $row['status'], 'merged_into' => $row['merged_into'], 'merged' => null !== $row['merged_at']];
    }

    private function renewed(string $customer): bool
    {
        return 1 === $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND expires_at > now() + interval '179 days'", [$customer]);
    }
}
