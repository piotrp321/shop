<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\InvalidProductCursor;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductCursorTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    /**
     * @return iterable<string, array{ProductSort}>
     */
    public static function sorts(): iterable
    {
        foreach (ProductSort::cases() as $sort) {
            yield $sort->value => [$sort];
        }
    }

    #[DataProvider('sorts')]
    public function testACursorOfASortSurvivesTheRoundTripWithItsPosition(ProductSort $sort): void
    {
        $product = new BackofficeProduct(self::ID, 'Żel "energetyczny"', 'standard', new \DateTimeImmutable('2026-11-05 10:11:12.345678+01:00'));

        $cursor = ProductCursor::decode(ProductCursor::after($product, $sort)->encode(), $sort);

        self::assertSame($sort, $cursor->sort);
        self::assertSame(self::ID, $cursor->id);
        self::assertEquals($sort->byUpdatedAt() ? new \DateTimeImmutable('2026-11-05 09:11:12.345678+00:00') : 'Żel "energetyczny"', $cursor->value);
    }

    /**
     * @return iterable<string, array{ProductSort, ProductSort}>
     */
    public static function pairsOfDifferentSorts(): iterable
    {
        foreach (ProductSort::cases() as $issued) {
            foreach (ProductSort::cases() as $used) {
                if ($issued !== $used) {
                    yield $issued->value.' used as '.$used->value => [$issued, $used];
                }
            }
        }
    }

    #[DataProvider('pairsOfDifferentSorts')]
    public function testACursorOfAnotherSortIsRejected(ProductSort $issued, ProductSort $used): void
    {
        $text = ProductCursor::after(new BackofficeProduct(self::ID, 'Alfa', 'standard', new \DateTimeImmutable('2026-11-05 10:00:00+00:00')), $issued)->encode();

        $this->expectException(InvalidProductCursor::class);

        ProductCursor::decode($text, $used);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedMoments(): iterable
    {
        yield 'a date only' => [['s' => '-updatedAt', 'v' => '2026-11-05', 'i' => self::ID]];
        yield 'not a moment' => [['s' => '-updatedAt', 'v' => 'yesterday', 'i' => self::ID]];
        yield 'another offset' => [['s' => '-updatedAt', 'v' => '2026-11-05T10:00:00.000000+01:00', 'i' => self::ID]];
        yield 'a day that does not exist' => [['s' => '-updatedAt', 'v' => '2026-02-31T10:00:00.000000+00:00', 'i' => self::ID]];
        yield 'a moment with a NUL' => [['s' => '-updatedAt', 'v' => "2026-11-05T10:00:00.000000+00:00\0", 'i' => self::ID]];
        yield 'a number' => [['s' => '-updatedAt', 'v' => 5, 'i' => self::ID]];
        yield 'an empty name' => [['s' => '-name', 'v' => '', 'i' => self::ID]];
        yield 'without the sort' => [['v' => 'Alfa', 'i' => self::ID]];
        yield 'with the old keys' => [['n' => 'Alfa', 'i' => self::ID]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedMoments')]
    public function testAMalformedPositionIsRejected(array $payload): void
    {
        $text = rtrim(strtr(base64_encode(json_encode($payload, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $sort = ProductSort::tryFrom(\is_string($payload['s'] ?? null) ? $payload['s'] : '') ?? ProductSort::UpdatedAtDescending;

        $this->expectException(InvalidProductCursor::class);

        ProductCursor::decode($text, $sort);
    }
}
