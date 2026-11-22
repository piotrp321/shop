<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * The texts of the Polish catalogue of the validator of the framework that the rows expect (the abbreviations of the
 * spec, section B: T, pusta, UUID, krótka(n), długa(n), znaki, opcja, zakres(max)), and the rows the lists share:
 * the same parameter with the same rules on several operations.
 */
final class ValidatorRows
{
    /** T: the type error of #[MapQueryString] and #[MapRequestPayload], from the catalogue of the project. */
    public const string TYPE = 'Ta wartość ma nieprawidłowy typ.';
    public const string BLANK = 'Ta wartość nie powinna być pusta.';
    public const string UUID = 'Ta wartość nie jest prawidłowym UUID.';
    public const string CHARSET = 'Ta wartość nie pasuje do oczekiwanego zestawu znaków UTF-8.';
    public const string CHOICE = 'Ta wartość powinna być jedną z podanych opcji.';
    public const string POSITIVE = 'Ta wartość powinna być dodatnia.';
    public const string AT_LEAST_ONE = 'Ten zbiór powinien zawierać 1 lub więcej elementów.';

    public static function tooShort(int $limit): string
    {
        return \sprintf('Ta wartość jest zbyt krótka. Powinna mieć %d lub więcej znaków.', $limit);
    }

    public static function tooLong(int $limit): string
    {
        return \sprintf('Ta wartość jest zbyt długa. Powinna mieć %d lub mniej znaków.', $limit);
    }

    public static function range(int $max): string
    {
        return \sprintf('Ta wartość powinna być pomiędzy 1 a %d.', $max);
    }

    /**
     * `category` of the lists of products, stocks and prices: a UUID of a category.
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null $token
     *
     * @return iterable<InputCase>
     */
    public static function category(string $operation, ?string $token): iterable
    {
        foreach (['category[]=x' => self::TYPE, 'category=' => self::BLANK, 'category=abc' => self::UUID] as $invalid => $title) {
            yield InputCase::query($operation, $token, 'product', 'category', $invalid, 'category={categoryId}', $invalid, [['category', $title]]);
        }
    }

    /**
     * `q` of the lists of products, stocks and prices: 2 to 100 characters.
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null $token
     *
     * @return iterable<InputCase>
     */
    public static function phrase(string $operation, ?string $token): iterable
    {
        $cases = [
            'q[]=x' => ['q[]=x', self::TYPE],
            '1 character' => ['q=a', self::tooShort(2)],
            '101 characters' => ['q='.str_repeat('a', 101), self::tooLong(100)],
            'invalid UTF-8' => ['q=%FF', self::CHARSET],
        ];
        foreach ($cases as $label => [$invalid, $title]) {
            yield InputCase::query($operation, $token, 'product', 'q', $label, 'q=namiot', $invalid, [['q', $title]]);
        }
    }

    /**
     * `perPage` of a list with Range(1, max).
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null $token
     *
     * @return iterable<InputCase>
     */
    public static function perPage(string $operation, ?string $token, string $fixture, int $max): iterable
    {
        foreach (['perPage=abc' => self::TYPE, 'perPage[]=x' => self::TYPE, 'perPage=0' => self::range($max), 'perPage='.($max + 1) => self::range($max)] as $invalid => $title) {
            yield InputCase::query($operation, $token, $fixture, 'perPage', $invalid, 'perPage='.$max, $invalid, [['perPage', $title]]);
        }
    }

    /**
     * `after` of a list with Length(1, 2048) and no message of its own; `$cursor` is one the list hands out.
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null $token
     *
     * @return iterable<InputCase>
     */
    public static function after(string $operation, ?string $token, string $fixture, string $cursor): iterable
    {
        $cases = [
            'after[]=x' => ['after[]=x', self::TYPE],
            'empty' => ['after=', self::tooShort(1)],
            '2049 characters' => ['after='.str_repeat('a', 2049), self::tooLong(2048)],
            'invalid UTF-8' => ['after=%FF', self::CHARSET],
        ];
        foreach ($cases as $label => [$invalid, $title]) {
            yield InputCase::query($operation, $token, $fixture, 'after', $label, 'after='.$cursor, $invalid, [['after', $title]]);
        }
    }

    /**
     * A parameter of another kind (`p[]=x`) with the message of its own the parameter keeps (table B2).
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null $token
     */
    public static function ofAnotherKind(string $operation, ?string $token, string $fixture, string $parameter, string $valid, string $title): InputCase
    {
        return InputCase::query($operation, $token, $fixture, $parameter, $parameter.'[]=x', $valid, $parameter.'[]=x', [[$parameter, $title]]);
    }
}
