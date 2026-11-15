<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Query\StockLevelCursor;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockLevelCursorTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testACursorKeepsItsLevelAndItsProductThroughTheText(): void
    {
        $text = StockLevelCursor::after(AvailabilityLevel::LowStock, ProductId::fromString(self::PRODUCT))->encode();

        $cursor = StockLevelCursor::decode($text, AvailabilityLevel::LowStock);

        self::assertSame(self::PRODUCT, $cursor->productId->toString());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $text);
    }

    public function testACursorOfAnotherLevelIsNoCursor(): void
    {
        $text = StockLevelCursor::after(AvailabilityLevel::OutOfStock, ProductId::fromString(self::PRODUCT))->encode();

        $this->expectException(InvalidStockListCursor::class);

        StockLevelCursor::decode($text, AvailabilityLevel::LowStock);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'characters outside base64url' => ['garbage!'];
        yield 'not JSON' => [self::base64('hello')];
        yield 'a list' => [self::base64('["out_of_stock","'.self::PRODUCT.'"]')];
        yield 'the cursor of the name list' => [self::base64('{"s":"name","v":"Plecak","i":"'.self::PRODUCT.'"}')];
        yield 'an extra key' => [self::base64('{"l":"out_of_stock","p":"'.self::PRODUCT.'","x":1}')];
        yield 'no product' => [self::base64('{"l":"out_of_stock"}')];
        yield 'a product that is no UUID' => [self::base64('{"l":"out_of_stock","p":"plecak"}')];
        yield 'a UUID in capitals' => [self::base64('{"l":"out_of_stock","p":"'.strtoupper(self::PRODUCT).'"}')];
        yield 'a product that is not a text' => [self::base64('{"l":"out_of_stock","p":5}')];
        yield 'a level that is not a text' => [self::base64('{"l":["out_of_stock"],"p":"'.self::PRODUCT.'"}')];
        yield 'too long' => [str_repeat('A', 3000)];
    }

    #[DataProvider('notCursors')]
    public function testWhatWasNotHandedOutIsRejectedWithoutEchoingIt(string $text): void
    {
        try {
            StockLevelCursor::decode($text, AvailabilityLevel::OutOfStock);
            self::fail('Expected InvalidStockListCursor.');
        } catch (InvalidStockListCursor $invalid) {
            self::assertStringNotContainsString('garbage', $invalid->getMessage());
        }
    }

    private static function base64(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
