<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\UI;

use App\Ordering\UI\Http\ChangeOrderStatusRequest;
use App\Ordering\UI\Http\CompleteRefundRequest;
use App\Ordering\UI\Http\PlaceCustomerOrderRequest;
use App\Ordering\UI\Http\PlaceGuestOrderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The texts of the requests refuse the characters the domain refuses, with the message of the field. A JSON body
 * cannot carry a text that is not valid UTF-8 (the decoder answers 400), so that case is tried on the objects here.
 */
final class ForbiddenCharactersInRequestsTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbidden(): iterable
    {
        yield 'next line U+0085' => ["A\u{0085}B"];
        yield 'line separator U+2028' => ["A\u{2028}B"];
        yield 'paragraph separator U+2029' => ["A\u{2029}B"];
        yield 'line separator at the end' => ["AB\u{2028}"];
        yield 'lone continuation byte' => ["A\x80B"];
        yield 'truncated sequence at the end' => ["AB\xC3"];
        yield 'only invalid bytes' => ["\xFF"];
        yield 'NUL' => ["A\0B"];
        // Every control character of the rule of texts (spec api-input-contract, T1), also C1.
        yield 'C1 control U+009F' => ["A\u{9F}B"];
        yield 'C1 control U+0080 at the end' => ["AB\u{80}"];
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

    #[DataProvider('forbidden')]
    public function testTheNoteOfAStatusChangeIsRefused(string $note): void
    {
        foreach (['ship', 'cancel'] as $transition) {
            self::assertSame([['note', 'Notatka zawiera niedozwolone znaki.']], $this->violations(new ChangeOrderStatusRequest($transition, 2, $note, null)), $transition);
        }
    }

    #[DataProvider('forbidden')]
    public function testTheNoteOfARefundCompletionIsRefused(string $note): void
    {
        self::assertSame([['note', 'Notatka zawiera niedozwolone znaki.']], $this->violations(new CompleteRefundRequest(3, $note)));
    }

    public function testANoteOfNonBreakingSpacesIsNoNoteForACancellationAndAReturn(): void
    {
        foreach (['cancel', 'return'] as $transition) {
            foreach (["\u{00A0}", "\u{00A0} \u{3000}\u{2003}"] as $note) {
                self::assertSame([['note', 'Notatka jest wymagana.']], $this->violations(new ChangeOrderStatusRequest($transition, 2, $note, null)), $transition);
            }
        }
        self::assertSame([], $this->violations(new ChangeOrderStatusRequest('ship', 2, "\u{00A0}", null)));
        self::assertSame([], $this->violations(new CompleteRefundRequest(3, "\u{00A0}")));
    }

    /**
     * Texts without a visible character once trimmed by the rule of texts (T3).
     *
     * @return iterable<string, array{string}>
     */
    public static function emptyTexts(): iterable
    {
        yield 'a zero width space' => ["\u{200B}"];
        yield 'a Hangul filler' => ["\u{3164}"];
        yield 'invisible characters and spaces' => ["\u{FEFF} \u{200B}\u{2060}"];
    }

    #[DataProvider('emptyTexts')]
    public function testANoteWithoutAVisibleCharacterIsNoNote(string $note): void
    {
        foreach (['cancel', 'return'] as $transition) {
            self::assertSame([['note', 'Notatka jest wymagana.']], $this->violations(new ChangeOrderStatusRequest($transition, 2, $note, null)), $transition);
        }
        self::assertSame([], $this->violations(new ChangeOrderStatusRequest('ship', 2, $note, null)));
        self::assertSame([], $this->violations(new CompleteRefundRequest(3, $note)));
    }

    #[DataProvider('emptyTexts')]
    public function testATextOfAnOrderWithoutAVisibleCharacterIsMissing(string $empty): void
    {
        $messages = [
            'email' => 'Adres e-mail jest wymagany.',
            'fullName' => 'Imię i nazwisko są wymagane.',
            'phone' => 'Numer telefonu jest wymagany.',
            'street' => 'Ulica i numer są wymagane.',
            'postalCode' => 'Kod pocztowy jest wymagany.',
            'city' => 'Miejscowość jest wymagana.',
        ];
        foreach ($messages as $field => $message) {
            self::assertSame([[$field, $message]], $this->violations(self::guestOrder([$field => $empty])), $field);
            if ('email' !== $field) {
                self::assertSame([[$field, $message]], $this->violations(self::customerOrder([$field => $empty])), $field);
            }
        }
    }

    /**
     * The texts of an order between the characters the rule of texts trims (T2), and the fields with a format among
     * them, have no violation; the same characters inside a value with a format break the format.
     */
    public function testTheTextsOfAnOrderBetweenTheEdgesOfTheRuleAreValid(): void
    {
        foreach (["\u{A0}", "\u{200B}", "\u{FEFF}", "\u{AD}\u{3000}"] as $edge) {
            $between = static fn (string $value): string => $edge.$value.$edge;
            $fields = ['email' => $between('anna.nowak@example.com'), 'fullName' => $between('Anna Nowak'), 'phone' => $between('+48 600 100 200'), 'street' => $between('ul. Długa 5/3'), 'postalCode' => $between('00-950'), 'city' => $between('Warszawa')];

            self::assertSame([], $this->violations(self::guestOrder($fields)), \sprintf('U+%04X', mb_ord($edge)));
            self::assertSame([], $this->violations(self::customerOrder($fields)), \sprintf('U+%04X', mb_ord($edge)));
        }
        self::assertSame([['phone', 'Podaj 9-cyfrowy numer telefonu.']], $this->violations(self::guestOrder(['phone' => "600\u{200B}100200"])));
        self::assertSame([['postalCode', 'Podaj kod pocztowy w formacie 00-000.']], $this->violations(self::guestOrder(['postalCode' => "00-\u{A0}950"])));
        self::assertSame([['email', 'Podaj poprawny adres e-mail.']], $this->violations(self::guestOrder(['email' => "anna\u{200B}nowak@example.com"])));
    }

    /**
     * A field with a format answers a forbidden character with the message of its format, also at the end of a valid
     * value, where the trim of the ends would drop it (T1 looks at the value as it came).
     */
    public function testAFieldWithAFormatRefusesAForbiddenCharacterAtTheEndOfAValidValue(): void
    {
        foreach (["\u{2028}", "\u{85}", "\u{9F}", "\t"] as $character) {
            self::assertSame([['email', 'Podaj poprawny adres e-mail.']], $this->violations(self::guestOrder(['email' => 'anna.nowak@example.com'.$character])));
            self::assertSame([['phone', 'Podaj 9-cyfrowy numer telefonu.']], $this->violations(self::guestOrder(['phone' => '600100200'.$character])));
            self::assertSame([['postalCode', 'Podaj kod pocztowy w formacie 00-000.']], $this->violations(self::customerOrder(['postalCode' => '00-950'.$character])));
        }
    }

    public function testTheLengthOfANoteIsCountedAfterATrimOfUnicodeWhiteSpace(): void
    {
        $edges = "\u{00A0}\u{3000}\u{200B}";

        self::assertSame([], $this->violations(new ChangeOrderStatusRequest('cancel', 2, $edges.str_repeat('ż', 500).$edges, null)));
        self::assertSame([], $this->violations(new CompleteRefundRequest(3, $edges.str_repeat('ż', 500).$edges)));
        self::assertSame([['note', 'Notatka może mieć najwyżej 500 znaków.']], $this->violations(new ChangeOrderStatusRequest('cancel', 2, $edges.str_repeat('ż', 501).$edges, null)));
        self::assertSame([['note', 'Notatka może mieć najwyżej 500 znaków.']], $this->violations(new CompleteRefundRequest(3, $edges.str_repeat('ż', 501).$edges)));
    }

    /**
     * @return iterable<string, array{string, string}> field and message
     */
    public static function textFields(): iterable
    {
        yield 'email' => ['email', 'Podaj poprawny adres e-mail.'];
        yield 'fullName' => ['fullName', 'Imię i nazwisko zawierają niedozwolone znaki.'];
        yield 'street' => ['street', 'Ulica i numer zawierają niedozwolone znaki.'];
        yield 'postalCode' => ['postalCode', 'Podaj kod pocztowy w formacie 00-000.'];
        yield 'city' => ['city', 'Miejscowość zawiera niedozwolone znaki.'];
    }

    #[DataProvider('textFields')]
    public function testTheTextsOfAGuestOrderRefuseForbiddenCharacters(string $field, string $message): void
    {
        foreach (self::forbidden() as $name => [$value]) {
            $text = 'email' === $field ? 'jan'.$value.'@example.com' : $value;
            $fields = ['email' => 'jan@example.com', 'fullName' => 'Jan Kowalski', 'street' => 'Długa 1', 'postalCode' => '00-950', 'city' => 'Warszawa', $field => $text];
            $request = new PlaceGuestOrderRequest('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', $fields['email'], $fields['fullName'], '600100200', $fields['street'], $fields['postalCode'], $fields['city'], 1000, '0192f0c4-7c1a-7c4e-9a6e-00000000de10', [['variantId' => '0192f0c4-7c1a-7c4e-9a6e-00000000a001', 'quantity' => 1]]);

            self::assertSame([[$field, $message]], $this->violations($request), $name);
        }
    }

    /**
     * @return iterable<string, array{string, string}> the fields of a customer order: the email comes from the token
     */
    public static function customerTextFields(): iterable
    {
        foreach (self::textFields() as $name => $field) {
            if ('email' !== $name) {
                yield $name => $field;
            }
        }
    }

    #[DataProvider('customerTextFields')]
    public function testTheTextsOfACustomerOrderRefuseForbiddenCharacters(string $field, string $message): void
    {
        foreach (self::forbidden() as $name => [$value]) {
            $fields = ['fullName' => 'Jan Kowalski', 'street' => 'Długa 1', 'postalCode' => '00-950', 'city' => 'Warszawa', $field => $value];
            $request = new PlaceCustomerOrderRequest($fields['fullName'], '600100200', $fields['street'], $fields['postalCode'], $fields['city'], 1000, '0192f0c4-7c1a-7c4e-9a6e-00000000de10', [['variantId' => '0192f0c4-7c1a-7c4e-9a6e-00000000a001', 'quantity' => 1]]);

            self::assertSame([[$field, $message]], $this->violations($request), $name);
        }
    }

    /**
     * @param array<string, string> $fields the texts that replace those of a valid body
     */
    private static function guestOrder(array $fields): PlaceGuestOrderRequest
    {
        $fields += ['email' => 'jan@example.com', 'fullName' => 'Jan Kowalski', 'phone' => '600100200', 'street' => 'Długa 1', 'postalCode' => '00-950', 'city' => 'Warszawa'];

        return new PlaceGuestOrderRequest('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', $fields['email'], $fields['fullName'], $fields['phone'], $fields['street'], $fields['postalCode'], $fields['city'], 1000, '0192f0c4-7c1a-7c4e-9a6e-00000000de10', [['variantId' => '0192f0c4-7c1a-7c4e-9a6e-00000000a001', 'quantity' => 1]]);
    }

    /**
     * @param array<string, string> $fields the texts that replace those of a valid body; an email is ignored
     */
    private static function customerOrder(array $fields): PlaceCustomerOrderRequest
    {
        $fields += ['fullName' => 'Jan Kowalski', 'phone' => '600100200', 'street' => 'Długa 1', 'postalCode' => '00-950', 'city' => 'Warszawa'];

        return new PlaceCustomerOrderRequest($fields['fullName'], $fields['phone'], $fields['street'], $fields['postalCode'], $fields['city'], 1000, '0192f0c4-7c1a-7c4e-9a6e-00000000de10', [['variantId' => '0192f0c4-7c1a-7c4e-9a6e-00000000a001', 'quantity' => 1]]);
    }
}
