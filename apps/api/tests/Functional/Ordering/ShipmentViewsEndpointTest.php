<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The shipment in the views of an order read over HTTP (docs/features/delivery/spec.md, W8, W8a, W10): the history of
 * the customer and the detail of the panel have `delivery` with the method whenever the order has one, and `shipment`
 * only with a tracking number (the carrier, the number, the link from the copy of the template); the number set at the
 * shipment and the one corrected afterwards are the ones read; an order without a method has the number without
 * carrier and link; `canChangeTrackingNumber` follows the status and the role.
 */
final class ShipmentViewsEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait;

    private const string TEMPLATE = 'https://tracking.example.com/{numer}';

    public function testTheCustomerSeesTheNumberAndTheLinkOnceStaffShipWithItAndThenTheCorrectedOne(): void
    {
        $orderId = $this->customerOrder();

        $before = $this->customerDetail($orderId);
        self::assertSame(['methodName' => 'Kurier testowy bez kosztu', 'gross' => 0], $before['delivery']);
        self::assertNull($before['shipment'], 'a paid order has no shipment');

        $this->shipWith($orderId, 'JJD0002-4455');
        $shipped = $this->customerDetail($orderId);
        self::assertSame(['carrier' => 'Kurier testowy bez kosztu', 'trackingNumber' => 'JJD0002-4455', 'trackingUrl' => 'https://tracking.example.com/JJD0002-4455'], $shipped['shipment']);

        $this->putJson('/api/v1/admin/orders/'.$orderId.'/tracking-number', ['trackingNumber' => 'XY-9', 'version' => 3], self::staff(['warehouse']));
        self::assertResponseStatusCodeSame(200);
        $corrected = $this->customerDetail($orderId);
        self::assertSame(['carrier' => 'Kurier testowy bez kosztu', 'trackingNumber' => 'XY-9', 'trackingUrl' => 'https://tracking.example.com/XY-9'], $corrected['shipment']);
        self::assertSame(['methodName' => 'Kurier testowy bez kosztu', 'gross' => 0], $corrected['delivery']);

        $this->putJson('/api/v1/admin/orders/'.$orderId.'/tracking-number', ['trackingNumber' => null, 'version' => 4], self::staff(['warehouse']));
        $removed = $this->customerDetail($orderId);
        self::assertNull($removed['shipment']);
        self::assertSame(['methodName' => 'Kurier testowy bez kosztu', 'gross' => 0], $removed['delivery'], 'the method stays without a number');
    }

    public function testAnOrderShippedWithoutANumberHasNoShipmentForTheCustomerAndThePanel(): void
    {
        $orderId = $this->customerOrder();
        $this->shipWith($orderId, null);

        self::assertNull($this->customerDetail($orderId)['shipment']);
        $panel = $this->getJson('/api/v1/admin/orders/'.$orderId, self::staff(['admin']));
        self::assertNull($panel['shipment']);
        self::assertSame(['methodName' => 'Kurier testowy bez kosztu', 'gross' => 0], $panel['delivery']);
    }

    public function testAMethodWithoutATemplateGivesTheNumberWithoutALink(): void
    {
        $orderId = $this->customerOrder();
        $this->connection->executeStatement('UPDATE ordering_order SET delivery_tracking_url_template = NULL WHERE id = ?', [$orderId]);
        $this->shipWith($orderId, 'AB-12');

        self::assertSame(['carrier' => 'Kurier testowy bez kosztu', 'trackingNumber' => 'AB-12', 'trackingUrl' => null], $this->customerDetail($orderId)['shipment']);
    }

    public function testAnOrderWithoutAMethodHasTheNumberWithoutCarrierAndLink(): void
    {
        $orderId = $this->customerOrder();
        $this->connection->executeStatement('UPDATE ordering_order SET delivery_method_id = NULL, delivery_method_name = NULL, delivery_tracking_url_template = NULL WHERE id = ?', [$orderId]);
        $this->shipWith($orderId, 'AB-12');

        $detail = $this->customerDetail($orderId);
        self::assertNull($detail['delivery']);
        self::assertSame(['carrier' => null, 'trackingNumber' => 'AB-12', 'trackingUrl' => null], $detail['shipment']);
        $panel = $this->getJson('/api/v1/admin/orders/'.$orderId, self::staff(['admin']));
        self::assertNull($panel['delivery']);
        self::assertSame(['carrier' => null, 'trackingNumber' => 'AB-12', 'trackingUrl' => null], $panel['shipment']);
    }

    public function testThePanelTellsWhoMayChangeTheNumberOfWhichOrder(): void
    {
        [$orderId] = $this->paidOrder();

        self::assertFalse($this->getJson('/api/v1/admin/orders/'.$orderId, self::staff(['admin']))['canChangeTrackingNumber'], 'a paid order has no number to change yet');

        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek'));
        $this->entityManager->clear();

        foreach ([[['admin'], true], [['warehouse'], true], [['support'], false]] as [$roles, $expected]) {
            $order = $this->getJson('/api/v1/admin/orders/'.$orderId, self::staff($roles));
            self::assertResponseStatusCodeSame(200);
            self::assertSame($expected, $order['canChangeTrackingNumber'], implode(',', $roles));
        }

        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Return, 3, 'Zwrot', 'kc-staff-1', 'anna'));
        $this->entityManager->clear();
        self::assertFalse($this->getJson('/api/v1/admin/orders/'.$orderId, self::staff(['admin']))['canChangeTrackingNumber'], 'a returned order');
    }

    /**
     * A paid order of the customer of TestTokenFactory::customer(), with a template of the link: version 2.
     */
    private function customerOrder(): string
    {
        [$orderId] = $this->paidOrder();
        $this->connection->executeStatement(
            "UPDATE ordering_order SET owner_type = 'customer', customer_id = ?, access_token = NULL, delivery_tracking_url_template = ? WHERE id = ?",
            [TestTokenFactory::CUSTOMER_ID, self::TEMPLATE, $orderId],
        );

        return $orderId;
    }

    private function shipWith(string $orderId, ?string $number): void
    {
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek', $number));
        $this->entityManager->clear();
    }

    /**
     * @return array<mixed>
     */
    private function customerDetail(string $orderId): array
    {
        $this->client->request('GET', '/api/v1/me/orders/'.$orderId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()]);
        self::assertResponseStatusCodeSame(200);
        $detail = $this->decoded();
        self::assertArrayHasKey('shipment', $detail);
        $this->entityManager->clear();

        return $detail;
    }
}
