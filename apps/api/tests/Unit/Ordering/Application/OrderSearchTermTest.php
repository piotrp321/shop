<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\InvalidBackofficeOrderList;
use App\Ordering\Application\Query\OrderSearchTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the search term of the order list means: an exact number, an exact email when it is a complete address (a
 * domain with a dot and a last label of 2 characters or more), else a fragment of 3 to 100 characters (also with an @).
 */
final class OrderSearchTermTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numbers(): iterable
    {
        yield 'as stored' => ['R-100001', 'R-100001'];
        yield 'in lower case' => ['r-100001', 'R-100001'];
        yield 'with spaces around' => ['  R-100001 ', 'R-100001'];
        yield 'with no-break spaces around' => ["\u{00A0}R-100001\u{00A0}", 'R-100001'];
        yield 'the shortest' => ['R-1', 'R-1'];
    }

    #[DataProvider('numbers')]
    public function testAnExactNumberIsKeptWhateverItsLetterCase(string $q, string $number): void
    {
        $term = OrderSearchTerm::parse($q);

        self::assertSame([$number, null, null], [$term->number, $term->email, $term->fragment]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fragments(): iterable
    {
        yield 'a surname' => ['Kowalski', 'Kowalski'];
        yield 'digits of a number' => ['100001', '100001'];
        yield 'the digits of a number with its prefix and a letter' => ['R-1000a', 'R-1000a'];
        yield 'a number with letters' => ['R-10a001', 'R-10a001'];
        yield 'a fragment of an email with an @' => ['jan@gm', 'jan@gm'];
        yield 'an email with a domain without a dot' => ['jan@gmail', 'jan@gmail'];
        yield 'an email with a last label of one character' => ['jan@gmail.c', 'jan@gmail.c'];
        yield 'an email without the local part' => ['@gmail.com', '@gmail.com'];
        yield 'an email with two @' => ['jan@@gmail.com', 'jan@@gmail.com'];
        yield 'an email with a space inside' => ['jan kowalski@gmail.com', 'jan kowalski@gmail.com'];
        yield 'an email with an empty label' => ['jan@gmail..com', 'jan@gmail..com'];
        yield 'a name with a space inside' => ['Jan Kowalski', 'Jan Kowalski'];
        yield 'spaces around dropped' => ['  jan  ', 'jan'];
        yield 'no-break spaces around dropped' => ["\u{00A0}jan\u{00A0}", 'jan'];
        yield 'three characters, two of them multibyte' => ['Łuk', 'Łuk'];
        yield 'a percent and an underscore stay as typed' => ['a%_b', 'a%_b'];
        yield 'a backslash stays as typed' => ['a\\b', 'a\\b'];
        yield 'a hundred characters' => [str_repeat('ż', 100), str_repeat('ż', 100)];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emails(): iterable
    {
        yield 'an email' => ['jan@example.com', 'jan@example.com'];
        yield 'in another letter case, as typed' => ['Jan.Kowalski@Example.COM', 'Jan.Kowalski@Example.COM'];
        yield 'with spaces around' => [' jan@example.com  ', 'jan@example.com'];
        yield 'with a subdomain' => ['jan+sklep@mail.example.co.uk', 'jan+sklep@mail.example.co.uk'];
        yield 'a number-like local part' => ['R-1@example.com', 'R-1@example.com'];
        yield 'the shortest' => ['a@b.cc', 'a@b.cc'];
    }

    #[DataProvider('emails')]
    public function testACompleteEmailAddressIsAnExactEmailNotAFragment(string $q, string $email): void
    {
        $term = OrderSearchTerm::parse($q);

        self::assertSame([null, $email, null], [$term->number, $term->email, $term->fragment]);
    }

    #[DataProvider('fragments')]
    public function testAnotherTextOfThreeToAHundredCharactersIsAFragment(string $q, string $fragment): void
    {
        $term = OrderSearchTerm::parse($q);

        self::assertSame([null, null, $fragment], [$term->number, $term->email, $term->fragment]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function longEmails(): iterable
    {
        yield 'a hundred and one characters' => [str_repeat('a', 60).'@'.str_repeat('b', 37).'.pl'];
        yield 'the longest email of an order' => [str_repeat('a', 64).'@'.str_repeat('b', 186).'.pl'];
    }

    #[DataProvider('longEmails')]
    public function testAnEmailTooLongForAFragmentIsAnExactEmailToo(string $email): void
    {
        $term = OrderSearchTerm::parse(' '.$email.' ');

        self::assertGreaterThan(100, mb_strlen($email));
        self::assertLessThanOrEqual(254, mb_strlen($email));
        self::assertSame([null, $email, null], [$term->number, $term->email, $term->fragment]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces only' => ['     '];
        yield 'no-break spaces only' => ["\u{00A0}\u{00A0}\u{00A0}\u{00A0}"];
        yield 'two characters' => ['ab'];
        yield 'two characters between spaces' => ['  ab  '];
        yield 'a prefix of a number' => ['R-'];
        yield 'over a hundred characters, no @' => [str_repeat('a', 101)];
        yield 'over a hundred characters, no email' => [str_repeat('a ', 60).'z'];
        yield 'over a hundred characters, an @ but no complete address' => [str_repeat('a', 100).'@'.str_repeat('b', 10)];
        yield 'over 254 characters, even an email' => [str_repeat('a', 250).'@b.com'];
        yield 'a NUL' => ["jan\0kowalski"];
        yield 'the separator of the index' => ["jan\u{001F}kowalski"];
        yield 'a tab' => ["jan\tkowalski"];
        yield 'a line break at the end' => ["jan kowalski\n"];
        yield 'a DEL' => ["jan\u{007F}kowalski"];
        yield 'a next line' => ["jan\u{0085}kowalski"];
        yield 'a line separator' => ["jan\u{2028}kowalski"];
        yield 'a long email with a control character' => [str_repeat('a', 100)."\0@".str_repeat('b', 100).'.example.com'];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefusedWithoutEchoingIt(string $q): void
    {
        try {
            OrderSearchTerm::parse($q);
            self::fail('The term must be refused.');
        } catch (InvalidBackofficeOrderList $refused) {
            self::assertStringNotContainsString('kowalski', $refused->getMessage());
        }
    }

    public function testATextThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(InvalidBackofficeOrderList::class);

        OrderSearchTerm::parse(hex2bin('6a616efffe6b6f77616c736b69') ?: '');
    }

    public function testTheLengthIsCountedInCharactersNotBytes(): void
    {
        self::assertSame('żż', mb_substr(OrderSearchTerm::parse('żżż')->fragment ?? '', 0, 2));
        $this->expectException(InvalidBackofficeOrderList::class);

        OrderSearchTerm::parse('żż');
    }
}
