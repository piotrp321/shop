<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Application\Query\BackofficeCustomerCursor;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\Query\InvalidBackofficeCustomerCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The keyset cursor of the customer list: the key of the sort, the value of the sorted column of the last customer and
 * its id, as opaque text; a cursor of another sort, or anything else, is no cursor.
 */
final class BackofficeCustomerCursorTest extends TestCase
{
    public function testEverySortKeepsTheValueOfItsColumnAndTheId(): void
    {
        $customer = self::customer();

        $moment = BackofficeCustomerCursor::decode(BackofficeCustomerCursor::after($customer, BackofficeCustomerSort::LastOrderDescending)->encode(), BackofficeCustomerSort::LastOrderDescending);
        $name = BackofficeCustomerCursor::decode(BackofficeCustomerCursor::after($customer, BackofficeCustomerSort::Name)->encode(), BackofficeCustomerSort::Name);
        $orders = BackofficeCustomerCursor::decode(BackofficeCustomerCursor::after($customer, BackofficeCustomerSort::OrderCountDescending)->encode(), BackofficeCustomerSort::OrderCountDescending);
        $gross = BackofficeCustomerCursor::decode(BackofficeCustomerCursor::after($customer, BackofficeCustomerSort::PaidGrossDescending)->encode(), BackofficeCustomerSort::PaidGrossDescending);

        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:30:00.123456+00:00'), $moment->value);
        self::assertSame('łucja gęślą', $name->value);
        self::assertSame(7, $orders->value);
        self::assertSame(123_400, $gross->value);
        foreach ([$moment, $name, $orders, $gross] as $cursor) {
            self::assertSame('kc-ąę/1', $cursor->customerId);
        }
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', BackofficeCustomerCursor::after($customer, BackofficeCustomerSort::Name)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storedNamesOutsideTheRule(): iterable
    {
        yield 'a tab' => ["anna\tnowak"];
        yield 'a C1 control' => ["anna\u{9F}nowak"];
        yield 'a no-break space only' => ["\u{A0}"];
        yield 'a line separator at the end' => ["anna nowak\u{2028}"];
    }

    /**
     * A name stored before a stricter rule of texts (spec api-input-contract, E7) is the position of the sort by name
     * like any other: the cursor after it is a cursor, so the list pages over the customer.
     */
    #[DataProvider('storedNamesOutsideTheRule')]
    public function testTheCursorAfterANameStoredOutsideTheRuleIsACursor(string $sortName): void
    {
        $customer = new ListedCustomer('kc-1', 'anna@example.com', $sortName, 1, 0, new \DateTimeImmutable('2026-11-05 12:30:00+00:00'), $sortName);

        $cursor = BackofficeCustomerCursor::decode(BackofficeCustomerCursor::after($customer, BackofficeCustomerSort::Name)->encode(), BackofficeCustomerSort::Name);

        self::assertSame([$sortName, 'kc-1'], [$cursor->value, $cursor->customerId]);
    }

    public function testACursorOfAnotherSortIsNoCursor(): void
    {
        $text = BackofficeCustomerCursor::after(self::customer(), BackofficeCustomerSort::OrderCountDescending)->encode();

        $this->expectException(InvalidBackofficeCustomerCursor::class);
        BackofficeCustomerCursor::decode($text, BackofficeCustomerSort::PaidGrossDescending);
    }

    /**
     * @return iterable<string, array{string, BackofficeCustomerSort}>
     */
    public static function notCursors(): iterable
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        yield 'empty' => ['', BackofficeCustomerSort::Name];
        yield 'not base64url' => ['a+b/c', BackofficeCustomerSort::Name];
        yield 'not JSON' => [rtrim(strtr(base64_encode('{'), '+/', '-_'), '='), BackofficeCustomerSort::Name];
        yield 'a list' => [$encode(['name', 'x', 'kc']), BackofficeCustomerSort::Name];
        yield 'a key more' => [$encode(['s' => 'name', 'v' => 'x', 'i' => 'kc', 'x' => 1]), BackofficeCustomerSort::Name];
        yield 'an empty id' => [$encode(['s' => 'name', 'v' => 'x', 'i' => '']), BackofficeCustomerSort::Name];
        yield 'an id too long' => [$encode(['s' => 'name', 'v' => 'x', 'i' => str_repeat('a', 256)]), BackofficeCustomerSort::Name];
        yield 'an id with a NUL' => [$encode(['s' => 'name', 'v' => 'x', 'i' => "kc\0"]), BackofficeCustomerSort::Name];
        yield 'a name with a NUL' => [$encode(['s' => 'name', 'v' => "x\0", 'i' => 'kc']), BackofficeCustomerSort::Name];
        yield 'a name that is a number' => [$encode(['s' => 'name', 'v' => 5, 'i' => 'kc']), BackofficeCustomerSort::Name];
        yield 'a count that is text' => [$encode(['s' => '-orderCount', 'v' => '5', 'i' => 'kc']), BackofficeCustomerSort::OrderCountDescending];
        yield 'a negative amount' => [$encode(['s' => '-paidGross', 'v' => -1, 'i' => 'kc']), BackofficeCustomerSort::PaidGrossDescending];
        yield 'a moment of another format' => [$encode(['s' => '-lastOrderAt', 'v' => '2026-11-05 12:30:00', 'i' => 'kc']), BackofficeCustomerSort::LastOrderDescending];
        yield 'a moment that does not exist' => [$encode(['s' => '-lastOrderAt', 'v' => '2026-02-31T12:30:00.000000+00:00', 'i' => 'kc']), BackofficeCustomerSort::LastOrderDescending];
        yield 'too long' => [str_repeat('a', 2049), BackofficeCustomerSort::Name];
    }

    #[DataProvider('notCursors')]
    public function testAnythingElseIsNoCursor(string $text, BackofficeCustomerSort $sort): void
    {
        $this->expectException(InvalidBackofficeCustomerCursor::class);

        BackofficeCustomerCursor::decode($text, $sort);
    }

    private static function customer(): ListedCustomer
    {
        return new ListedCustomer('kc-ąę/1', 'lucja@example.com', 'Łucja Gęślą', 7, 123_400, new \DateTimeImmutable('2026-11-05 13:30:00.123456+01:00'), 'łucja gęślą');
    }
}
