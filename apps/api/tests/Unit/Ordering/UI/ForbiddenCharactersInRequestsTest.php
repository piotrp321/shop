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
            self::assertSame([['note', 'Notatka zawiera niedozwolone znaki.']], $this->violations(new ChangeOrderStatusRequest($transition, 2, $note)), $transition);
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
                self::assertSame([['note', 'Notatka jest wymagana.']], $this->violations(new ChangeOrderStatusRequest($transition, 2, $note)), $transition);
            }
        }
        self::assertSame([], $this->violations(new ChangeOrderStatusRequest('ship', 2, "\u{00A0}")));
        self::assertSame([], $this->violations(new CompleteRefundRequest(3, "\u{00A0}")));
    }

    public function testTheLengthOfANoteIsCountedAfterATrimOfUnicodeWhiteSpace(): void
    {
        $edges = "\u{00A0}\u{3000}";

        self::assertSame([], $this->violations(new ChangeOrderStatusRequest('cancel', 2, $edges.str_repeat('ż', 500).$edges)));
        self::assertSame([], $this->violations(new CompleteRefundRequest(3, $edges.str_repeat('ż', 500).$edges)));
        self::assertSame([['note', 'Notatka może mieć najwyżej 500 znaków.']], $this->violations(new ChangeOrderStatusRequest('cancel', 2, $edges.str_repeat('ż', 501).$edges)));
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
            $request = new PlaceGuestOrderRequest('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', $fields['email'], $fields['fullName'], '600100200', $fields['street'], $fields['postalCode'], $fields['city'], 1000);

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
            $request = new PlaceCustomerOrderRequest($fields['fullName'], '600100200', $fields['street'], $fields['postalCode'], $fields['city'], 1000);

            self::assertSame([[$field, $message]], $this->violations($request), $name);
        }
    }
}
