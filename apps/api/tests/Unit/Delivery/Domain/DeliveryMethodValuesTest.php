<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Domain;

use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\InvalidDeliveryMethod;
use App\Delivery\Domain\TrackingUrlTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value objects of a delivery method (docs/features/delivery/spec.md, "Model" and W1): each refuses its value
 * with a Polish message at the field of the request.
 */
final class DeliveryMethodValuesTest extends TestCase
{
    private const string NAME_REQUIRED = 'Nazwa jest wymagana.';
    private const string NAME_TOO_LONG = 'Nazwa może mieć najwyżej 100 znaków.';
    private const string NAME_CHARACTERS = 'Nazwa zawiera niedozwolone znaki.';
    private const string DESCRIPTION_TOO_LONG = 'Opis może mieć najwyżej 200 znaków.';
    private const string DESCRIPTION_CHARACTERS = 'Opis zawiera niedozwolone znaki.';
    private const string PRICE = 'Cena musi wynosić od 0,00 do 999 999,99 zł.';
    private const string FREE_FROM = 'Próg darmowej dostawy musi wynosić od 0,01 do 999 999 999,99 zł.';
    private const string POSITION = 'Kolejność musi być liczbą całkowitą od 0 do 999.';
    private const string TEMPLATE_TOO_LONG = 'Link śledzenia może mieć najwyżej 500 znaków.';
    private const string TEMPLATE_HTTPS = 'Link śledzenia musi zaczynać się od https:// i mieć nazwę hosta.';
    private const string TEMPLATE_CHARACTERS = 'Link śledzenia nie może zawierać spacji ani znaków sterujących.';
    private const string TEMPLATE_NUMBER = 'Link śledzenia musi zawierać dokładnie jedno {numer}.';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validNames(): iterable
    {
        yield 'one character' => ['K', 'K'];
        yield 'a hundred characters' => [str_repeat('ł', 100), str_repeat('ł', 100)];
        yield 'a hundred characters once trimmed' => ['  '.str_repeat('a', 100)."\u{A0}", str_repeat('a', 100)];
        yield 'Polish letters' => ['Kurier ŁÓDŹ żółć', 'Kurier ŁÓDŹ żółć'];
        yield 'trimmed of Unicode spaces' => ["\u{3000}Kurier\u{2003} ", 'Kurier'];
        yield 'spaces inside kept' => ['Kurier  ekspresowy', 'Kurier  ekspresowy'];
        yield 'trimmed of zero width spaces' => ["\u{200B}\u{FEFF}Kurier\u{2060}\u{AD}", 'Kurier'];
        yield 'a hundred characters once trimmed of zero width spaces' => ["\u{200B}".str_repeat('a', 100)."\u{200B}", str_repeat('a', 100)];
        yield 'ending with the flag of England' => ["Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", "Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"];
        yield 'an emoji with U+200D' => ["Kurier \u{1F469}\u{200D}\u{1F4BB}", "Kurier \u{1F469}\u{200D}\u{1F4BB}"];
    }

