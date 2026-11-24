<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Shared\Domain\Text;
use PHPUnit\Framework\Assert;

/**
 * The contract of the texts the demo data generator writes: it writes by COPY, past the domain, so a text of a field
 * under the rule of texts (App\Shared\Domain\Text) must already be in the form the rule keeps: no forbidden character,
 * a visible character, nothing the rule trims at the ends. The value objects of the domain trim instead of refusing,
 * so building one does not show a text written untrimmed.
 */
final class GeneratedText
{
    private function __construct()
    {
    }

    public static function assertInTheFormOfTheRule(string $text, string $what): void
    {
        $shown = json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE);
        Assert::assertFalse(Text::hasForbiddenCharacters($text), $what.': a forbidden character in '.$shown);
        Assert::assertNotSame('', Text::normalize($text), $what.': no visible character in '.$shown);
        Assert::assertSame(Text::normalize($text), $text, $what.': the rule trims '.$shown);
    }

    /**
     * @param iterable<string> $texts
     */
    public static function assertAllInTheFormOfTheRule(iterable $texts, string $what): void
    {
        $count = 0;
        foreach ($texts as $text) {
            self::assertInTheFormOfTheRule($text, $what);
            ++$count;
        }
        Assert::assertGreaterThan(0, $count, $what.': no text was checked');
    }
}
