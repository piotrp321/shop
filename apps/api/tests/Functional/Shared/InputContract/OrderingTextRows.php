<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * Rows of the contract of texts of the operations of Ordering (TextRow): the contact and the address of an order of
 * a guest and of a customer, the note of a staff member to a change of the status and to the completion of a refund,
 * and the phrases of the lists of orders and of customers of the panel.
 */
final class OrderingTextRows
{
    private const string GUEST = 'POST /api/v1/orders';
    private const string CUSTOMER = 'POST /api/v1/me/orders';
    private const string ORDER = 'SELECT %s FROM ordering_order WHERE id = :id';
    private const string NOTE = 'Notatka zawiera niedozwolone znaki.';
    private const string NOTE_TYPE = 'Notatka musi być tekstem.';
    private const string PHONE = 'Podaj 9-cyfrowy numer telefonu.';
    private const string POSTAL_CODE = 'Podaj kod pocztowy w formacie 00-000.';
    private const string EMAIL = 'Podaj poprawny adres e-mail.';

    /**
     * @return iterable<TextRow>
     */
    public static function all(): iterable
    {
        yield from self::orderTexts();
        yield from self::staffNotes();
        yield from self::phrases();
    }

    /**
     * @return iterable<TextRow>
     */
    private static function orderTexts(): iterable
    {
        // One unit of 12,30 zł with the free delivery method of the fixture.
        $details = [
            'fullName' => 'Anna Nowak',
            'phone' => '600100200',
            'street' => 'ul. Długa 5/3',
            'postalCode' => '00-950',
            'city' => 'Warszawa',
            'expectedTotalGross' => 1230,
            'deliveryMethodId' => '{deliveryMethodId}',
            'expectedLines' => [['variantId' => '{variantId}', 'quantity' => 1]],
        ];
        $operations = [
            self::GUEST => [null, 'guestCheckout', ['cartId' => '{cartId}', 'email' => 'anna.nowak@example.com', ...$details]],
            self::CUSTOMER => [InputCase::CUSTOMER, 'customerCheckout', $details],
        ];
        $headers = ['Idempotency-Key' => '{idempotencyKey}'];
        $id = ['id' => '{response.orderId}'];

        foreach ($operations as $operation => [$token, $fixture, $body]) {
            yield TextRow::required($operation, $token, $fixture, 'fullName', 'Anna Nowak', $body,
                empty: 'Imię i nazwisko są wymagane.',
                forbidden: 'Imię i nazwisko zawierają niedozwolone znaki.',
                wrongType: 'Imię i nazwisko są wymagane.',
                stored: TextRow::column(\sprintf(self::ORDER, 'full_name'), $id), headers: $headers);
            yield TextRow::required($operation, $token, $fixture, 'street', 'ul. Długa 5/3', $body,
                empty: 'Ulica i numer są wymagane.',
                forbidden: 'Ulica i numer zawierają niedozwolone znaki.',
                wrongType: 'Ulica i numer są wymagane.',
                stored: TextRow::column(\sprintf(self::ORDER, 'street'), $id), headers: $headers);
            yield TextRow::required($operation, $token, $fixture, 'city', 'Warszawa', $body,
                empty: 'Miejscowość jest wymagana.',
                forbidden: 'Miejscowość zawiera niedozwolone znaki.',
                wrongType: 'Miejscowość jest wymagana.',
                stored: TextRow::column(\sprintf(self::ORDER, 'city'), $id), headers: $headers);
            yield TextRow::format($operation, $token, $fixture, 'phone', $body,
                empty: 'Numer telefonu jest wymagany.',
                forbidden: self::PHONE,
                wrongType: self::PHONE,
                cases: self::phoneCases(),
                stored: TextRow::column(\sprintf(self::ORDER, 'phone'), $id), headers: $headers);
            yield TextRow::format($operation, $token, $fixture, 'postalCode', $body,
                empty: 'Kod pocztowy jest wymagany.',
                forbidden: self::POSTAL_CODE,
                wrongType: self::POSTAL_CODE,
                cases: self::postalCodeCases(),
                stored: TextRow::column(\sprintf(self::ORDER, 'postal_code'), $id), headers: $headers);
        }

        yield TextRow::format(self::GUEST, null, 'guestCheckout', 'email', $operations[self::GUEST][2],
            empty: 'Adres e-mail jest wymagany.',
            forbidden: self::EMAIL,
            wrongType: self::EMAIL,
            cases: self::emailCases(),
            stored: TextRow::column(\sprintf(self::ORDER, 'email'), $id), headers: $headers);
    }

