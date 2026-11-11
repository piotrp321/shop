<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

/**
 * What the tests of the panel's endpoints with required body keys share: the bodies with a key omitted and the
 * violations that must answer them, one Polish "required" at each omitted key (spec docs/features/quality-fixes, #257).
 */
trait RequiredKeyViolationsTrait
{
    /**
     * Each key omitted on its own, then all of them (an empty object).
     *
     * @param list<string> $keys
     *
     * @return iterable<string, array{list<string>}>
     */
    private static function omittedKeys(array $keys): iterable
    {
        foreach ($keys as $key) {
            yield 'only '.$key.' omitted' => [[$key]];
        }
        yield 'every key omitted' => [$keys];
    }

    /**
     * The body of the omitted keys left out; a string when nothing is left, because PHP encodes an empty array as a list.
     *
     * @param array<string, mixed> $valid
     * @param list<string>         $omitted
     *
     * @return array<string, mixed>|string
     */
    private static function bodyWithout(array $valid, array $omitted): array|string
    {
        $body = array_diff_key($valid, array_flip($omitted));

        return [] === $body ? '{}' : $body;
    }

    /**
     * @param list<string> $omitted
     */
    private static function assertRequiredAt(array $omitted, mixed $violations): void
    {
        self::assertSame(
            array_map(static fn (string $key): array => [$key, 'To pole jest wymagane.'], $omitted),
            self::pathsWithTitles($violations),
        );
    }

    /**
     * @return list<array{string, string}> the property path and the title of every violation, in order
     */
    private static function pathsWithTitles(mixed $violations): array
    {
        self::assertIsArray($violations);
        $pairs = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            self::assertIsString($violation['title']);
            $pairs[] = [$violation['propertyPath'], $violation['title']];
        }

        return $pairs;
    }
}
