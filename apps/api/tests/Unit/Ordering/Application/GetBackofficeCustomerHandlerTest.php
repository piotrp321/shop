<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\CustomerSummary;
use App\Ordering\Application\Query\CustomerNotFound;
use App\Ordering\Application\Query\GetBackofficeCustomer;
use App\Ordering\Application\Query\GetBackofficeCustomerHandler;
use App\Ordering\Application\View\BackofficeCustomerView;
use App\Ordering\Application\View\ShippingAddressView;
use App\Tests\Unit\Ordering\InMemoryBackofficeCustomerReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The card of a customer: its summary and up to 10 distinct addresses of its last 50 orders; an unknown customer, or
 * an id no customer can have, is not found without asking the reader for it.
 */
final class GetBackofficeCustomerHandlerTest extends TestCase
{
    public function testTheCardHasTheSummaryAndTheAddressesOfTheLastOrders(): void
    {
        $address = new ShippingAddressView('Długa 1', '00-950', 'Warszawa', 'PL');
        $reader = new InMemoryBackofficeCustomerReader(
            summaries: ['kc-1' => new CustomerSummary('kc-1', 'anna@example.com', 'Anna Nowak', '+48600100200', 3, 2, 24_600, new \DateTimeImmutable('2026-11-01 10:00:00+00:00'), new \DateTimeImmutable('2026-11-03 10:00:00+00:00'))],
            addresses: ['kc-1' => [$address]],
        );

        $card = new GetBackofficeCustomerHandler($reader)(new GetBackofficeCustomer('kc-1'));

        self::assertEquals(new BackofficeCustomerView('kc-1', 'anna@example.com', 'Anna Nowak', '+48600100200', 3, 2, 24_600, 'PLN', new \DateTimeImmutable('2026-11-01 10:00:00+00:00'), new \DateTimeImmutable('2026-11-03 10:00:00+00:00'), [$address]), $card);
        self::assertSame([['kc-1', 50, 10]], $reader->addressCalls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknown(): iterable
    {
        yield 'no such customer' => ['kc-2'];
        yield 'empty' => [''];
        yield 'longer than a customer id' => [str_repeat('a', 256)];
        yield 'with a NUL' => ["kc-1\0"];
        yield 'with a line separator' => ["kc\u{2028}1"];
    }

    #[DataProvider('unknown')]
    public function testAnUnknownCustomerIsNotFound(string $customerId): void
    {
        $reader = new InMemoryBackofficeCustomerReader();

        try {
            new GetBackofficeCustomerHandler($reader)(new GetBackofficeCustomer($customerId));
            self::fail('Expected CustomerNotFound.');
        } catch (CustomerNotFound $notFound) {
            self::assertSame('Klient nie istnieje.', $notFound->getMessage());
            self::assertSame([], $reader->addressCalls);
        }
    }
}
