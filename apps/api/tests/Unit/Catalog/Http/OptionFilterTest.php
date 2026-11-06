<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Http;

use App\Catalog\UI\Http\OptionFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class OptionFilterTest extends TestCase
{
    public function testNoOptionIsValidAndEmpty(): void
    {
        $filter = OptionFilter::fromQueryString('category=x&page=2');

        self::assertSame([], $this->paths($filter));
        self::assertSame([], $filter->axes());
    }

    public function testRepeatedKeyIsGroupedByAxisInTheOrderSent(): void
    {
        $filter = OptionFilter::fromQueryString('option='.rawurlencode('Kolor:czarne').'&q=a&option='.rawurlencode('Rozmiar:42').'&option='.rawurlencode('Kolor:biały').'&option='.rawurlencode('Kolor:czarne'));

        self::assertSame([], $this->paths($filter));
        self::assertSame(['Kolor' => ['czarne', 'biały'], 'Rozmiar' => ['42']], $filter->axes());
    }

    public function testValueIsSplitOnTheFirstColonOnly(): void
    {
        $filter = OptionFilter::fromQueryString('option='.rawurlencode('Skala:1:50'));

        self::assertSame([], $this->paths($filter));
        self::assertSame(['Skala' => ['1:50']], $filter->axes());
    }

    public function testPlusAndPercentEncodingAreDecoded(): void
    {
        $filter = OptionFilter::fromQueryString('option=Rozmiar+buta:42%20EU');

        self::assertSame(['Rozmiar buta' => ['42 EU']], $filter->axes());
    }

    public function testLimitsAreInclusive(): void
    {
        $entries = ['option='.rawurlencode(str_repeat('o', 30).':'.str_repeat('w', 40))];
        for ($i = 1; $i < 50; ++$i) {
            $entries[] = 'option='.rawurlencode('Kolor:'.$i);
        }

        self::assertSame([], $this->paths(OptionFilter::fromQueryString(implode('&', $entries))));
        self::assertSame([], $this->paths(OptionFilter::fromQueryString(implode('&', array_map(static fn (int $i): string => 'option='.rawurlencode('Os'.$i.':x'), range(1, 5))))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueryStrings(): iterable
    {
        yield 'no colon' => ['option=Kolor'];
        yield 'empty axis' => ['option=%3Aczarne'];
        yield 'empty value' => ['option=Kolor%3A'];
        yield 'empty entry' => ['option='];
        yield 'axis of 31 characters' => ['option='.str_repeat('o', 31).'%3Ax'];
        yield 'value of 41 characters' => ['option=Kolor%3A'.str_repeat('w', 41)];
        yield 'bracket form' => ['option%5B%5D=Kolor%3Aczarne'];
        yield 'indexed bracket form' => ['option[0]=Kolor%3Aczarne'];
        yield '51 entries' => [implode('&', array_map(static fn (int $i): string => 'option=Kolor%3A'.$i, range(1, 51)))];
        yield 'six axes' => [implode('&', array_map(static fn (int $i): string => 'option=Os'.$i.'%3Ax', range(1, 6)))];
    }

    #[DataProvider('invalidQueryStrings')]
    public function testInvalidOptionIsReportedOnTheOptionPath(string $queryString): void
    {
        self::assertSame(['option'], array_unique($this->paths(OptionFilter::fromQueryString($queryString))));
    }

    public function testViolationMessagesNeverContainTheSubmittedValue(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        foreach ($validator->validate(OptionFilter::fromQueryString('option=SecretAxisWithoutColon')) as $violation) {
            self::assertStringNotContainsString('SecretAxis', (string) $violation->getMessage());
        }
    }

    public function testCollectingStopsOnceTheLimitIsExceeded(): void
    {
        $filter = OptionFilter::fromQueryString(implode('&', array_fill(0, 5000, 'option=Kolor%3Ax')));

        self::assertCount(51, $filter->entries());
    }

    /**
     * @return list<string>
     */
    private function paths(OptionFilter $filter): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $paths = [];
        foreach ($validator->validate($filter) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
