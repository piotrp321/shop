<?php

declare(strict_types=1);

namespace App\Tests\Support\InputConformance;

/**
 * Values of an input that a validation tends to treat differently from what the code after it does. Data only: what
 * an endpoint answers to a value is its own contract, stated by the test of that endpoint.
 */
final class InputValues
{
    /**
     * Texts in the shape of a UUID that are no UUID of a known version.
     *
     * @return iterable<string, array{string}>
     */
    public static function uuidsOutsideTheVersions(): iterable
    {
        yield 'nil UUID' => ['00000000-0000-0000-0000-000000000000'];
        yield 'max UUID' => ['ffffffff-ffff-ffff-ffff-ffffffffffff'];
    }

    /**
     * Other notations of the same UUID.
     *
     * @return iterable<string, array{string}>
     */
    public static function equivalentUuidForms(string $canonical): iterable
    {
        yield 'upper case' => [strtoupper($canonical)];
    }

    /**
     * Texts a reader sees as empty although an ASCII trim() leaves them as they are.
     *
     * @return iterable<string, array{string}>
     */
    public static function blankUnicodeTexts(): iterable
    {
        yield 'no-break space' => ["\u{00A0}"];
        yield 'ideographic space' => ["\u{3000}"];
        yield 'em space between ASCII spaces' => [" \u{2003} "];
        yield 'several kinds of Unicode white space' => [" \u{3000}\u{2003}\u{202F}"];
    }
}
