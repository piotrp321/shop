<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantNotFound;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceValuesTest extends TestCase
{
    public function testProductIdIsALowerCaseUuid(): void
    {
        $id = ProductId::fromString('0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60');

        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', $id->toString());
        self::assertTrue($id->equals(ProductId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    #[DataProvider('malformedIds')]
    public function testProductIdRejectsAMalformedUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductId::fromString($value);
    }

    public function testVariantIdIsALowerCaseUuid(): void
    {
        $id = VariantId::fromString('0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60');

        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', $id->toString());
        self::assertTrue($id->equals(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    #[DataProvider('malformedIds')]
    public function testVariantIdRejectsAMalformedUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VariantId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedIds(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['product-1'];
        yield 'trailing newline' => ["0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60\n"];
    }

    public function testStaffChangeCarriesTheStaffMember(): void
    {
        $origin = PriceChangeOrigin::staff('f6a1c1d2-0000-4000-8000-000000000001', 'Anna Admin');

        self::assertSame(PriceChangeSource::Staff, $origin->source());
        self::assertSame('f6a1c1d2-0000-4000-8000-000000000001', $origin->actorId());
        self::assertSame('Anna Admin', $origin->actorName());
    }

    public function testSystemChangesHaveNoActor(): void
    {
        $seed = PriceChangeOrigin::seed();
        $reconciliation = PriceChangeOrigin::taxReconciliation();

        self::assertSame(PriceChangeSource::Seed, $seed->source());
        self::assertNull($seed->actorId());
        self::assertNull($seed->actorName());
        self::assertSame(PriceChangeSource::TaxReconciliation, $reconciliation->source());
        self::assertNull($reconciliation->actorId());
        self::assertNull($reconciliation->actorName());
    }

    #[DataProvider('invalidActors')]
    public function testStaffChangeNeedsAnActorIdAndName(string $actorId, string $actorName): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PriceChangeOrigin::staff($actorId, $actorName);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidActors(): iterable
    {
        yield 'empty id' => ['', 'Anna Admin'];
        yield 'empty name' => ['f6a1c1d2-0000-4000-8000-000000000001', ''];
        yield 'blank name' => ['f6a1c1d2-0000-4000-8000-000000000001', '   '];
        yield 'id longer than 255' => [str_repeat('a', 256), 'Anna Admin'];
        yield 'name longer than 255 characters' => ['f6a1c1d2-0000-4000-8000-000000000001', str_repeat('ż', 256)];
    }

    public function testConcurrentModificationIsAConflict(): void
    {
        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('The price was modified concurrently. Reload it and try again.');

        throw new PriceModifiedConcurrently();
    }

    public function testUnknownVariantIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('Wariant produktu nie istnieje.');

        throw new VariantNotFound();
    }
}
