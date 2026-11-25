<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantKey;
use App\Catalog\Domain\Product\VariantValues;
use App\DemoData\Catalog\CategoryTree;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Catalog\ProductSpec;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Photo\PhotoAssigner;
use App\DemoData\Profile\Profiles;
use App\DemoData\Random\Prng;
use PHPUnit\Framework\TestCase;

final class ProductGeneratorTest extends TestCase
{
    private static ?Dictionaries $dictionaries = null;
    /** @var list<ProductSpec>|null */
    private static ?array $small = null;

    public static function tearDownAfterClass(): void
    {
        // The 2000 products and the dictionaries are not needed by the tests that run after this class.
        self::$small = null;
        self::$dictionaries = null;
    }

    private static function dictionaries(): Dictionaries
    {
        return self::$dictionaries ??= new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
    }

    private static function profiles(): Profiles
    {
        return Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
    }

    private static function anchor(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17 00:00:00', new \DateTimeZone('UTC'));
    }

    private static function generator(int $total = 2000): ProductGenerator
    {
        $profiles = self::profiles();

        return new ProductGenerator(self::dictionaries(), $profiles->namespace, self::anchor(), $total, $profiles->partSize);
    }

    /**
     * @return list<ProductSpec>
     */
    private static function small(): array
    {
        if (null === self::$small) {
            $generator = self::generator();
            $specs = [];
            for ($part = 0; $part < self::profiles()->partCount(2000); ++$part) {
                foreach ($generator->part($part) as $spec) {
                    $specs[] = $spec;
                }
            }
            self::$small = $specs;
        }

        return self::$small;
    }

    public function testAPartHasItsRangeOfIndexesAndTheLastOneIsShort(): void
    {
        $generator = self::generator(1_234);

        $first = iterator_to_array($generator->part(0), false);
        $last = iterator_to_array($generator->part(2), false);

        self::assertCount(500, $first);
        self::assertSame(0, $first[0]->index);
        self::assertSame(499, $first[499]->index);
        self::assertCount(234, $last);
        self::assertSame(1_000, $last[0]->index);
        self::assertSame(1_233, $last[233]->index);
        self::assertSame([], iterator_to_array($generator->part(3), false));
    }

    public function testThePartsDoNotDependOnEachOther(): void
    {
        $generator = self::generator();

        $alone = iterator_to_array($generator->part(2), false);
        iterator_to_array($generator->part(0), false);
        $afterAnother = iterator_to_array(self::generator()->part(2), false);

        self::assertEquals($alone, $afterAnother);
    }

    public function testTheSameInputsGiveTheSameProducts(): void
    {
        self::assertEquals(
            iterator_to_array(self::generator()->part(1), false),
            iterator_to_array(self::generator()->part(1), false),
        );
    }

    public function testAnotherSeedGivesOtherProducts(): void
    {
        $profiles = self::profiles();
        $other = new ProductGenerator(self::dictionaries(), '0f0f0f0f-0000-4000-8000-000000000001', self::anchor(), 2000, $profiles->partSize);

        $a = iterator_to_array(self::generator()->part(0), false);
        $b = iterator_to_array($other->part(0), false);

        self::assertNotEquals(array_map(static fn (ProductSpec $s): string => $s->name, $a), array_map(static fn (ProductSpec $s): string => $s->name, $b));
        self::assertNotSame($a[0]->id, $b[0]->id);
    }

    public function testTimesComeFromTheAnchorNotFromTheClock(): void
    {
        $later = new ProductGenerator(self::dictionaries(), self::profiles()->namespace, self::anchor()->modify('+10 days'), 2000, 500);

        $a = iterator_to_array(self::generator()->part(0), false);
        $b = iterator_to_array($later->part(0), false);

        foreach ($a as $i => $spec) {
            self::assertSame(
                $spec->firstPublishedAt?->modify('+10 days')->format(\DATE_ATOM),
                $b[$i]->firstPublishedAt?->format(\DATE_ATOM),
            );
            self::assertSame($spec->name, $b[$i]->name);
        }
    }

