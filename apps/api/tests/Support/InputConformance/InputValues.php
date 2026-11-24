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

    /**
     * The characters the rule of texts forbids anywhere in a text (docs/features/api-input-contract/spec.md, T1): every
     * control character (Unicode Cc: C0, DEL, C1) and the line and paragraph separators.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenTextCharacters(): iterable
    {
        foreach ([...range(0x00, 0x1F), 0x7F, ...range(0x80, 0x9F), 0x2028, 0x2029] as $codePoint) {
            yield \sprintf('U+%04X', $codePoint) => [mb_chr($codePoint, 'UTF-8')];
        }
    }

    /**
     * Texts that are not valid UTF-8, which the rule of texts forbids (T1).
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidUtf8Texts(): iterable
    {
        yield 'a lone continuation byte' => ["Kur\x80ier"];
        yield 'a cut sequence' => ["Kurier\xC5"];
        yield 'a cut sequence inside' => ["Kur\xE2\x82ier"];
        yield 'bad bytes only' => ["\xFF\xFE"];
    }

    /**
     * The characters the rule of texts trims from the ends of a text and keeps inside it (T2): Unicode white space
     * (`\s` and the category Z, without the separators T1 forbids) and four invisible characters.
     *
     * @return iterable<string, array{string}>
     */
    public static function textEdgeCharacters(): iterable
    {
        foreach ([0x20, 0xA0, 0x1680, ...range(0x2000, 0x200A), 0x202F, 0x205F, 0x3000, 0x180E, 0x200B, 0x2060, 0xFEFF, 0xAD] as $codePoint) {
            yield \sprintf('U+%04X', $codePoint) => [mb_chr($codePoint, 'UTF-8')];
        }
    }

    /**
     * Texts without a visible character, which the rule of texts takes for empty (T3): single characters and mixtures
     * of white space, format characters and default ignorable code points.
     *
     * @return iterable<string, array{string}>
     */
    public static function emptyTexts(): iterable
    {
        yield 'nothing' => [''];
        yield 'ASCII spaces' => ['   '];
        foreach ([0xA0, 0x3000, 0x200B, 0xFEFF, 0xAD, 0x2060, 0x180E, 0x200D, 0x200E, 0x202E, 0xFE0F, 0x3164, 0x115F, 0xFFA0, 0x034F, 0xE0067] as $codePoint) {
            yield \sprintf('U+%04X', $codePoint) => [mb_chr($codePoint, 'UTF-8')];
        }
        yield 'zero width spaces and a Hangul filler' => ["\u{200B}\u{3164}\u{200B}"];
        yield 'joiners and marks of direction' => ["\u{200D}\u{200E}\u{202E}\u{200C}"];
        yield 'white space around a variation selector' => [" \u{A0}\u{FE0F}\u{3000} "];
        yield 'tag characters without their flag' => ["\u{E0067}\u{E0062}\u{E007F}"];
    }

    /**
     * Texts of one character the rule of texts takes for visible (T3) although Unicode gives it no letter: private use
     * (Co) and characters the Unicode of PCRE does not know (Cn: never assigned, or newer than PCRE, e.g. an emoji of
     * Unicode 16).
     *
     * @return iterable<string, array{string}>
     */
    public static function visibleCharactersWithoutALetter(): iterable
    {
        foreach ([0xE000, 0xF8FF, 0x10FFFD, 0x0378, 0x1FAE9] as $codePoint) {
            yield \sprintf('U+%04X', $codePoint) => [mb_chr($codePoint, 'UTF-8')];
        }
    }

    /**
     * Texts the rule of texts keeps as they are (T5): format characters next to a visible character, also at the end.
     *
     * @return iterable<string, array{string}>
     */
    public static function preservedTexts(): iterable
    {
        yield 'ending with the flag of England' => ["Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"];
        yield 'an emoji with U+200D' => ["Kurier \u{1F469}\u{200D}\u{1F4BB}"];
        yield 'Polish letters' => ['Zażółć gęślą jaźń'];
    }

    /**
     * The values of the class "empty" of the contract test of texts (spec, "Test kontraktu tekstów").
     *
     * @return iterable<string, array{string}>
     */
    public static function emptyTextClass(): iterable
    {
        yield 'U+00A0' => ["\u{A0}"];
        yield 'U+200B' => ["\u{200B}"];
        yield 'U+3164' => ["\u{3164}"];
    }

    /**
     * The characters of the class "forbidden" of the contract test of texts, sent inside a text.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenTextClass(): iterable
    {
        yield 'U+0000' => ["\u{0}"];
        yield 'U+009F' => ["\u{9F}"];
        yield 'U+2028' => ["\u{2028}"];
    }

    /**
     * The characters of the class "forbidden" of the contract test of texts sent at the end of a text: a trim of the
     * ends (`\s` with `/u`) would drop them, so they show that the characters are looked for in the value as it came.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenAtTheEndTextClass(): iterable
    {
        yield 'U+2028' => ["\u{2028}"];
    }

    /**
     * The characters of the class "edges" of the contract test of texts, sent before and after a text.
     *
     * @return iterable<string, array{string}>
     */
    public static function edgeTextClass(): iterable
    {
        yield 'U+00A0' => ["\u{A0}"];
        yield 'U+200B' => ["\u{200B}"];
    }
}
