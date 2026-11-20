<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\TrackingNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tracking number of a shipment (docs/features/delivery/spec.md, W8): ASCII letters, digits and hyphens, 1 to 40
 * characters once trimmed of Unicode white space; nothing but white space is no number.
 */
final class TrackingNumberTest extends TestCase
{
    private const string MESSAGE = 'Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki.';

    /**
     * @return iterable<string, array{string, string}> the input, the number kept
     */
    public static function validNumbers(): iterable
    {
        yield 'digits' => ['123456789', '123456789'];
        yield 'letters, digits and hyphens' => ['JJD0002-4455-AB', 'JJD0002-4455-AB'];
        yield 'one character' => ['A', 'A'];
        yield 'a hyphen only' => ['-', '-'];
        yield '40 characters' => [str_repeat('a', 40), str_repeat('a', 40)];
        yield 'ASCII white space at the ends' => [" \t123\n", '123'];
        yield 'NBSP at the ends' => ["\u{00A0}123\u{00A0}", '123'];
        yield 'other Unicode white space at the ends' => ["\u{2003}\u{3000}123\u{2028}", '123'];
        yield '40 characters with white space around' => ["\u{00A0}".str_repeat('9', 40)."\u{00A0}", str_repeat('9', 40)];
    }

    #[DataProvider('validNumbers')]
    public function testItKeepsAValidNumberTrimmed(string $input, string $kept): void
    {
        self::assertSame($kept, TrackingNumber::optional($input)?->toString());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noNumber(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'NBSP' => ["\u{00A0}"];
        yield 'mixed Unicode white space' => ["\u{00A0}\u{2003}\n\u{2029}"];
    }

    #[DataProvider('noNumber')]
    public function testNothingButWhiteSpaceIsNoNumber(?string $input): void
    {
        self::assertNull(TrackingNumber::optional($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield '41 characters' => [str_repeat('a', 41)];
        yield '41 characters after the trim' => [' '.str_repeat('a', 41).' '];
        yield 'a Polish letter' => ['ZAMÓWIENIE1'];
        yield 'a space inside' => ['123 456'];
        yield 'an underscore' => ['123_456'];
        yield 'a slash' => ['123/456'];
        yield 'a NUL' => ["123\0456"];
        yield 'a NUL at the end' => ["123\0"];
        yield 'U+2028 inside' => ["123\u{2028}456"];
        yield 'a NBSP inside' => ["123\u{00A0}456"];
        yield 'a line break inside' => ["123\n456"];
        yield 'not valid UTF-8' => ["12\xC3\x28"];
        yield 'a full-width digit' => ['１２３'];
    }

    #[DataProvider('invalidNumbers')]
    public function testItRefusesANumberOfAnotherShape(string $input): void
    {
        $this->expectException(InvalidOrderInput::class);
        $this->expectExceptionMessage(self::MESSAGE);

        TrackingNumber::optional($input);
    }

    public function testAStoredNumberIsReadBackAsItIs(): void
    {
        self::assertSame('JJD0002-4455', TrackingNumber::fromString('JJD0002-4455')->toString());
        self::assertTrue(TrackingNumber::fromString('A1')->equals(TrackingNumber::fromString('A1')));
        self::assertFalse(TrackingNumber::fromString('A1')->equals(TrackingNumber::fromString('A2')));
    }

    public function testAStoredNumberIsNotTrimmedOrRepaired(): void
    {
        $this->expectException(InvalidOrderInput::class);

        TrackingNumber::fromString(' A1');
    }
}
