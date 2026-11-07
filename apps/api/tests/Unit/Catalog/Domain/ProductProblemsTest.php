<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ImageNotFound;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductNotPublishable;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\SlugLocked;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\UnknownVariant;
use App\Catalog\Domain\Product\VariantIdentityLocked;
use App\Catalog\Domain\Product\VariantInUse;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\TypedProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The problems of a product write the panel tells apart: the 409s by their `type`, every one with a Polish detail.
 */
final class ProductProblemsTest extends TestCase
{
    private const string PREFIX = 'tag:piotrp321.github.io,2026:shop/problems/';

    /**
     * @return iterable<string, array{\Closure(): \Throwable, string, string}>
     */
    public static function conflicts(): iterable
    {
        yield 'slug taken' => [static fn (): \Throwable => new SlugAlreadyTaken(), 'slug-taken', 'Ten slug ma już inny produkt. Podaj własny slug.'];
        yield 'slug locked' => [static fn (): \Throwable => new SlugLocked(), 'slug-locked', 'Slug produktu nie może się zmienić po pierwszej publikacji.'];
        yield 'product modified' => [
            static fn (): \Throwable => new ProductModifiedConcurrently(),
            'product-modified',
            'Produkt został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.',
        ];
        yield 'not publishable' => [static fn (): \Throwable => ProductNotPublishable::withoutImages(), 'product-not-publishable', 'Dodaj co najmniej jedno zdjęcie przed publikacją.'];
        yield 'variant locked' => [
            static fn (): \Throwable => VariantIdentityLocked::axes(),
            'variant-locked',
            'Po pierwszej publikacji nie można dodać, usunąć ani przestawić osi wariantów.',
        ];
        yield 'variant in use' => [
            static fn (): \Throwable => new VariantInUse('czarne, 42'),
            'variant-in-use',
            'Wariant „czarne, 42” ma stan magazynowy albo rezerwacje. Najpierw skoryguj stan do zera.',
        ];
    }

    /**
     * @param \Closure(): \Throwable $exception
     */
    #[DataProvider('conflicts')]
    public function testEveryConflictIsATypedProblemWithAPolishDetail(\Closure $exception, string $type, string $detail): void
    {
        $problem = $exception();

        self::assertInstanceOf(Conflict::class, $problem);
        self::assertInstanceOf(TypedProblem::class, $problem);
        self::assertSame(self::PREFIX.$type, $problem->problemType());
        self::assertSame(self::PREFIX.$type, \constant($problem::class.'::TYPE'));
        self::assertSame($detail, $problem->getMessage());
    }

    public function testAnUnknownVariantIsInvalidInputAtItsRow(): void
    {
        $problem = new UnknownVariant(3);

        self::assertInstanceOf(InvalidInput::class, $problem);
        self::assertNotInstanceOf(TypedProblem::class, $problem);
        self::assertSame(3, $problem->index);
        self::assertSame('Wariant nie należy do tego produktu.', $problem->getMessage());
    }

    public function testAMissingImageOriginalIsInvalidInputAtItsImage(): void
    {
        $problem = new ImageNotFound(1);

        self::assertInstanceOf(InvalidInput::class, $problem);
        self::assertSame(1, $problem->index);
        self::assertSame('Nie ma takiego zdjęcia. Wyślij plik ponownie.', $problem->getMessage());
    }

    public function testASlugThatCannotBeDerivedIsInvalidInput(): void
    {
        $problem = new SlugRequired();

        self::assertInstanceOf(InvalidInput::class, $problem);
        self::assertSame('Podaj slug.', $problem->getMessage());
    }
}