    #[DataProvider('validNames')]
    public function testANameIsTrimmedOfUnicodeWhitespace(string $name, string $expected): void
    {
        self::assertSame($expected, DeliveryMethodName::of($name)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => ['', self::NAME_REQUIRED];
        yield 'ASCII spaces only' => ['   ', self::NAME_REQUIRED];
        yield 'a no-break space only' => ["\u{A0}", self::NAME_REQUIRED];
        yield 'Unicode spaces only' => [" \u{3000}\u{2003}\u{202F}", self::NAME_REQUIRED];
        yield 'a zero width space only' => ["\u{200B}", self::NAME_REQUIRED];
        yield 'a Hangul filler only' => ["\u{3164}", self::NAME_REQUIRED];
        yield 'invisible characters only' => ["\u{FEFF}\u{AD}\u{2060}\u{200E}\u{202E}\u{200D}", self::NAME_REQUIRED];
        yield 'a C1 control character' => ["Kur\u{9F}ier", self::NAME_CHARACTERS];
        yield 'a C1 control character at the end' => ["Kurier\u{80}", self::NAME_CHARACTERS];
        yield 'a hundred and one characters' => [str_repeat('a', 101), self::NAME_TOO_LONG];
        yield 'a NUL byte' => ["Kur\0ier", self::NAME_CHARACTERS];
        yield 'a line separator' => ["Kur\u{2028}ier", self::NAME_CHARACTERS];
        yield 'a paragraph separator' => ["Kurier\u{2029}", self::NAME_CHARACTERS];
        yield 'a next line' => ["Kurier\u{85}", self::NAME_CHARACTERS];
        yield 'a line feed at the end' => ["Kurier\n", self::NAME_CHARACTERS];
        yield 'a tab inside' => ["Kur\tier", self::NAME_CHARACTERS];
        yield 'DEL' => ["Kur\x7Fier", self::NAME_CHARACTERS];
        yield 'invalid UTF-8' => ["Kur\xC3\x28ier", self::NAME_CHARACTERS];
        yield 'a lone continuation byte' => ["\x80", self::NAME_CHARACTERS];
    }

    #[DataProvider('invalidNames')]
    public function testAnInvalidNameIsRefusedAtItsField(string $name, string $message): void
    {
        $this->assertRefused(static fn () => DeliveryMethodName::of($name), 'name', $message);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function validDescriptions(): iterable
    {
        yield 'null is no description' => [null, ''];
        yield 'empty' => ['', ''];
        yield 'Unicode spaces only' => ["\u{A0}\u{3000} ", ''];
        yield 'invisible characters only' => ["\u{200B}\u{3164}", ''];
        yield 'trimmed of zero width spaces' => ["\u{200B}Jutro\u{200B}", 'Jutro'];
        yield 'two hundred characters' => [str_repeat('ż', 200), str_repeat('ż', 200)];
        yield 'trimmed' => ["  Dostawa w 2–3 dni robocze\u{A0}", 'Dostawa w 2–3 dni robocze'];
    }

    #[DataProvider('validDescriptions')]
    public function testADescriptionIsOptionalAndTrimmed(?string $description, string $expected): void
    {
        self::assertSame($expected, DeliveryMethodDescription::of($description)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDescriptions(): iterable
    {
        yield 'two hundred and one characters' => [str_repeat('a', 201), self::DESCRIPTION_TOO_LONG];
        yield 'a line feed' => ["Dostawa\nw 2 dni", self::DESCRIPTION_CHARACTERS];
        yield 'a carriage return' => ["Dostawa\r", self::DESCRIPTION_CHARACTERS];
        yield 'a NUL byte' => ["Dostawa\0", self::DESCRIPTION_CHARACTERS];
        yield 'a line separator' => ["Dostawa\u{2028}", self::DESCRIPTION_CHARACTERS];
        yield 'a C1 control character' => ["Dos\u{9F}tawa", self::DESCRIPTION_CHARACTERS];
        yield 'invalid UTF-8' => ["Dostawa \xFF", self::DESCRIPTION_CHARACTERS];
    }

    #[DataProvider('invalidDescriptions')]
    public function testAnInvalidDescriptionIsRefusedAtItsField(string $description, string $message): void
    {
        $this->assertRefused(static fn () => DeliveryMethodDescription::of($description), 'description', $message);
    }

    /**
     * A stored text is read as it is, also one the rule refuses (docs/features/api-input-contract/spec.md, E7).
     */
    public function testAStoredNameAndDescriptionAreReadWithoutTheRule(): void
    {
        foreach (["\u{200B}", "Kurier\u{9F}", "Kurier\t", " Kurier\u{A0}"] as $stored) {
            self::assertSame($stored, DeliveryMethodName::reconstitute($stored)->toString());
            self::assertSame($stored, DeliveryMethodDescription::reconstitute($stored)->toString());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validPrices(): iterable
    {
        yield 'free' => [0];
        yield 'one grosz' => [1];
        yield '999 999,99 zł' => [99_999_999];
    }

    #[DataProvider('validPrices')]
    public function testAPriceIsFrom0To99999999Grosze(int $gross): void
    {
        self::assertSame($gross, DeliveryPrice::of($gross)->gross());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPrices(): iterable
    {
        yield 'negative' => [-1];
        yield 'a million zloty' => [100_000_000];
    }

    #[DataProvider('invalidPrices')]
    public function testAPriceOutOfItsRangeIsRefused(int $gross): void
    {
        $this->assertRefused(static fn () => DeliveryPrice::of($gross), 'priceGross', self::PRICE);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validThresholds(): iterable
    {
        yield 'one grosz' => [1];
        yield '299 zł' => [29_900];
        yield '999 999 999,99 zł' => [99_999_999_999];
    }

    #[DataProvider('validThresholds')]
    public function testAThresholdIsFromOneGroszUp(int $gross): void
    {
        self::assertSame($gross, FreeDeliveryThreshold::of($gross)->gross());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidThresholds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-100];
        yield 'a billion zloty' => [100_000_000_000];
    }

    #[DataProvider('invalidThresholds')]
    public function testAThresholdOutOfItsRangeIsRefused(int $gross): void
    {
        $this->assertRefused(static fn () => FreeDeliveryThreshold::of($gross), 'freeFromGross', self::FREE_FROM);
    }

    public function testNoThresholdIsNull(): void
    {
        self::assertNull(FreeDeliveryThreshold::optional(null));
        self::assertSame(1, FreeDeliveryThreshold::optional(1)?->gross());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validPositions(): iterable
    {
        yield 'first' => [0];
        yield 'last' => [999];
    }

    #[DataProvider('validPositions')]
    public function testAPositionIsFrom0To999(int $position): void
    {
        self::assertSame($position, DeliveryPosition::of($position)->value());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPositions(): iterable
    {
        yield 'negative' => [-1];
        yield 'a thousand' => [1000];
    }

    #[DataProvider('invalidPositions')]
    public function testAPositionOutOfItsRangeIsRefused(int $position): void
    {
        $this->assertRefused(static fn () => DeliveryPosition::of($position), 'position', self::POSITION);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validTemplates(): iterable
    {
        yield 'the number in the path' => ['https://tracking.example.com/{numer}', 'https://tracking.example.com/{numer}'];
        yield 'the number in the query' => ['https://example.com/track?n={numer}&lang=pl', 'https://example.com/track?n={numer}&lang=pl'];
        yield 'a port' => ['https://example.com:8443/{numer}', 'https://example.com:8443/{numer}'];
        yield 'a host without a dot' => ['https://tracking/{numer}', 'https://tracking/{numer}'];
        yield 'trimmed' => ["  https://example.com/{numer}\u{A0}", 'https://example.com/{numer}'];
        $prefix = 'https://example.com/';
        $long = $prefix.str_repeat('a', 500 - \strlen($prefix) - \strlen('{numer}')).'{numer}';
        yield 'five hundred characters' => [$long, $long];
    }

    #[DataProvider('validTemplates')]
    public function testATemplateIsAnHttpsAddressWithOneNumber(string $template, string $expected): void
    {
        self::assertSame($expected, TrackingUrlTemplate::of($template)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTemplates(): iterable
    {
        $prefix = 'https://example.com/';
        yield 'five hundred and one characters' => [$prefix.str_repeat('a', 501 - \strlen($prefix) - \strlen('{numer}')).'{numer}', self::TEMPLATE_TOO_LONG];
        yield 'http' => ['http://example.com/{numer}', self::TEMPLATE_HTTPS];
        yield 'no scheme' => ['example.com/{numer}', self::TEMPLATE_HTTPS];
        yield 'another scheme' => ['javascript://example.com/{numer}', self::TEMPLATE_HTTPS];
        yield 'no host' => ['https:///{numer}', self::TEMPLATE_HTTPS];
        yield 'only the scheme' => ['https://', self::TEMPLATE_HTTPS];
        yield 'the number as the host' => ['https://{numer}', self::TEMPLATE_HTTPS];
        yield 'user info before the host' => ['https://user@example.com/{numer}', self::TEMPLATE_HTTPS];
        yield 'a host ending with a dash' => ['https://example-/{numer}', self::TEMPLATE_HTTPS];
        yield 'a space inside' => ['https://example.com/a b/{numer}', self::TEMPLATE_CHARACTERS];
        yield 'a no-break space inside' => ["https://example.com/a\u{A0}b/{numer}", self::TEMPLATE_CHARACTERS];
        yield 'a tab inside' => ["https://example.com/\t{numer}", self::TEMPLATE_CHARACTERS];
        yield 'a line feed at the end' => ["https://example.com/{numer}\n", self::TEMPLATE_CHARACTERS];
        yield 'a NUL byte' => ["https://example.com/\0{numer}", self::TEMPLATE_CHARACTERS];
        yield 'a line separator' => ["https://example.com/\u{2028}{numer}", self::TEMPLATE_CHARACTERS];
        yield 'invalid UTF-8' => ["https://example.com/\xC3\x28{numer}", self::TEMPLATE_CHARACTERS];
        yield 'no number' => ['https://example.com/track', self::TEMPLATE_NUMBER];
        yield 'two numbers' => ['https://example.com/{numer}/{numer}', self::TEMPLATE_NUMBER];
        yield 'the number in another case' => ['https://example.com/{NUMER}', self::TEMPLATE_NUMBER];
    }

    #[DataProvider('invalidTemplates')]
    public function testAnInvalidTemplateIsRefusedAtItsField(string $template, string $message): void
    {
        $this->assertRefused(static fn () => TrackingUrlTemplate::of($template), 'trackingUrlTemplate', $message);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function noTemplates(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'ASCII spaces' => ['   '];
        yield 'a no-break space' => ["\u{A0}"];
        yield 'Unicode spaces' => [" \u{3000}\u{2003}"];
    }

    #[DataProvider('noTemplates')]
    public function testNullAndABlankTextAreNoTemplate(?string $template): void
    {
        self::assertNull(TrackingUrlTemplate::optional($template));
    }

    public function testAnOptionalTemplateIsCheckedLikeARequiredOne(): void
    {
        self::assertSame('https://example.com/{numer}', TrackingUrlTemplate::optional(' https://example.com/{numer}')?->toString());
        $this->assertRefused(static fn () => TrackingUrlTemplate::optional('http://example.com/{numer}'), 'trackingUrlTemplate', self::TEMPLATE_HTTPS);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trackingNumbers(): iterable
    {
        yield 'letters and digits' => ['AB123-45', 'https://tracking.example.com/AB123-45?x=1'];
        yield 'a character of the address' => ['a/b?c&d', 'https://tracking.example.com/a%2Fb%3Fc%26d?x=1'];
        yield 'a space and a Polish letter' => ['ż 1', 'https://tracking.example.com/%C5%BC%201?x=1'];
    }

    #[DataProvider('trackingNumbers')]
    public function testTheUrlForANumberHasTheNumberEncodedInPlaceOfTheMarker(string $number, string $expected): void
    {
        self::assertSame($expected, TrackingUrlTemplate::of('https://tracking.example.com/{numer}?x=1')->urlFor($number));
    }

    public function testAnIdIsAUuidInLowerCase(): void
    {
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8a01', DeliveryMethodId::fromString('0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C8A01')->toString());
        $this->expectException(\InvalidArgumentException::class);
        DeliveryMethodId::fromString('kurier-1');
    }

    /**
     * @param \Closure(): mixed $build
     */
    private function assertRefused(\Closure $build, string $field, string $message): void
    {
        try {
            $build();
            self::fail('The value must be refused.');
        } catch (InvalidDeliveryMethod $refused) {
            self::assertSame([$field, $message], [$refused->field, $refused->getMessage()]);
        }
    }
}
