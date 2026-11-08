<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\OrderConfirmationText;
use App\Ordering\Application\View\OrderDiscountView;
use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\OrderTotalsView;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Application\View\ShippingAddressView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderConfirmationTextTest extends TestCase
{
    public function testSubjectCarriesTheOrderNumber(): void
    {
        self::assertSame('Potwierdzenie zamówienia R-100001', (new OrderConfirmationText())->subject(self::view()));
    }

    public function testBodyListsTheOrderInPolish(): void
    {
        $view = self::view([
            self::line('Buty Asfalt', '42', 2, 123_456),
            self::line('Czapka', '', 1, 2_000),
        ], 125_456);

        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            dziękujemy za zamówienie R-100001 złożone 05.11.2026 o 13:00.

            Zamówione pozycje:
            - Buty Asfalt, 42 × 2: 1 234,56 zł
            - Czapka × 1: 20,00 zł

            Suma brutto: 1 254,56 zł

            Adres dostawy:
            Jan Kowalski
            Długa 1
            00-950 Warszawa
            Telefon: +48600100200

            Zamówienie czeka na płatność do 13:30 (05.11.2026). Bez płatności anulujemy je automatycznie.

            TEXT;
        self::assertSame($expected, (new OrderConfirmationText())->body($view));
    }

    public function testWithACodeTheItemsAreBeforeTheDiscountThenTheCodeLineAndTheTotalAfterIt(): void
    {
        $view = self::view([
            self::line('Buty Asfalt', '42', 2, 123_456, 12_345),
            self::line('Czapka', '', 1, 2_000, 200),
        ], 125_456 - 12_545, new OrderDiscountView('WITAJ10', 'percent', 10, 12_545));

        $expected = <<<'TEXT'
            Zamówione pozycje:
            - Buty Asfalt, 42 × 2: 1 234,56 zł
            - Czapka × 1: 20,00 zł
            Kod WITAJ10: −125,45 zł

            Suma brutto: 1 129,11 zł

            TEXT;
        self::assertStringContainsString($expected, (new OrderConfirmationText())->body($view));
    }

    public function testWithoutACodeThereIsNoCodeLine(): void
    {
        $body = (new OrderConfirmationText())->body(self::view([self::line('Czapka', '', 1, 2_000)], 2_000));

        self::assertStringNotContainsString('Kod ', $body);
        self::assertStringContainsString("\n- Czapka × 1: 20,00 zł\n\nSuma brutto: 20,00 zł\n", $body);
    }

    public function testTheDefaultVariantHasNeitherLabelNorSeparator(): void
    {
        $body = (new OrderConfirmationText())->body(self::view([self::line('Czapka', '', 3, 6_000)], 6_000));

        self::assertStringContainsString("\n- Czapka × 3: 60,00 zł\n", $body);
        self::assertStringNotContainsString('Czapka,', $body);
    }

    public function testTheVariantLabelFollowsTheNameAfterAComma(): void
    {
        $body = (new OrderConfirmationText())->body(self::view([self::line('Koszulka', 'M, niebieska', 1, 9_900)], 9_900));

        self::assertStringContainsString("\n- Koszulka, M, niebieska × 1: 99,00 zł\n", $body);
    }

    #[DataProvider('moments')]
    public function testTheMomentIsShownInWarsawTime(string $placedAt, string $expected): void
    {
        $body = (new OrderConfirmationText())->body(self::view(placedAt: new \DateTimeImmutable($placedAt)));

        self::assertStringContainsString('złożone '.$expected.'.', $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function moments(): iterable
    {
        yield 'winter time (UTC+1)' => ['2026-11-05 12:00:00+00:00', '05.11.2026 o 13:00'];
        yield 'summer time (UTC+2)' => ['2026-07-01 12:05:00+00:00', '01.07.2026 o 14:05'];
        yield 'the next day in Warsaw' => ['2026-12-31 23:30:00+00:00', '01.01.2027 o 00:30'];
        yield 'a moment given in another zone' => ['2026-11-05 07:00:00-05:00', '05.11.2026 o 13:00'];
    }

    #[DataProvider('deadlines')]
    public function testTheDeadlineIsShownInWarsawTime(string $deadline, string $expected): void
    {
        $body = (new OrderConfirmationText())->body(self::view(deadline: new \DateTimeImmutable($deadline)));

        self::assertStringContainsString("\nZamówienie czeka na płatność do ".$expected.'. Bez płatności anulujemy je automatycznie.'."\n", $body);
        self::assertStringNotContainsString('O płatności poinformujemy', $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deadlines(): iterable
    {
        yield 'winter time (UTC+1)' => ['2026-11-05 12:30:00+00:00', '13:30 (05.11.2026)'];
        yield 'summer time (UTC+2)' => ['2026-07-01 12:35:00+00:00', '14:35 (01.07.2026)'];
        yield 'the next day in Warsaw' => ['2026-12-31 23:20:00+00:00', '00:20 (01.01.2027)'];
        yield 'a moment given in another zone' => ['2026-11-05 07:30:00-05:00', '13:30 (05.11.2026)'];
    }

    #[DataProvider('amounts')]
    public function testAmountsAreFormattedAsGroszeWithSpacesAndAComma(int $grosze, string $expected): void
    {
        $body = (new OrderConfirmationText())->body(self::view([self::line('Czapka', '', 1, $grosze)], $grosze));

        self::assertStringContainsString("\n- Czapka × 1: ".$expected."\n", $body);
        self::assertStringContainsString("\nSuma brutto: ".$expected."\n", $body);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'under a złoty' => [5, '0,05 zł'];
        yield 'whole złoty' => [100, '1,00 zł'];
        yield 'below a thousand' => [99_999, '999,99 zł'];
        yield 'thousands' => [123_456, '1 234,56 zł'];
        yield 'a million' => [100_000_000, '1 000 000,00 zł'];
    }

    public function testTheSeparatorOfThousandsIsAPlainSpace(): void
    {
        $body = (new OrderConfirmationText())->body(self::view([self::line('Czapka', '', 1, 123_456)], 123_456));

        self::assertStringContainsString("1\x20234,56\x20zł", $body);
        self::assertStringNotContainsString("\u{00A0}", $body);
        self::assertStringNotContainsString("\u{202F}", $body);
    }

    /**
     * @param list<OrderLineView> $lines
     */
    private static function view(array $lines = [], int $gross = 0, ?OrderDiscountView $discount = null, ?\DateTimeImmutable $placedAt = null, ?\DateTimeImmutable $deadline = null): OrderView
    {
        return new OrderView(
            '0192f0c4-7c1a-7c4e-9a6e-0000000000aa',
            'R-100001',
            'new',
            'jan@example.com',
            'Jan Kowalski',
            '+48600100200',
            new ShippingAddressView('Długa 1', '00-950', 'Warszawa', 'PL'),
            [] === $lines ? [self::line('Czapka', '', 1, 100)] : $lines,
            $discount,
            new OrderTotalsView(0, 0, $gross, 'PLN'),
            $placedAt ?? new \DateTimeImmutable('2026-11-05 12:00:00+00:00'),
            $deadline ?? ($placedAt ?? new \DateTimeImmutable('2026-11-05 12:00:00+00:00'))->modify('+30 minutes'),
            null,
        );
    }

    private static function line(string $name, string $label, int $quantity, int $grossBeforeDiscount, int $discount = 0): OrderLineView
    {
        return new OrderLineView('0192f0c4-7c1a-7c4e-9a6e-000000000001', $name, $label, $quantity, intdiv($grossBeforeDiscount, $quantity), $grossBeforeDiscount, $discount, $grossBeforeDiscount - $discount, null);
    }
}
