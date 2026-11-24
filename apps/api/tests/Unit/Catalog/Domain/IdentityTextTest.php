<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\IdentityText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The identity of an axis name or a variant value after the first publication (K5; docs/features/catalog-admin/spec.md,
 * „Tożsamość wariantu po publikacji”).
 */
final class IdentityTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function storedAndGiven(): iterable
    {
        // A stored text the rule takes: trimmed by the rule, without regard to case.
        yield 'the same text in another case' => ['Czarne', 'czarne', true];
        yield 'a text stored with U+200B at its end' => ["czarne\u{200B}", 'czarne', true];
        yield 'a text stored with U+FEFF at its start' => ["\u{FEFF}Kolor", 'kolor', true];
        yield 'another text' => ['czarne', 'białe', false];
        yield 'a text stored with U+200B, changed' => ["czarne\u{200B}", 'białe', false];
        yield 'a space inside is part of the text' => ['czar ne', 'czarne', false];
        // A stored text with a forbidden character: its correction only.
        yield 'U+009F inside, corrected' => ["czar\u{9F}ne", 'czarne', true];
        yield 'U+009F inside, corrected with a space' => ["czar\u{9F}ne", 'czar ne', true];
        yield 'U+009F inside, corrected in another case' => ["czar\u{9F}ne", 'Czarne', true];
        yield 'U+009F inside, changed' => ["czar\u{9F}ne", 'zielone', false];
        yield 'a tab inside, corrected' => ["Kol\tor", 'Kolor', true];
        yield 'a tab inside, corrected with a space' => ["Kol\tor", 'Kol or', true];
        yield 'a tab inside, renamed' => ["Kol\tor", 'Materiał', false];
        yield 'a tab between words, corrected with a space' => ["M\tL", 'M L', true];
        yield 'a tab between words, corrected without it' => ["M\tL", 'ML', true];
        yield 'a tab between words, changed' => ["M\tL", 'M/L', false];
        yield 'U+2028 at the end, corrected' => ["XL\u{2028}", 'XL', true];
        // A stored text without a visible character: no identity.
        yield 'U+200B only' => ["\u{200B}", 'zielone', true];
        yield 'U+3164 only' => ["\u{3164}", 'Materiał', true];
        yield 'a forbidden character only' => ["\u{9F}", 'zielone', true];
        yield 'a tab and U+200B' => ["\t\u{200B}", 'zielone', true];
    }

    #[DataProvider('storedAndGiven')]
    public function testAGivenTextKeepsTheIdentityOfTheStoredOneOnlyAsTheRuleSays(string $stored, string $given, bool $keeps): void
    {
        self::assertSame($keeps, IdentityText::kept([$stored], [$given]));
    }

    public function testTheValuesOfAVariantKeepTheirIdentityPositionByPosition(): void
    {
        self::assertTrue(IdentityText::kept(["czar\u{9F}ne", "M\u{200B}"], ['czarne', 'M']));
        self::assertFalse(IdentityText::kept(["czar\u{9F}ne", 'M'], ['czarne', 'L']));
        self::assertFalse(IdentityText::kept(['czarne', 'M'], ['M', 'czarne']));
        self::assertFalse(IdentityText::kept(['czarne'], ['czarne', 'M']));
    }

    public function testAStoredTextWithoutAVisibleCharacterHasNoIdentity(): void
    {
        self::assertTrue(IdentityText::has("Kol\tor"));
        self::assertTrue(IdentityText::has('Kolor'));
        self::assertFalse(IdentityText::has("\u{200B}"));
        self::assertFalse(IdentityText::has("\t\u{3164}"));
    }
}
