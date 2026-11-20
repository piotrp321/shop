<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\UI;

use App\Ordering\UI\Http\ChangeOrderStatusRequest;
use App\Ordering\UI\Http\ChangeTrackingNumberRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The tracking number in the requests of the panel (docs/features/delivery/spec.md, W8 and W8a): nothing or white space
 * is no number; only a shipment takes one, and then a text of ASCII letters, digits and hyphens (1 to 40) once
 * trimmed. A value that is not allowed for the transition gets the message of the transition before the one of the
 * format. One violation at most, at the field.
 */
final class TrackingNumberRequestsTest extends TestCase
{
    private const string FORMAT = 'Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki.';
    private const string TEXT = 'Numer przesyłki musi być tekstem.';
    private const string ONLY_SHIPMENT = 'Numer przesyłki można podać tylko przy wysyłce.';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @return iterable<string, array{mixed}> values that are no number
     */
    public static function noNumber(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'NBSP' => ["\u{00A0}"];
        yield 'Unicode white space' => ["\u{2003}\u{2028}\u{3000}"];
    }

    #[DataProvider('noNumber')]
    public function testNoNumberIsValidForEveryTransition(mixed $value): void
    {
        foreach (ChangeOrderStatusRequest::TRANSITIONS as $transition) {
            $note = \in_array($transition, ['cancel', 'return'], true) ? 'Notatka' : null;

            self::assertSame([], $this->violations(new ChangeOrderStatusRequest($transition, 2, $note, $value)), $transition);
        }
        self::assertSame([], $this->violations(new ChangeTrackingNumberRequest($value, 2)));
    }

    /**
     * @return iterable<string, array{mixed}> values a shipment takes
     */
    public static function numbers(): iterable
    {
        yield 'digits' => ['123456789'];
        yield 'letters, digits and hyphens' => ['JJD0002-44'];
        yield '40 characters' => [str_repeat('A', 40)];
        yield 'trimmed' => ["\u{00A0}JJD-1 "];
    }

    #[DataProvider('numbers')]
    public function testAShipmentTakesANumber(mixed $value): void
    {
        self::assertSame([], $this->violations(new ChangeOrderStatusRequest('ship', 2, null, $value)));
        self::assertSame([], $this->violations(new ChangeTrackingNumberRequest($value, 2)));
    }

    /**
     * @return iterable<string, array{mixed, string}> a value of a shipment and the message of its violation
     */
    public static function refusedForAShipment(): iterable
    {
        yield 'a number' => [123, self::TEXT];
        yield 'a float' => [1.5, self::TEXT];
        yield 'true' => [true, self::TEXT];
        yield 'false' => [false, self::TEXT];
        yield 'a list' => [[], self::TEXT];
        yield 'an object' => [['a' => 1], self::TEXT];
        yield '41 characters' => [str_repeat('a', 41), self::FORMAT];
        yield 'Polish letters' => ['PRZESYŁKA', self::FORMAT];
        yield 'a space inside' => ['123 456', self::FORMAT];
        yield 'NUL' => ["123\0456", self::FORMAT];
        yield 'U+2028 inside' => ["123\u{2028}456", self::FORMAT];
        yield 'NBSP inside' => ["123\u{00A0}456", self::FORMAT];
        yield 'not valid UTF-8' => ["12\xC3\x28", self::FORMAT];
        yield 'a slash' => ['12/34', self::FORMAT];
    }

    #[DataProvider('refusedForAShipment')]
    public function testAShipmentRefusesAWrongValue(mixed $value, string $message): void
    {
        self::assertSame([['trackingNumber', $message]], $this->violations(new ChangeOrderStatusRequest('ship', 2, null, $value)));
        self::assertSame([['trackingNumber', $message]], $this->violations(new ChangeTrackingNumberRequest($value, 2)));
    }

    /**
     * @return iterable<string, array{mixed}> every value, whatever its format or type, but no number
     */
    public static function anyValue(): iterable
    {
        yield 'a good number' => ['JJD-1'];
        yield 'a bad format' => ['PRZESYŁKA 1'];
        yield 'too long' => [str_repeat('a', 41)];
        yield 'NUL' => ["1\0"];
        yield 'a number' => [123];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'a list' => [[]];
        yield 'an object' => [['a' => 1]];
    }

    #[DataProvider('anyValue')]
    public function testOtherTransitionsTakeNoNumberOfAnyShape(mixed $value): void
    {
        foreach (['deliver', 'cancel', 'return'] as $transition) {
            $note = 'deliver' === $transition ? null : 'Notatka';

            self::assertSame([['trackingNumber', self::ONLY_SHIPMENT]], $this->violations(new ChangeOrderStatusRequest($transition, 2, $note, $value)), $transition);
        }
    }

    public function testAnUnknownTransitionHasItsOwnViolationOnly(): void
    {
        self::assertSame([['transition', 'Wybierz zmianę statusu: wysłane, dostarczone, anulowanie albo zwrot.']], $this->violations(new ChangeOrderStatusRequest('fly', 2, null, 'JJD-1')));
    }

    public function testTheVersionOfTheTrackingNumberRequestIsChecked(): void
    {
        self::assertSame([['version', 'Wersja zamówienia jest wymagana.']], $this->violations(new ChangeTrackingNumberRequest('JJD-1', null)));
        self::assertSame([['version', 'Wersja zamówienia musi być liczbą całkowitą od 1.']], $this->violations(new ChangeTrackingNumberRequest('JJD-1', 'x')));
        self::assertSame([['version', 'Wersja zamówienia musi być liczbą całkowitą od 1.']], $this->violations(new ChangeTrackingNumberRequest('JJD-1', 0)));
    }

    /**
     * @return list<array{string, string}> path and message of every violation
     */
    private function violations(object $request): array
    {
        $found = [];
        foreach ($this->validator->validate($request) as $violation) {
            $found[] = [$violation->getPropertyPath(), (string) $violation->getMessage()];
        }

        return $found;
    }
}