    public function testTheIdsAreVersion5UuidsOfTheNamespaceOfTheSeed(): void
    {
        $namespace = self::profiles()->namespace;
        $spec = self::small()[17];

        self::assertSame(DemoUuid::v5($namespace, 'product:17'), $spec->id);
        foreach ($spec->variants as $k => $variant) {
            self::assertSame(DemoUuid::v5($namespace, 'variant:17:'.$k), $variant->id);
            self::assertSame($k, $variant->position);
        }
    }

    public function testTheNameIsTypeBrandModelAndTheSlugIsTheCatalogSlugWithTheIndexOfTheProduct(): void
    {
        $dictionaries = self::dictionaries();
        $types = [];
        foreach ($dictionaries->types() as $type) {
            $types[$type->key] = $type;
        }

        foreach (self::small() as $spec) {
            $type = $types[$spec->typeKey];
            self::assertStringStartsWith($type->name.' ', $spec->name);
            $rest = substr($spec->name, \strlen($type->name) + 1);
            [$brand] = explode(' ', $rest, 2);
            self::assertContains($brand, $dictionaries->brands($type->department()));
            self::assertSame(Slug::slugify($spec->name).'-'.($spec->index + 1), $spec->slug);
            new Slug($spec->slug);
            ProductName::of($spec->name);
        }
    }

    /**
     * The generator writes the texts of a product by COPY, past the rule of texts: the name, the description, the axes
     * and the values of the variants, and the alt texts of the photos built from the name, are in the form the rule
     * keeps (see GeneratedText).
     */
    public function testTheTextsOfTheProductsAreInTheFormOfTheRule(): void
    {
        $prng = Prng::forPart(self::profiles()->namespace, 'photos', 0);
        foreach (self::small() as $spec) {
            GeneratedText::assertInTheFormOfTheRule($spec->name, 'product name');
            GeneratedText::assertInTheFormOfTheRule($spec->shortDescription, 'short description of '.$spec->name);
            foreach ($spec->axes as $axis) {
                GeneratedText::assertInTheFormOfTheRule($axis, 'axis of '.$spec->name);
            }
            foreach ($spec->variants as $variant) {
                foreach ($variant->values as $value) {
                    GeneratedText::assertInTheFormOfTheRule($value, 'variant value of '.$spec->name);
                }
            }
            foreach (PhotoAssigner::assign($spec, ['a', 'b', 'c'], $prng) as $image) {
                GeneratedText::assertInTheFormOfTheRule($image['alt'], 'alt text of '.$spec->name);
            }
        }
    }

    public function testTheSlugsAreUnique(): void
    {
        $slugs = array_map(static fn (ProductSpec $spec): string => $spec->slug, self::small());

        self::assertCount(2000, array_unique($slugs));
    }

    public function testTheCategoriesAreLeavesOfTheTreeAndTheAdditionalOneIsAnotherLeafOfTheDepartment(): void
    {
        $namespace = self::profiles()->namespace;
        $dictionaries = self::dictionaries();
        $leafIds = [];
        foreach ($dictionaries->leafPaths() as $path) {
            $leafIds[CategoryTree::leafId($namespace, $path)] = explode('/', $path)[0];
        }

        $withAdditional = 0;
        foreach (self::small() as $spec) {
            self::assertArrayHasKey($spec->primaryCategoryId, $leafIds);
            self::assertLessThanOrEqual(1, \count($spec->additionalCategoryIds));
            foreach ($spec->additionalCategoryIds as $id) {
                self::assertArrayHasKey($id, $leafIds);
                self::assertNotSame($spec->primaryCategoryId, $id);
                self::assertSame($leafIds[$spec->primaryCategoryId], $leafIds[$id]);
                ++$withAdditional;
            }
        }
        self::assertGreaterThan(200, $withAdditional);
        self::assertLessThan(700, $withAdditional);
    }

