<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Text;
use App\Tests\Support\InputConformance\InputValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule of texts typed by people (docs/features/api-input-contract/spec.md, "Etap 2: reguła tekstu", T1–T5): the
 * forbidden characters, the trimming of the ends, the empty text, the length after the trimming and what is kept.
 */
final class TextTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenCharacters(): iterable
    {
        foreach (InputValues::forbiddenTextCharacters() as $label => [$character]) {
            yield $label.' at the start' => [$character.'Kurier'];
            yield $label.' inside' => ['Kur'.$character.'ier'];
            yield $label.' at the end' => ['Kurier'.$character];
        }
    }

    #[DataProvider('forbiddenCharacters')]
    public function testAControlCharacterOrASeparatorIsForbiddenAnywhere(string $text): void
    {
        self::assertTrue(Text::hasForbiddenCharacters($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theSeparatorsOfTheSearchIndex(): iterable
    {
        yield 'U+001E' => ["\u{1E}"];
        yield 'U+001F' => ["\u{1F}"];
    }

    #[DataProvider('theSeparatorsOfTheSearchIndex')]
    public function testASeparatorOfTheSearchIndexIsForbiddenAnywhereAndNotTrimmedAway(string $separator): void
    {
        // The facet tokens of the search index join an axis and a value with these characters (spec search, M1).
        foreach (['Ko'.$separator.'lor', $separator.'Kolor', 'Kolor'.$separator, $separator, ' '.$separator.' '] as $text) {
            self::assertTrue(Text::hasForbiddenCharacters($text), json_encode($text, \JSON_THROW_ON_ERROR));
        }
        // Trimming leaves them at the ends of a text that has other characters, so a check after it finds them too.
        foreach ([$separator.'Kolor', 'Kolor'.$separator, ' Kolor'.$separator.' '] as $text) {
            self::assertTrue(Text::hasForbiddenCharacters(Text::normalize($text)), json_encode($text, \JSON_THROW_ON_ERROR));
        }
        self::assertTrue(Text::hasForbiddenCharacters('Kolor'.$separator, "\t\n\r"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUtf8Texts(): iterable
    {
        yield from InputValues::invalidUtf8Texts();
    }

    #[DataProvider('invalidUtf8Texts')]
    public function testATextThatIsNotValidUtf8IsForbidden(string $text): void
    {
        self::assertTrue(Text::hasForbiddenCharacters($text));
        self::assertTrue(Text::hasForbiddenCharacters($text, "\t\n\r"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedCharacters(): iterable
    {
        yield 'a line feed in a field of lines' => ["Pierwsza\nDruga", "\n"];
        yield 'a tab in a note' => ["Kolumna\tkolumna", "\t\n\r"];
        yield 'a line feed in a note' => ["Pierwsza\nDruga\n", "\t\n\r"];
        yield 'a carriage return and a line feed in a note' => ["Pierwsza\r\nDruga", "\t\n\r"];
    }

    #[DataProvider('allowedCharacters')]
    public function testACharacterAllowedInAFieldIsNotForbiddenThere(string $text, string $allowed): void
    {
        self::assertFalse(Text::hasForbiddenCharacters($text, $allowed));
        self::assertTrue(Text::hasForbiddenCharacters($text), 'without the list of the field it is forbidden');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenBesidesTheAllowed(): iterable
    {
        yield 'a carriage return where only a line feed is allowed' => ["Pierwsza\r\nDruga", "\n"];
        yield 'NUL in a note' => ["Notatka\0", "\t\n\r"];
        yield 'U+0085 in a note' => ["Notatka\u{85}", "\t\n\r"];
        yield 'U+009F in a note' => ["No\u{9F}tatka", "\t\n\r"];
        yield 'U+2028 in a note' => ["Notatka\u{2028}druga", "\t\n\r"];
        yield 'U+2029 in a note' => ["Notatka\u{2029}", "\t\n\r"];
        yield 'a vertical tab in a note' => ["Notatka\x0B", "\t\n\r"];
    }

    #[DataProvider('forbiddenBesidesTheAllowed')]
    public function testTheOtherForbiddenCharactersStayForbiddenBesidesTheAllowedOnes(string $text, string $allowed): void
    {
        self::assertTrue(Text::hasForbiddenCharacters($text, $allowed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textsWithoutForbiddenCharacters(): iterable
    {
        yield 'ASCII' => ['Kurier standardowy'];
        yield 'nothing' => [''];
        foreach (InputValues::textEdgeCharacters() as $label => [$character]) {
            yield $label => ['Ku'.$character.'rier'];
        }
        foreach (InputValues::preservedTexts() as $label => [$text]) {
            yield $label => [$text];
        }
        yield 'marks of direction inside' => ["Kurier \u{202E}abc\u{202C}"];
    }

    #[DataProvider('textsWithoutForbiddenCharacters')]
    public function testOtherCharactersAreNotForbidden(string $text): void
    {
        self::assertFalse(Text::hasForbiddenCharacters($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function edgeCharacters(): iterable
    {
        yield from InputValues::textEdgeCharacters();
    }

    #[DataProvider('edgeCharacters')]
    public function testAnEdgeCharacterIsTrimmedFromBothEndsAndKeptInside(string $character): void
    {
        self::assertSame('Kurier', Text::normalize($character.'Kurier'));
        self::assertSame('Kurier', Text::normalize('Kurier'.$character));
        self::assertSame('Kurier', Text::normalize($character.$character.'Kurier'.$character.$character));
        self::assertSame('Ku'.$character.'rier', Text::normalize($character.'Ku'.$character.'rier'.$character));
    }

    public function testTheEndsLoseEveryEdgeCharacterTogether(): void
    {
        $edges = implode('', array_map(static fn (array $value): string => $value[0], iterator_to_array(InputValues::textEdgeCharacters())));

        self::assertSame("Kurier\u{A0}\u{200B}standardowy", Text::normalize($edges."Kurier\u{A0}\u{200B}standardowy".$edges));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyTexts(): iterable
    {
        yield from InputValues::emptyTexts();
    }

    #[DataProvider('emptyTexts')]
    public function testATextWithoutAVisibleCharacterIsEmpty(string $text): void
    {
        self::assertSame('', Text::normalize($text));
        self::assertSame(0, Text::length($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function visibleCharactersWithoutALetter(): iterable
    {
        yield from InputValues::visibleCharactersWithoutALetter();
    }

    #[DataProvider('visibleCharactersWithoutALetter')]
    public function testAPrivateUseOrUnassignedCharacterIsVisible(string $character): void
    {
        self::assertFalse(Text::hasForbiddenCharacters($character));
        self::assertSame($character, Text::normalize($character));
        self::assertSame(1, Text::length($character));
        self::assertSame($character, Text::normalize("\u{A0}\u{200B}".$character."\u{200B}\u{A0}"));
        self::assertSame("\u{200D}".$character, Text::normalize("\u{200D}".$character));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invisibleCharactersNextToVisibleOnes(): iterable
    {
        yield 'U+200D at the end' => ["Kurier\u{200D}", "Kurier\u{200D}"];
        yield 'U+200E at the start' => ["\u{200E}Kurier", "\u{200E}Kurier"];
        yield 'U+3164 at the end' => ["Kurier\u{3164}", "Kurier\u{3164}"];
        yield 'U+FE0F after a trimmed space' => ["Kurier\u{2764}\u{FE0F}\u{A0}", "Kurier\u{2764}\u{FE0F}"];
    }

    #[DataProvider('invisibleCharactersNextToVisibleOnes')]
    public function testOnlyTheFourInvisibleCharactersAreTrimmedFromTheEnds(string $text, string $expected): void
    {
        self::assertSame($expected, Text::normalize($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function preservedTexts(): iterable
    {
        yield from InputValues::preservedTexts();
        yield 'a heart with U+FE0F after it' => ["Serce \u{2764}\u{FE0F}"];
        yield 'a heart with U+FE0F at the start' => ["\u{2764}\u{FE0F} serce"];
        yield 'only the flag of England' => ["\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"];
        yield 'a family of emoji joined by U+200D' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"];
        yield 'spaces inside' => ['Kurier  ekspresowy'];
    }

    #[DataProvider('preservedTexts')]
    public function testFormatCharactersNextToAVisibleCharacterAreKept(string $text): void
    {
        self::assertFalse(Text::hasForbiddenCharacters($text));
        self::assertSame($text, Text::normalize($text));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function lengths(): iterable
    {
        yield 'ASCII' => ['Kurier', 6];
        yield 'Polish letters count as one each' => ['żółć', 4];
        yield 'trimmed of no-break spaces' => ["\u{A0}\u{A0}Kurier\u{A0}", 6];
        yield 'trimmed of zero width spaces' => ["\u{200B}Kurier\u{200B}\u{FEFF}", 6];
        yield 'a hundred letters between spaces' => [str_repeat("\u{3000}", 5).str_repeat('a', 100).' ', 100];
        yield 'code points of the flag of England' => ["\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", 7];
        yield 'an empty text' => ["\u{200B}\u{3164}", 0];
    }

    #[DataProvider('lengths')]
    public function testTheLengthCountsCodePointsAfterTheTrimming(string $text, int $length): void
    {
        self::assertSame($length, Text::length($text));
    }

    /**
     * A query parameter may come as no valid UTF-8 and a normalizer of the validator runs before the check of the
     * characters: the text is left for that check, never an error.
     */
    #[DataProvider('invalidUtf8Texts')]
    public function testATextThatIsNotValidUtf8IsNotNormalized(string $text): void
    {
        self::assertSame(' '.$text.' ', Text::normalize(' '.$text.' '));
    }
}
