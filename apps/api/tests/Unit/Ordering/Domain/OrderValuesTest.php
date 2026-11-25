<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\ExpectedLines;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\Money;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\VariantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phones(): iterable
    {
        yield 'nine digits' => ['600100200', '+48600100200'];
        yield 'with spaces' => ['600 100 200', '+48600100200'];
        yield 'with hyphens' => ['600-100-200', '+48600100200'];
        yield 'with +48' => ['+48600100200', '+48600100200'];
        yield 'with +48, spaces and hyphens' => [' +48 600-100 200 ', '+48600100200'];
    }

    #[DataProvider('phones')]
    public function testPhoneIsNormalisedToPlus48AndNineDigits(string $phone, string $stored): void
    {
        self::assertSame($stored, self::contact(phone: $phone)->phone());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPhones(): iterable
    {
        yield 'empty' => [''];
        yield '8 digits' => ['60010020'];
        yield '10 digits' => ['6001002001'];
        yield '48 without plus' => ['48600100200'];
        yield 'other country' => ['+49600100200'];
        yield 'letters' => ['600 100 2OO'];
        yield 'dots' => ['600.100.200'];
    }

    #[DataProvider('invalidPhones')]
    public function testPhoneOtherThanNineDigitsIsRefused(string $phone): void
    {
        $this->expectException(InvalidOrderInput::class);
        $this->expectExceptionMessage('Podaj 9-cyfrowy numer telefonu.');

        self::contact(phone: $phone);
    }

    public function testContactIsTrimmed(): void
    {
        $contact = Contact::of(EmailAddress::fromString('  jan@example.com '), '  Jan Kowalski  ', '600100200');

        self::assertSame('jan@example.com', $contact->email()->toString());
        self::assertSame('Jan Kowalski', $contact->fullName());
    }

    public function testFullNameHasOneToHundredCharactersAfterTrimming(): void
    {
        self::assertSame(str_repeat('ż', 100), self::contact(fullName: ' '.str_repeat('ż', 100).' ')->fullName());
        foreach (['', '   ', str_repeat('a', 101)] as $fullName) {
            try {
                self::contact(fullName: $fullName);
                self::fail('Expected InvalidOrderInput for a full name of '.mb_strlen($fullName).' characters.');
            } catch (InvalidOrderInput $invalid) {
                self::assertStringStartsWith('Imię i nazwisko', $invalid->getMessage());
            }
        }
    }

    public function testEmailIsTrimmedNeedsAnAtAndAtMost254Characters(): void
    {
        $longest = str_repeat('a', 64).'@'.str_repeat('b', 185).'.com';
        self::assertSame(254, \strlen($longest));
        self::assertSame($longest, EmailAddress::fromString(' '.$longest.' ')->toString());

        foreach (['', '  ', 'jan.example.com', $longest.'m'] as $email) {
            try {
                EmailAddress::fromString($email);
                self::fail('Expected InvalidOrderInput for '.$email);
            } catch (InvalidOrderInput $invalid) {
                self::assertSame('Podaj poprawny adres e-mail.', $invalid->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function withControlCharacters(): iterable
    {
        yield 'NUL inside' => ["A\0B"];
        yield 'NUL at the end, which a trim would drop' => ["AB\0"];
        yield 'tab at the end' => ["AB\t"];
        yield 'line break at the start' => ["\nAB"];
        yield 'carriage return inside' => ["A\rB"];
        yield 'unit separator' => ["A\x1FB"];
        yield 'DEL' => ["A\x7FB"];
        yield 'next line U+0085' => ["A\u{0085}B"];
        yield 'line separator U+2028' => ["A\u{2028}B"];
        yield 'paragraph separator U+2029' => ["A\u{2029}B"];
        yield 'line separator U+2028 at the end' => ["AB\u{2028}"];
        yield 'paragraph separator U+2029 at the start' => ["\u{2029}AB"];
        yield 'lone continuation byte, not valid UTF-8' => ["A\x80B"];
        yield 'truncated sequence at the end, not valid UTF-8' => ["AB\xC3"];
        yield 'overlong sequence, not valid UTF-8' => ["A\xC0\xAFB"];
        // Every control character of the rule of texts (spec api-input-contract, T1), also C1.
        yield 'C1 control U+009F inside' => ["A\u{9F}B"];
        yield 'C1 control U+0080 at the end' => ["AB\u{80}"];
    }

    #[DataProvider('withControlCharacters')]
    public function testFullNameWithAControlCharacterIsRefused(string $fullName): void
    {
        $this->expectException(InvalidOrderInput::class);
        $this->expectExceptionMessage('Imię i nazwisko zawierają niedozwolone znaki.');

        self::contact(fullName: $fullName);
    }

    #[DataProvider('withControlCharacters')]
    public function testStreetAndCityWithAControlCharacterAreRefused(string $value): void
    {
        foreach ([[$value, 'Warszawa', 'Ulica i numer zawierają niedozwolone znaki.'], ['Długa 1', $value, 'Miejscowość zawiera niedozwolone znaki.']] as [$street, $city, $message]) {
            try {
                ShippingAddress::inPoland($street, '00-950', $city);
                self::fail('Expected InvalidOrderInput.');
            } catch (InvalidOrderInput $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
        }
    }

    #[DataProvider('withControlCharacters')]
    public function testEmailWithAControlCharacterIsRefusedAlsoAtTheEnds(string $value): void
    {
        foreach (['jan'.$value.'@example.com', 'jan@example.com'.$value, $value.'jan@example.com'] as $email) {
            try {
                EmailAddress::fromString($email);
                self::fail('Expected InvalidOrderInput.');
            } catch (InvalidOrderInput $invalid) {
                self::assertSame('Podaj poprawny adres e-mail.', $invalid->getMessage());
            }
        }
    }

    /**
     * The control character is the only thing wrong with an otherwise valid value, also at the ends, where a trim
     * would drop it before the format is checked.
     *
     * @return iterable<string, array{string}>
     */
    public static function controlCharactersAlone(): iterable
    {
        yield 'NUL' => ["\0"];
        yield 'tab' => ["\t"];
        yield 'line feed' => ["\n"];
        yield 'carriage return' => ["\r"];
        yield 'DEL' => ["\x7F"];
        yield 'U+0085' => ["\u{0085}"];
        yield 'U+2028' => ["\u{2028}"];
        yield 'U+2029' => ["\u{2029}"];
        yield 'not valid UTF-8' => ["\xC3"];
        yield 'C1 control U+009F' => ["\u{9F}"];
    }

    #[DataProvider('controlCharactersAlone')]
    public function testPostalCodeWithOnlyAControlCharacterWrongIsRefused(string $control): void
    {
        foreach (['00-950'.$control, $control.'00-950', '00-9'.$control.'50'] as $postalCode) {
            try {
                ShippingAddress::inPoland('Długa 1', $postalCode, 'Warszawa');
                self::fail('Expected InvalidOrderInput.');
            } catch (InvalidOrderInput $invalid) {
                self::assertSame('Podaj kod pocztowy w formacie 00-000.', $invalid->getMessage());
            }
        }
    }

    #[DataProvider('controlCharactersAlone')]
    public function testPhoneWithOnlyAControlCharacterWrongIsRefused(string $control): void
    {
        foreach (['600100200'.$control, $control.'600100200', '600'.$control.'100200'] as $phone) {
            try {
                self::contact(phone: $phone);
                self::fail('Expected InvalidOrderInput.');
            } catch (InvalidOrderInput $invalid) {
                self::assertSame('Podaj 9-cyfrowy numer telefonu.', $invalid->getMessage());
            }
        }
    }

    public function testAControlCharacterDoesNotChangeWhichMessageAnEmptyOrTooLongValueGets(): void
    {
        foreach (["\n", "\0", " \t "] as $blank) {
            try {
                self::contact(fullName: $blank);
                self::fail('Expected InvalidOrderInput.');
            } catch (InvalidOrderInput $invalid) {
                self::assertSame('Imię i nazwisko są wymagane.', $invalid->getMessage());
            }
        }
    }

    /**
     * Texts without a visible character once trimmed (spec api-input-contract, T3).
     *
     * @return iterable<string, array{string}>
     */
    public static function emptyTexts(): iterable
    {
        yield 'a no-break space' => ["\u{A0}"];
        yield 'a zero width space' => ["\u{200B}"];
        yield 'a Hangul filler' => ["\u{3164}"];
        yield 'invisible characters and spaces' => ["\u{FEFF} \u{200B}\u{2060}\u{3000}"];
    }

    #[DataProvider('emptyTexts')]
    public function testATextWithoutAVisibleCharacterIsNoValue(string $empty): void
    {
        $cases = [
            'Imię i nazwisko są wymagane.' => static fn () => self::contact(fullName: $empty),
            'Ulica i numer są wymagane.' => static fn () => ShippingAddress::inPoland($empty, '00-950', 'Warszawa'),
            'Kod pocztowy jest wymagany.' => static fn () => ShippingAddress::inPoland('Długa 1', $empty, 'Warszawa'),
            'Miejscowość jest wymagana.' => static fn () => ShippingAddress::inPoland('Długa 1', '00-950', $empty),
            'Podaj poprawny adres e-mail.' => static fn () => EmailAddress::fromString($empty),
            'Podaj 9-cyfrowy numer telefonu.' => static fn () => self::contact(phone: $empty),
        ];
        foreach ($cases as $message => $build) {
            try {
                $build();
                self::fail('Expected InvalidOrderInput: '.$message);
            } catch (InvalidOrderInput $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
        }
    }

    /**
     * The characters the rule of texts trims from the ends (T2), not only the ASCII white space of trim().
     *
     * @return iterable<string, array{string}>
     */
    public static function edges(): iterable
    {
        yield 'no-break spaces' => ["\u{A0}"];
        yield 'zero width spaces' => ["\u{200B}"];
        yield 'a byte order mark' => ["\u{FEFF}"];
        yield 'soft hyphens' => ["\u{AD}"];
        yield 'an ideographic space and a word joiner' => ["\u{3000}\u{2060}"];
    }

    #[DataProvider('edges')]
    public function testTheContactAndTheAddressLoseTheEdgesOfTheRuleOfTexts(string $edge): void
    {
        $contact = Contact::of(EmailAddress::fromString($edge.'Jan.Kowalski@Example.com'.$edge), $edge.'Jan Kowalski'.$edge, $edge.'+48 600-100-200'.$edge);
        $address = ShippingAddress::inPoland($edge.'ul. Długa 1/2'.$edge, $edge.'00-950'.$edge, $edge.'Warszawa'.$edge);

        self::assertSame(['Jan.Kowalski@Example.com', 'Jan Kowalski', '+48600100200'], [$contact->email()->toString(), $contact->fullName(), $contact->phone()]);
        self::assertSame(['ul. Długa 1/2', '00-950', 'Warszawa'], [$address->street(), $address->postalCode(), $address->city()]);
    }

    public function testInsideATextNothingIsDroppedSoAFormatRefusesIt(): void
    {
        self::assertSame("Jan\u{A0}Kowalski", self::contact(fullName: "\u{200B}Jan\u{A0}Kowalski\u{200B}")->fullName());
        self::assertSame("ul.\u{200B}Długa 1", ShippingAddress::inPoland("\u{FEFF}ul.\u{200B}Długa 1\u{AD}", '00-950', 'Warszawa')->street());
        foreach ([
            'Podaj 9-cyfrowy numer telefonu.' => static fn () => self::contact(phone: "600\u{A0}100\u{A0}200"),
            'Podaj kod pocztowy w formacie 00-000.' => static fn () => ShippingAddress::inPoland('Długa 1', "00-\u{200B}950", 'Warszawa'),
        ] as $message => $build) {
            try {
                $build();
                self::fail('Expected InvalidOrderInput: '.$message);
            } catch (InvalidOrderInput $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
        }
    }

    public function testTheLimitsCountTheTextOnceTrimmed(): void
    {
        $edge = "\u{200B}\u{A0}";

        self::assertSame(str_repeat('ż', 100), self::contact(fullName: $edge.str_repeat('ż', 100).$edge)->fullName());
        self::assertSame(str_repeat('ł', 60), ShippingAddress::inPoland($edge.str_repeat('ś', 100).$edge, '00-950', $edge.str_repeat('ł', 60).$edge)->city());
        $longest = str_repeat('a', 64).'@'.str_repeat('b', 185).'.com';
        self::assertSame($longest, EmailAddress::fromString($edge.$longest.$edge)->toString());
    }

    /**
     * What an order read from the database keeps: the texts as stored, also those the rule refuses (spec
     * api-input-contract, E7), without a check and without a trim.
     */
    public function testTheStoredContactAndAddressAreKeptAsTheyAre(): void
    {
        $contact = Contact::stored(EmailAddress::stored(" jan@example.com\u{200B}"), "\u{A0}", "+48600100200\t");
        $address = ShippingAddress::stored("\u{200B}", "00-950\u{A0}", "War\tszawa\u{9F}");

        self::assertSame([" jan@example.com\u{200B}", "\u{A0}", "+48600100200\t"], [$contact->email()->toString(), $contact->fullName(), $contact->phone()]);
        self::assertSame(["\u{200B}", "00-950\u{A0}", "War\tszawa\u{9F}", 'PL'], [$address->street(), $address->postalCode(), $address->city(), $address->country()]);
    }

    public function testShippingAddressIsTrimmedAndAlwaysInPoland(): void
    {
        $address = ShippingAddress::inPoland('  ul. Długa 1/2 ', ' 00-950 ', '  Warszawa ');

        self::assertSame('ul. Długa 1/2', $address->street());
        self::assertSame('00-950', $address->postalCode());
        self::assertSame('Warszawa', $address->city());
        self::assertSame('PL', $address->country());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'empty street' => ['  ', '00-950', 'Warszawa', 'Ulica i numer są wymagane.'];
        yield 'long street' => [str_repeat('u', 101), '00-950', 'Warszawa', 'Ulica i numer mogą mieć najwyżej 100 znaków.'];
        yield 'empty postal code' => ['Długa 1', ' ', 'Warszawa', 'Kod pocztowy jest wymagany.'];
        yield 'postal code without hyphen' => ['Długa 1', '00950', 'Warszawa', 'Podaj kod pocztowy w formacie 00-000.'];
        yield 'postal code too long' => ['Długa 1', '00-9500', 'Warszawa', 'Podaj kod pocztowy w formacie 00-000.'];
        yield 'postal code with letters' => ['Długa 1', 'AB-950', 'Warszawa', 'Podaj kod pocztowy w formacie 00-000.'];
        yield 'empty city' => ['Długa 1', '00-950', '', 'Miejscowość jest wymagana.'];
        yield 'long city' => ['Długa 1', '00-950', str_repeat('m', 61), 'Miejscowość może mieć najwyżej 60 znaków.'];
    }

    #[DataProvider('invalidAddresses')]
    public function testShippingAddressRefusesInvalidParts(string $street, string $postalCode, string $city, string $message): void
    {
        $this->expectException(InvalidOrderInput::class);
        $this->expectExceptionMessage($message);

        ShippingAddress::inPoland($street, $postalCode, $city);
    }

    public function testLongestStreetAndCityAreAccepted(): void
    {
        $address = ShippingAddress::inPoland(str_repeat('ś', 100), '00-950', str_repeat('ł', 60));

        self::assertSame(100, mb_strlen($address->street()));
        self::assertSame(60, mb_strlen($address->city()));
    }

    public function testOrderNumberIsRAndASequenceValueFrom100001(): void
    {
        self::assertSame('R-100001', OrderNumber::fromSequence(100001)->toString());
        self::assertSame('R-2000000', OrderNumber::fromSequence(2_000_000)->toString());
        self::assertSame('R-100001', OrderNumber::fromString('R-100001')->toString());
        self::assertTrue(OrderNumber::fromString('R-100002')->equals(OrderNumber::fromSequence(100002)));
    }

    public function testOrderNumberBelow100001OrMalformedIsRefused(): void
    {
        foreach ([static fn () => OrderNumber::fromSequence(100000), static fn () => OrderNumber::fromSequence(0)] as $build) {
            try {
                $build();
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['100001', 'R-100000', 'R-0100001', 'r-100001', 'R-100001 ', 'R-1e6'] as $number) {
            try {
                OrderNumber::fromString($number);
                self::fail('Expected InvalidArgumentException for '.$number);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOrderOwnerIsAGuestOrACustomerWithASub(): void
    {
        self::assertTrue(OrderOwner::guest()->isGuest());
        self::assertNull(OrderOwner::guest()->customerId());
        $customer = OrderOwner::customer(str_repeat('s', 255));
        self::assertFalse($customer->isGuest());
        self::assertSame(str_repeat('s', 255), $customer->customerId());

        foreach (['', str_repeat('s', 256)] as $sub) {
            try {
                OrderOwner::customer($sub);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIdempotencyKeyHasOneToHundredAllowedCharacters(): void
    {
        $key = 'Ab0._:-'.str_repeat('k', 93);
        self::assertSame($key, IdempotencyKey::fromString($key)->toString());

        foreach (['', $key.'k', 'key with space', 'klucz-ż', "key\n"] as $invalid) {
            try {
                IdempotencyKey::fromString($invalid);
                self::fail('Expected InvalidOrderInput for '.$invalid);
            } catch (InvalidOrderInput $refused) {
                self::assertSame('Nieprawidłowy klucz żądania.', $refused->getMessage());
            }
        }
    }

    public function testOrderIdAndVariantIdAreUuids(): void
    {
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-00000000000a', OrderId::fromString('0192F0C4-7C1A-7C4E-9A6E-00000000000A')->toString());
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-00000000000a', VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-00000000000a')->toString());

        $this->expectException(\InvalidArgumentException::class);
        OrderId::fromString('not-a-uuid');
    }

    public function testMoneyIsNonNegativeGroszeInPln(): void
    {
        $money = Money::of(1234, Currency::PLN);
        self::assertSame(1234, $money->amount());
        self::assertSame(2468, $money->times(2)->amount());
        self::assertSame(1235, $money->add(Money::of(1, Currency::PLN))->amount());
        self::assertSame(0, Money::zero(Currency::PLN)->amount());

        $this->expectException(\InvalidArgumentException::class);
        Money::of(-1, Currency::PLN);
    }

    public function testPriceCopyNeedsNetPlusVatEqualToGross(): void
    {
        $price = PriceCopy::of(10_000, 2_300, 12_300, 2300, 'standard', Currency::PLN);

        self::assertSame(10_000, $price->net()->amount());
        self::assertSame(2_300, $price->vat()->amount());
        self::assertSame(12_300, $price->gross()->amount());
        self::assertSame(2300, $price->vatRateBp());
        self::assertSame('standard', $price->taxClass());

        foreach ([
            static fn () => PriceCopy::of(10_000, 2_300, 12_301, 2300, 'standard', Currency::PLN),
            static fn () => PriceCopy::of(10_000, 2_300, 12_300, -1, 'standard', Currency::PLN),
            static fn () => PriceCopy::of(10_000, 2_300, 12_300, 2300, '', Currency::PLN),
            static fn () => PriceCopy::of(10_000, 2_300, 12_300, 2300, str_repeat('c', 51), Currency::PLN),
        ] as $build) {
            try {
                $build();
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFingerprintIsTheSameForTheSameNormalisedRequest(): void
    {
        $address = ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');
        $fingerprint = RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), $address, 12_300);
        $same = RequestFingerprint::of(
            'guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b',
            Contact::of(EmailAddress::fromString(' jan@example.com '), ' Jan Kowalski ', '+48 600-100-200'),
            ShippingAddress::inPoland(' Długa 1 ', '00-950', 'Warszawa '),
            12_300,
        );

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint->toString());
        self::assertTrue($fingerprint->equals($same));
        self::assertTrue($fingerprint->equals(RequestFingerprint::fromString($fingerprint->toString())));
    }

    /**
     * The fingerprint of a request whose texts have no characters at their ends but ASCII white space is the one of
     * the time before the rule of texts (spec api-input-contract, "Wdrożenie i klienci"): a repeat of a placement made
     * before the rule still is a repeat. The values are the fingerprints the code before the rule computed.
     */
    public function testTheFingerprintOfATextWithoutOtherEdgesIsTheOneOfTheTimeBeforeTheRule(): void
    {
        self::assertSame('a2a1e7fca14ecfe689884b636a0d591616f3d6608398bfb662f93c153011fbac', RequestFingerprint::of(
            'guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b',
            Contact::of(EmailAddress::fromString(' Jan.Kowalski@Example.com '), ' Jan Kowalski-Żółć ', '+48 600-100-200'),
            ShippingAddress::inPoland(' ul. Długa 1/2 ', ' 00-950 ', 'Warszawa '),
            12_300,
            'WITAJ10',
            '0192f0c4-7c1a-7c4e-9a6e-00000000de10',
            ExpectedLines::of([['variantId' => '0192f0c4-7c1a-7c4e-9a6e-00000000a001', 'quantity' => 2]]),
        )->toString());
        self::assertSame('9f0158a7b6885bdb4b212f3264553fec5a459190bf551e9b57b019e5c60bd54f', RequestFingerprint::of(
            'customer:kc-1',
            Contact::of(EmailAddress::fromString('ewa@example.com'), 'Ewa Nowak', '600100200'),
            ShippingAddress::inPoland('Długa 1', '00-950', 'Kraków'),
            1230,
        )->toString());
    }

    /**
     * A repeat with the same texts between other edges of the rule is the same request: the fingerprint is computed
     * from the trimmed values.
     */
    public function testTheFingerprintIsTheSameForTextsBetweenTheEdgesOfTheRule(): void
    {
        $fingerprint = static fn (string $edge): RequestFingerprint => RequestFingerprint::of(
            'guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b',
            Contact::of(EmailAddress::fromString($edge.'jan@example.com'.$edge), $edge.'Jan Kowalski'.$edge, $edge.'600100200'.$edge),
            ShippingAddress::inPoland($edge.'Długa 1'.$edge, $edge.'00-950'.$edge, $edge.'Warszawa'.$edge),
            12_300,
        );

        foreach (["\u{A0}", "\u{200B}", "\u{FEFF}", "\u{3000}\u{2060}"] as $edge) {
            self::assertTrue($fingerprint('')->equals($fingerprint($edge)), \sprintf('U+%04X', mb_ord($edge)));
        }
    }

    public function testFingerprintDiffersForAnotherCartContactAddressOrAmount(): void
    {
        $address = ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');
        $fingerprint = RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), $address, 12_300);

        foreach ([
            RequestFingerprint::of('guest:7f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), $address, 12_300),
            RequestFingerprint::of('customer:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), $address, 12_300),
            RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(email: 'ewa@example.com'), $address, 12_300),
            RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(fullName: 'Jan Nowak'), $address, 12_300),
            RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(phone: '600100201'), $address, 12_300),
            RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), ShippingAddress::inPoland('Długa 2', '00-950', 'Warszawa'), 12_300),
            RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), ShippingAddress::inPoland('Długa 1', '00-951', 'Warszawa'), 12_300),
            RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), ShippingAddress::inPoland('Długa 1', '00-950', 'Kraków'), 12_300),
            RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), $address, 12_301),
        ] as $index => $other) {
            self::assertFalse($fingerprint->equals($other), 'variation '.$index);
        }
    }

    public function testFingerprintFromStringNeeds64HexDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RequestFingerprint::fromString(str_repeat('g', 64));
    }

    private static function contact(string $email = 'jan@example.com', string $fullName = 'Jan Kowalski', string $phone = '600100200'): Contact
    {
        return Contact::of(EmailAddress::fromString($email), $fullName, $phone);
    }

    public function testAnAccessTokenIsFortyThreeCharactersOfBase64Url(): void
    {
        $token = 'Ab0_-Ab0_-Ab0_-Ab0_-Ab0_-Ab0_-Ab0_-Ab0_-Ab0';

        self::assertSame($token, AccessToken::fromString($token)->toString());
        self::assertTrue(AccessToken::fromString($token)->matches($token));
        self::assertFalse(AccessToken::fromString($token)->matches(strtolower($token)));
        self::assertFalse(AccessToken::fromString($token)->matches(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield '42 characters' => [str_repeat('a', 42)];
        yield '44 characters' => [str_repeat('a', 44)];
        yield 'base64 with plus' => [str_repeat('a', 42).'+'];
        yield 'base64 with slash' => [str_repeat('a', 42).'/'];
        yield 'padding' => [str_repeat('a', 42).'='];
        yield 'a new line at the end' => [str_repeat('a', 43)."\n"];
    }

    #[DataProvider('invalidTokens')]
    public function testAnyOtherAccessTokenIsRefused(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AccessToken::fromString($token);
    }

    public function testAPaymentIdIsAUuid(): void
    {
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-00000000000a', PaymentId::fromString('0192F0C4-7C1A-7C4E-9A6E-00000000000A')->toString());
        self::assertTrue(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-00000000000a')->equals(PaymentId::fromString('0192F0C4-7C1A-7C4E-9A6E-00000000000A')));

        $this->expectException(\InvalidArgumentException::class);
        PaymentId::fromString('not-a-uuid');
    }
}
