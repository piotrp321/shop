<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Stock\InvalidReceiptKey;
use App\Inventory\Domain\Stock\ReceiptKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validKeys(): iterable
    {
        yield 'a uuid' => ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'];
        yield 'every allowed character' => ['Az09._:-'];
        yield 'one character' => ['a'];
        yield '100 characters' => [str_repeat('k', 100)];
        yield 'a seed key' => ['seed:0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'];
    }

    #[DataProvider('validKeys')]
    public function testAKeyOfAllowedCharactersIsKeptAsItIs(string $value): void
    {
        $key = ReceiptKey::fromString($value);

        self::assertSame($value, $key->toString());
        self::assertTrue($key->equals(ReceiptKey::fromString($value)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield '101 characters' => [str_repeat('k', 101)];
        yield 'a space' => ['key 1'];
        yield 'a slash' => ['key/1'];
        yield 'a trailing newline' => ["key\n"];
        yield 'a letter outside ASCII' => ['klucz-ż'];
    }

    #[DataProvider('malformedKeys')]
    public function testAMalformedKeyIsInvalidInputWithoutTheValueInTheMessage(string $value): void
    {
        try {
            ReceiptKey::fromString($value);
            self::fail('Expected InvalidReceiptKey.');
        } catch (InvalidReceiptKey $invalid) {
            self::assertSame('A receipt key must have 1 to 100 characters: letters, digits, ".", "_", ":" or "-".', $invalid->getMessage());
        }
    }

    public function testKeysDifferingInLetterCaseAreDifferent(): void
    {
        self::assertFalse(ReceiptKey::fromString('key')->equals(ReceiptKey::fromString('KEY')));
    }
}
