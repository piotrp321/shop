<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The order and stock checks of demo-data:verify on the data of every database step of the profile small, and on each
 * violation of the specification injected into it.
 */
final class OrderVerifyTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    public function testItPassesAfterEveryStep(): void
    {
        $this->allSteps();

        $tester = $this->verify();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testItListsAVariantWithoutAStockRecord(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement('DELETE FROM inventory_stock_item WHERE variant_id = (SELECT variant_id FROM inventory_stock_item WHERE physical = 0 ORDER BY variant_id LIMIT 1)');

        $this->assertViolation('A variant has no stock record');
    }

    public function testItListsReservedStockThatIsNotTheSumOfTheActiveReservations(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement('UPDATE inventory_stock_item SET physical = physical + 1, reserved = reserved + 1 WHERE variant_id = (SELECT variant_id FROM inventory_stock_item WHERE physical > 0 ORDER BY variant_id LIMIT 1)');

        $this->assertViolation('The reserved stock of a variant is not the sum of its active reservations');
    }

    public function testItListsStockThatIsNotTheLastMovement(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement("UPDATE inventory_stock_item SET physical = physical + 5 WHERE variant_id = (SELECT variant_id FROM inventory_stock_movement WHERE type = 'commit' ORDER BY variant_id LIMIT 1)");

        $this->assertViolation('The last movement of a variant is not its stock');
    }

    public function testItListsAPaidOrderWithoutAnActiveReservation(): void
    {
        $this->allSteps();
        $number = $this->connection()->fetchOne("SELECT number FROM ordering_order WHERE status = 'paid' ORDER BY number LIMIT 1");
        $this->connection()->executeStatement("UPDATE inventory_reservation SET status = 'committed' WHERE reservation_key = (SELECT id::text FROM ordering_order WHERE number = ?)", [$number]);

        $this->assertViolation('A paid order has no active reservation: '.$this->text($number));
    }

    public function testItListsAnOrderWhoseLinesLessTheDiscountAreNotItsTotal(): void
    {
        $this->allSteps();
        $number = $this->text($this->connection()->fetchOne('SELECT number FROM ordering_order ORDER BY number LIMIT 1 OFFSET 10'));
        $this->connection()->executeStatement('UPDATE ordering_order SET total_gross = total_gross + 1, total_net = total_net + 1 WHERE number = ?', [$number]);

        $this->assertViolation('The lines of an order less its discount and with its delivery are not its total: '.$number);
    }

    public function testAnOrderWithItsDeliveryInItsTotalsPasses(): void
    {
        $this->allSteps();
        $number = $this->unpaidOrder();
        $this->connection()->executeStatement("UPDATE ordering_order SET delivery_method_id = gen_random_uuid(), delivery_method_name = 'Kurier', delivery_gross = 1999, delivery_net = 1625, delivery_vat = 374, total_net = total_net + 1625, total_vat = total_vat + 374, total_gross = total_gross + 1999 WHERE number = ?", [$number]);

        $tester = $this->verify();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}> the change of an order with a delivery that its totals do not follow
     */
    public static function deliveriesNotInTheTotals(): iterable
    {
        yield 'a delivery not added' => ["UPDATE ordering_order SET delivery_method_id = gen_random_uuid(), delivery_method_name = 'Kurier', delivery_gross = 1999, delivery_net = 1625, delivery_vat = 374 WHERE number = ?"];
        yield 'the net and the VAT of the delivery apart from the totals' => ["UPDATE ordering_order SET delivery_method_id = gen_random_uuid(), delivery_method_name = 'Kurier', delivery_gross = 1999, delivery_net = 1625, delivery_vat = 374, total_net = total_net + 1626, total_vat = total_vat + 373, total_gross = total_gross + 1999 WHERE number = ?"];
    }

    #[DataProvider('deliveriesNotInTheTotals')]
    public function testItListsAnOrderWhoseDeliveryIsNotInItsTotals(string $sql): void
    {
        $this->allSteps();
        $number = $this->unpaidOrder();
        $this->connection()->executeStatement($sql, [$number]);

        $this->assertViolation('The lines of an order less its discount and with its delivery are not its total: '.$number);
    }

    /**
     * A generated order without a payment (whose total no payment has to match).
     */
    private function unpaidOrder(): string
    {
        return $this->text($this->connection()->fetchOne('SELECT number FROM ordering_order WHERE paid_at IS NULL ORDER BY number LIMIT 1'));
    }

    public function testItListsAPaidOrderWithoutAPaymentOfItsTotal(): void
    {
        $this->allSteps();
        $number = $this->text($this->connection()->fetchOne("SELECT number FROM ordering_order WHERE status = 'delivered' ORDER BY number LIMIT 1"));
        $this->connection()->executeStatement('UPDATE payment_payment SET amount = amount + 1 WHERE order_id = (SELECT id FROM ordering_order WHERE number = ?)', [$number]);

        $this->assertViolation('A paid order has no succeeded payment of its total: '.$number);
    }

    public function testItListsAnOrderWithACodeWithoutItsUsage(): void
    {
        $this->allSteps();
        $number = $this->text($this->connection()->fetchOne('SELECT number FROM ordering_order WHERE discount_code IS NOT NULL ORDER BY number LIMIT 1'));
        $this->connection()->executeStatement('DELETE FROM pricing_discount_code_usage WHERE order_id = (SELECT id FROM ordering_order WHERE number = ?)', [$number]);

        $this->assertViolation('An order with a discount code has no usage of the code: '.$number);
    }

    public function testItListsALineWhoseUnitPriceIsNotTheEffectivePriceAtThePlacement(): void
    {
        $this->allSteps();
        $number = $this->text($this->connection()->fetchOne('SELECT number FROM ordering_order WHERE discount_code IS NULL ORDER BY number LIMIT 1 OFFSET 20'));
        $this->connection()->executeStatement('UPDATE ordering_order_line SET unit_gross = unit_gross + 100, unit_net = unit_net + 100 WHERE order_id = (SELECT id FROM ordering_order WHERE number = ?) AND position = 0', [$number]);

        $this->assertViolation('The unit price of a line is not the effective price of its variant at the placement: '.$number);
    }

    public function testItListsALineInAPromotionWithoutItsCopy(): void
    {
        $this->allSteps();
        $number = $this->text($this->connection()->fetchOne('SELECT o.number FROM ordering_order o JOIN ordering_order_line l ON l.order_id = o.id WHERE l.promotion_id IS NOT NULL ORDER BY o.number LIMIT 1'));
        $this->connection()->executeStatement('UPDATE ordering_order_line SET promotion_id = NULL, promotion_name = NULL, promotion_percent_off = NULL, regular_unit_gross = NULL WHERE order_id = (SELECT id FROM ordering_order WHERE number = ?)', [$number]);

        $this->assertViolation('The unit price of a line is not the effective price of its variant at the placement: '.$number);
    }

    public function testALargeProfileChecksTheLinePricesOfASampleOfTheOrders(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        // --orders 1000000 makes the sample one order in ten by its index (number - 100001); the counts then differ, which is not what is looked at.
        $outOfSample = $this->text($connection->fetchOne('SELECT number FROM ordering_order WHERE (CAST(substr(number, 3) AS bigint) - 100001) % 10 = 3 ORDER BY number LIMIT 1'));
        $connection->executeStatement('UPDATE ordering_order_line SET unit_gross = unit_gross + 100 WHERE order_id = (SELECT id FROM ordering_order WHERE number = ?)', [$outOfSample]);
        self::assertStringNotContainsString('is not the effective price', $this->verify(['--orders' => 1_000_000])->getDisplay());

        $inSample = $this->text($connection->fetchOne('SELECT number FROM ordering_order WHERE (CAST(substr(number, 3) AS bigint) - 100001) % 10 = 0 ORDER BY number LIMIT 1 OFFSET 3'));
        $connection->executeStatement('UPDATE ordering_order_line SET unit_gross = unit_gross + 100 WHERE order_id = (SELECT id FROM ordering_order WHERE number = ?)', [$inSample]);

        self::assertStringContainsString('is not the effective price of its variant at the placement: '.$inSample, $this->verify(['--orders' => 1_000_000])->getDisplay());
    }

    public function testItListsACustomerOrderOfACustomerOutsideTheGeneratedAccounts(): void
    {
        $this->allSteps();
        $number = $this->text($this->connection()->fetchOne("SELECT number FROM ordering_order WHERE owner_type = 'customer' ORDER BY number LIMIT 1"));
        $this->connection()->executeStatement("UPDATE ordering_order SET customer_id = '6b4f0a52-4a3c-4d8e-9a7e-2f1f0c3e5d11' WHERE number = ?", [$number]);

        $this->assertViolation('An order of a customer is not of a generated account: '.$number);
    }

    public function testItListsAnOrderCountThatDiffersFromTheProfile(): void
    {
        $this->allSteps();
        $id = $this->connection()->fetchOne("SELECT id FROM ordering_order WHERE status = 'delivered' AND discount_code IS NULL AND placed_at < now() - interval '200 days' ORDER BY number LIMIT 1");
        foreach (['ordering_order_line', 'ordering_order_status_change', 'payment_payment'] as $table) {
            $this->connection()->executeStatement('DELETE FROM '.$table.' WHERE order_id = ?', [$id]);
        }
        $this->connection()->executeStatement('DELETE FROM ordering_order WHERE id = ?', [$id]);

        $this->assertViolation('has 5000 orders of the generator, the table has 4999');
    }

    public function testItListsAnOrderWhoseHistoryDoesNotEndWithItsStatus(): void
    {
        $this->allSteps();
        $number = $this->text($this->connection()->fetchOne("SELECT number FROM ordering_order WHERE status = 'delivered' ORDER BY number LIMIT 1"));
        $this->connection()->executeStatement("DELETE FROM ordering_order_status_change WHERE order_id = (SELECT id FROM ordering_order WHERE number = ?) AND to_status = 'delivered'", [$number]);

        $this->assertViolation('The history of an order does not end with its status: '.$number);
    }

    private function assertViolation(string $expected): void
    {
        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString($expected, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }
}
