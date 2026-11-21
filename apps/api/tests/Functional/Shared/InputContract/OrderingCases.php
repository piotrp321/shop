<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Application\Query\BackofficeCustomerCursor;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use Symfony\Component\Uid\Uuid;

/**
 * Rows of the input contract of the operations of Ordering.
 */
final class OrderingCases
{
    private const string CUSTOMER_ORDERS = 'GET /api/v1/me/orders';
    private const string ORDERS = 'GET /api/v1/admin/orders';
    private const string CUSTOMERS = 'GET /api/v1/admin/customers';

    /**
     * @return iterable<InputCase>
     */
    public static function all(): iterable
    {
        $none = InputContractFixtures::NONE;
        $now = new \DateTimeImmutable();
        // The default sort of both lists of orders: the newest first.
        $orderCursor = BackofficeOrderCursor::placedAfter($now, Uuid::v4()->toRfc4122())->encode();

        yield from ValidatorRows::perPage(self::CUSTOMER_ORDERS, InputCase::CUSTOMER, $none, 50);
        yield from ValidatorRows::after(self::CUSTOMER_ORDERS, InputCase::CUSTOMER, $none, $orderCursor);

        $staff = InputCase::STAFF;
        yield from ValidatorRows::perPage(self::ORDERS, $staff, $none, 100);
        yield from ValidatorRows::after(self::ORDERS, $staff, $none, $orderCursor);
        yield InputCase::query(self::ORDERS, $staff, $none, 'status', 'status[]=x', 'status=paid', 'status[]=x', [['status', ValidatorRows::TYPE]]);
        yield ValidatorRows::ofAnotherKind(self::ORDERS, $staff, $none, 'refund', 'refund=pending', 'Filtr zwrotów może mieć tylko wartość pending albo overdue.');
        yield ValidatorRows::ofAnotherKind(self::ORDERS, $staff, $none, 'q', 'q=Kowalski', 'Szukana fraza to numer zamówienia albo tekst od 3 do 100 znaków bez znaków sterujących.');
        foreach (['placedFrom', 'placedTo'] as $date) {
            yield ValidatorRows::ofAnotherKind(self::ORDERS, $staff, $none, $date, $date.'=2026-01-15', 'Podaj datę w formacie RRRR-MM-DD, od 2000 do 2999 roku.');
        }
        yield ValidatorRows::ofAnotherKind(self::ORDERS, $staff, $none, 'sort', 'sort=-total', 'Sortowanie może mieć tylko wartość -placedAt, placedAt, -total albo total.');
        yield ValidatorRows::ofAnotherKind(self::ORDERS, $staff, $none, 'customerId', 'customerId=abc', 'Identyfikator klienta ma od 1 do 255 znaków bez znaków sterujących.');

        $customer = new ListedCustomer(Uuid::v4()->toRfc4122(), 'jan@example.com', 'Jan Kowalski', 1, 1230, $now, 'kowalski jan');
        $customerCursor = BackofficeCustomerCursor::after($customer, BackofficeCustomerSort::LastOrderDescending)->encode();
        yield ValidatorRows::ofAnotherKind(self::CUSTOMERS, $staff, $none, 'q', 'q=jan', 'Szukana fraza to fragment e-maila albo imienia i nazwiska, od 3 do 100 znaków bez znaków sterujących.');
        yield ValidatorRows::ofAnotherKind(self::CUSTOMERS, $staff, $none, 'sort', 'sort=name', 'Sortowanie może mieć tylko wartość -lastOrderAt, name, -orderCount albo -paidGross.');
        yield ValidatorRows::ofAnotherKind(self::CUSTOMERS, $staff, $none, 'after', 'after='.$customerCursor, 'Kursor strony jest nieprawidłowy.');
        yield ValidatorRows::ofAnotherKind(self::CUSTOMERS, $staff, $none, 'perPage', 'perPage=100', 'Liczba wyników na stronie to liczba całkowita od 1 do 100.');
    }
}