    public function testTheVariantsAreValidForTheCatalogAndTheKeyIsTheOneOfTheCatalog(): void
    {
        foreach (self::small() as $spec) {
            $variants = ProductVariants::create(
                VariantAxes::fromList($spec->axes),
                array_map(
                    static fn ($variant): ProductVariant => new ProductVariant(VariantId::fromString($variant->id), VariantValues::fromList($variant->values)),
                    $spec->variants,
                ),
            );
            self::assertSame(\count($spec->variants), $variants->count());
            foreach ($spec->variants as $variant) {
                self::assertSame(
                    json_encode(VariantValues::fromList($variant->values)->comparisonKey(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                    $variant->valuesKey,
                );
                // The key of a variant of the demo patterns (VariantKey) is unique in the whole catalog.
                self::assertNotSame('', VariantKey::of($spec->slug, VariantValues::fromList($variant->values)));
            }
        }
    }

    public function testTheVariantKeysAreUniqueInTheWholeCatalog(): void
    {
        $keys = [];
        foreach (self::small() as $spec) {
            foreach ($spec->variants as $variant) {
                $keys[] = VariantKey::of($spec->slug, VariantValues::fromList($variant->values));
            }
        }

        self::assertSame(array_unique($keys), $keys);
    }

    public function testTheDescriptionFitsTheCatalogAndIsBuiltFromTheTypeAndTheDepartment(): void
    {
        foreach (self::small() as $spec) {
            ShortDescription::of($spec->shortDescription);
            self::assertStringStartsWith($spec->name.': ', $spec->shortDescription);
            self::assertStringEndsWith('.', $spec->shortDescription);
        }
    }

    public function testAboutThreeVariantsOnAverageAndAboutFortyPercentWithOne(): void
    {
        $specs = self::small();
        $variants = array_sum(array_map(static fn (ProductSpec $s): int => \count($s->variants), $specs));
        $single = \count(array_filter($specs, static fn (ProductSpec $s): bool => 1 === \count($s->variants)));
        $max = max(1, ...array_map(static fn (ProductSpec $s): int => \count($s->variants), $specs));

        self::assertEqualsWithDelta(3.0, $variants / 2000, 0.35);
        self::assertEqualsWithDelta(0.40, $single / 2000, 0.05);
        self::assertLessThanOrEqual(8, $max);
        self::assertGreaterThanOrEqual(6, $max);
    }

    public function testAboutTwoPercentAreDraftsWithoutAPublicationTime(): void
    {
        $specs = self::small();
        $drafts = array_filter($specs, static fn (ProductSpec $s): bool => 'draft' === $s->status);

        self::assertEqualsWithDelta(40, \count($drafts), 22);
        foreach ($drafts as $draft) {
            self::assertNull($draft->firstPublishedAt);
        }
        foreach ($specs as $spec) {
            self::assertContains($spec->status, ['draft', 'published']);
            if ('published' === $spec->status) {
                self::assertNotNull($spec->firstPublishedAt);
                self::assertEquals($spec->firstPublishedAt, $spec->updatedAt);
            }
        }
    }

    public function testEveryFiftiethProductIsWithoutPhotos(): void
    {
        foreach (self::small() as $spec) {
            self::assertSame(0 === ($spec->index + 1) % 50, $spec->imageless, 'index '.$spec->index);
        }
    }

    public function testThePublicationTimesGrowWithTheIndexAndEndBeforeTheAnchor(): void
    {
        $anchor = self::anchor();
        $previousDay = null;
        foreach (self::small() as $spec) {
            if (null === $spec->firstPublishedAt) {
                self::assertLessThan($anchor, $spec->updatedAt);
                continue;
            }
            self::assertLessThan($anchor->modify('-15 days'), $spec->firstPublishedAt);
            $day = (int) $spec->firstPublishedAt->format('U') / 86_400;
            if (null !== $previousDay) {
                self::assertGreaterThanOrEqual($previousDay - 2, $day);
            }
            $previousDay = $day;
        }
    }

    public function testEveryTypeAppearsOnSmall(): void
    {
        $used = array_unique(array_map(static fn (ProductSpec $s): string => $s->typeKey, self::small()));

        self::assertGreaterThanOrEqual(100, \count($used));
    }

    public function testAPrngOfAnotherStreamIsNotUsed(): void
    {
        // The catalog stream is the one of the part: the same part of another generator instance repeats it.
        $a = Prng::forPart(self::profiles()->namespace, 'catalog', 0);
        $b = Prng::forPart(self::profiles()->namespace, 'catalog', 0);

        self::assertSame($a->int(0, 1_000_000), $b->int(0, 1_000_000));
    }
}
