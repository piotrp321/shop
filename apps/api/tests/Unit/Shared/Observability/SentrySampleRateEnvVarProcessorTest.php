<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\SentrySampleRateEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SENTRY_TRACES_SAMPLE_RATE is a number from 0 to 1 written as digits; anything else is 0 (no traces), as in the store and
 * the panel, so a wrong value never stops the API or the worker (issue #559).
 */
final class SentrySampleRateEnvVarProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function values(): iterable
    {
        yield 'zero' => ['0', 0.0];
        yield 'one' => ['1', 1.0];
        yield 'a tenth' => ['0.1', 0.1];
        yield 'surrounded by spaces' => [' 0.25 ', 0.25];
        yield 'one with a fraction' => ['1.0', 1.0];
        yield 'a decimal comma' => ['0,1', 0.0];
        yield 'empty' => ['', 0.0];
        yield 'only spaces' => ['  ', 0.0];
        yield 'a word' => ['abc', 0.0];
        yield 'above one' => ['1.5', 0.0];
        yield 'negative' => ['-0.1', 0.0];
        yield 'exponent' => ['1e-1', 0.0];
        yield 'a leading point' => ['.5', 0.0];
        yield 'not a string' => [null, 0.0];
    }

    #[DataProvider('values')]
    public function testTheRateIsTheWrittenNumberOrZero(mixed $value, float $expected): void
    {
        $rate = (new SentrySampleRateEnvVarProcessor())->getEnv('app_sample_rate', 'SENTRY_TRACES_SAMPLE_RATE', static fn (): mixed => $value);

        self::assertSame($expected, $rate);
    }

    public function testItProvidesAFloat(): void
    {
        self::assertSame(['app_sample_rate' => 'float'], SentrySampleRateEnvVarProcessor::getProvidedTypes());
    }
}