    /**
     * The table of the phone of "Pola z formatem: przypadki"; its texts empty by T3 are the class empty of every row,
     * and the valid value with U+2028 at its end is added by the mechanism.
     *
     * @return list<array{string, string, int, string}>
     */
    private static function phoneCases(): array
    {
        $stored = '+48600100200';
        $format = self::PHONE;

        return [
            ['600100200', '600100200', 201, $stored],
            ['+48600100200', '+48600100200', 201, $stored],
            ['600 100 200', '600 100 200', 201, $stored],
            ['600-100-200', '600-100-200', 201, $stored],
            ['+48 600-100-200', '+48 600-100-200', 201, $stored],
            ['600100200 between U+00A0', "\u{A0}600100200\u{A0}", 201, $stored],
            ['600100200 between U+200B', "\u{200B}600100200\u{200B}", 201, $stored],
            ['600100200 after U+FEFF', "\u{FEFF}600100200", 201, $stored],
            ['+48 600 100 200 between U+00A0', "\u{A0}+48 600 100 200\u{A0}", 201, $stored],
            ['600, U+00A0, 100, U+00A0, 200', "600\u{A0}100\u{A0}200", 422, $format],
            ['600, U+200B, 100200', "600\u{200B}100200", 422, $format],
            ['600, U+009F, 100200', "600\u{9F}100200", 422, $format],
            ['60010020', '60010020', 422, $format],
            ['6001002000', '6001002000', 422, $format],
            ['+49600100200', '+49600100200', 422, $format],
        ];
    }

    /**
     * The table of the postal code of "Pola z formatem: przypadki", like phoneCases().
     *
     * @return list<array{string, string, int, string}>
     */
    private static function postalCodeCases(): array
    {
        $format = self::POSTAL_CODE;

        return [
            ['00-950', '00-950', 201, '00-950'],
            ['00-950 between ASCII spaces', ' 00-950 ', 201, '00-950'],
            ['00-950 between U+00A0', "\u{A0}00-950\u{A0}", 201, '00-950'],
            ['00-950 between U+200B', "\u{200B}00-950\u{200B}", 201, '00-950'],
            ['00-950 after U+FEFF', "\u{FEFF}00-950", 201, '00-950'],
            ['00-, U+00A0, 950', "00-\u{A0}950", 422, $format],
            ['00-, U+009F, 950', "00-\u{9F}950", 422, $format],
            ['00950', '00950', 422, $format],
            ['00 950', '00 950', 422, $format],
            ['00, U+2013, 950', "00\u{2013}950", 422, $format],
            ['00-9500', '00-9500', 422, $format],
        ];
    }

