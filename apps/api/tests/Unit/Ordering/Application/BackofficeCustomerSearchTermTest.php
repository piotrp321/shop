<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\BackofficeCustomerSearchTerm;
use App\Ordering\Application\Query\InvalidBackofficeCustomerList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The search term of the customer list: a fragment of 3 to 100 characters once trimmed (also of the Unicode spaces),
 * Polish letters counted as one character each, without a control character anywhere.
 */
final class BackofficeCustomerSearchTermTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fragments(): iterable
    {
        yield 'three characters' => ['ann', 'ann'];
        yield 'a part of an email' => ['anna@gm', 'anna@gm'];
        yield 'trimmed' => ['  Nowak ', 'Nowak'];
        yield 'trimmed of no-break spaces' => ["\u{00A0}Łucja\u{2003}", 'Łucja'];
        yield 'three Polish letters' => ['żół', 'żół'];
        yield 'a hundred characters' => [str_repeat('ą', 100), str_repeat('ą', 100)];
        yield 'the wildcards of LIKE as they are' => ['%_\\', '%_\\'];
    }

    #[DataProvider('fragments')]
    public function testAFragmentIsTrimmed(string $q, string $expected): void
    {
        self::assertSame($expected, BackofficeCustomerSearchTerm::fragment($q));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'two characters' => ['an'];
        yield 'two characters among spaces' => ['  an  '];
        yield 'only no-break spaces' => ["\u{00A0}\u{00A0}\u{00A0}\u{00A0}"];
        yield 'a hundred and one characters' => [str_repeat('a', 101)];
        yield 'a NUL' => ["ann\0a"];
        yield 'a NUL at the end' => ["anna\0"];
        yield 'a line separator' => ["ann\u{2028}a"];
        yield 'a tab' => ["ann\ta"];
        yield 'not UTF-8' => ["ann\xC3"];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(string $q): void
    {
        $this->expectException(InvalidBackofficeCustomerList::class);

        BackofficeCustomerSearchTerm::fragment($q);
    }
}