    /**
     * The table of the email of "Pola z formatem: przypadki", like phoneCases(); the letter case stays.
     *
     * @return list<array{string, string, int, string}>
     */
    private static function emailCases(): array
    {
        $address = 'anna.nowak@example.com';
        $format = self::EMAIL;
        // 64 + 1 + 189 characters: the longest local part and labels the mailer takes.
        $longest = str_repeat('a', 64).'@'.str_repeat('b', 61).'.'.str_repeat('c', 61).'.'.str_repeat('d', 61).'.com';

        return [
            [$address, $address, 201, $address],
            ['Anna.Nowak@Example.COM', 'Anna.Nowak@Example.COM', 201, 'Anna.Nowak@Example.COM'],
            ['the address between ASCII spaces', ' '.$address.' ', 201, $address],
            ['the address between U+00A0', "\u{A0}".$address."\u{A0}", 201, $address],
            ['the address between U+200B', "\u{200B}".$address."\u{200B}", 201, $address],
            ['the address after U+FEFF', "\u{FEFF}".$address, 201, $address],
            ['an address of 254 characters between U+00A0', "\u{A0}".$longest."\u{A0}", 201, $longest],
            ['an address of 255 characters', str_replace('.com', '.comm', $longest), 422, $format],
            ['anna, U+200B, nowak@example.com', "anna\u{200B}nowak@example.com", 422, $format],
            ['anna nowak@example.com', 'anna nowak@example.com', 422, $format],
            ['anna.nowak@, U+00A0, example.com', "anna.nowak@\u{A0}example.com", 422, $format],
            ['anna, U+009F, nowak@example.com', "anna\u{9F}nowak@example.com", 422, $format],
        ];
    }

    /**
     * The note of a change of the status (optional for a shipment, required for a cancellation and for a return) and of
     * the completion of a refund (optional), each read from the row it is stored in. The two required notes of one
     * operation and field differ by the variant of the row.
     *
     * @return iterable<TextRow>
     */
    private static function staffNotes(): iterable
    {
        $staff = InputCase::STAFF;
        $statusChange = 'POST /api/v1/admin/orders/{orderId}/status-changes';
        $history = TextRow::column('SELECT note FROM ordering_order_status_change WHERE order_id = :id ORDER BY position DESC LIMIT 1', ['id' => '{orderId}']);
        $change = ['transition' => 'ship', 'version' => 2, 'note' => null, 'trackingNumber' => null];

        yield TextRow::optional($statusChange, $staff, 'paidOrder', 'note', 'Kurier DPD', $change,
            noValue: null, forbidden: self::NOTE, wrongType: self::NOTE_TYPE, stored: $history);
        yield TextRow::required($statusChange, $staff, 'paidOrder', 'note', 'Brak towaru', ['transition' => 'cancel'] + $change,
            empty: 'Notatka jest wymagana.', forbidden: self::NOTE, wrongType: self::NOTE_TYPE, stored: $history);
        yield TextRow::required($statusChange, $staff, 'shippedOrder', 'note', 'Towar uszkodzony', ['transition' => 'return', 'version' => 3] + $change,
            empty: 'Notatka jest wymagana.', forbidden: self::NOTE, wrongType: self::NOTE_TYPE, stored: $history, variant: 'return');
        yield TextRow::optional('POST /api/v1/admin/orders/{orderId}/refund-completion', $staff, 'cancelledOrder', 'note', 'Przelew wysłany', ['version' => 3, 'note' => null],
            noValue: null, forbidden: self::NOTE, wrongType: self::NOTE_TYPE,
            stored: TextRow::column('SELECT refund_note FROM ordering_order WHERE id = :id', ['id' => '{orderId}']));
    }

    /**
     * @return iterable<TextRow>
     */
    private static function phrases(): iterable
    {
        $staff = InputCase::STAFF;
        $orders = 'Szukana fraza to numer zamówienia albo tekst od 3 do 100 znaków bez znaków sterujących.';
        $customers = 'Szukana fraza to fragment e-maila albo imienia i nazwiska, od 3 do 100 znaków bez znaków sterujących.';

        yield TextRow::phrase('GET /api/v1/admin/orders', $staff, 'searchedOrder', 'Wierzbięta', $orders, $orders,
            phrase: '{orderName}', record: '{orderId}', items: 'items', id: 'orderId', count: 'total.value');
        yield TextRow::phrase('GET /api/v1/admin/customers', $staff, 'searchedCustomer', 'Szczęsna', $customers, $customers,
            phrase: '{customerName}', record: '{customerId}', items: 'items', id: 'customerId', count: 'total.value');
    }
}
