<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Domain\Product\ProductParameter;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantValues;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DictionariesTest extends TestCase
{
    private static function directory(): string
    {
        return \dirname(__DIR__, 3).'/fixtures/demo-data';
    }

    private static function dictionaries(): Dictionaries
    {
        return new Dictionaries(self::directory());
    }

    public function testTheTreeHasAboutAHundredLeafCategoriesInThe7DepartmentsOfTheSeedCatalog(): void
    {
        $dictionaries = self::dictionaries();

        $leaves = $dictionaries->leafPaths();
        self::assertGreaterThanOrEqual(95, \count($leaves));
        self::assertLessThanOrEqual(115, \count($leaves));
        self::assertSame(array_unique($leaves), $leaves, 'A leaf path is listed twice.');

        /** @var array{categories: list<array{slug: string}>} $seed */
        $seed = Yaml::parseFile(\dirname(__DIR__, 3).'/fixtures/catalog.yaml');
        $seedDepartments = array_column($seed['categories'], 'slug');
        self::assertSame($seedDepartments, $dictionaries->departmentSlugs());
    }

    public function testNoGroupHasTheSlugOfAGroupOfTheSeedCatalogInTheSameDepartment(): void
    {
        /** @var array{categories: list<array{slug: string, children?: list<array{slug: string}>}>} $seed */
        $seed = Yaml::parseFile(\dirname(__DIR__, 3).'/fixtures/catalog.yaml');
        $dictionaries = self::dictionaries();

        foreach ($seed['categories'] as $department) {
            $taken = array_column($department['children'] ?? [], 'slug');
            self::assertSame([], array_values(array_intersect($taken, $dictionaries->groupSlugs($department['slug']))), 'A group collides in '.$department['slug']);
        }
    }

    /**
     * The generator writes the texts of the dictionaries by COPY, past the rule of texts: every entry is in the form the
     * rule keeps (see GeneratedText), so no product, category or description carries a character the rule would trim.
     */
    public function testEveryTextOfTheDictionariesIsInTheFormOfTheRule(): void
    {
        $dictionaries = self::dictionaries();

        GeneratedText::assertAllInTheFormOfTheRule(array_column($dictionaries->categoryDefinitions(), 'name'), 'category name');
        $types = $dictionaries->types();
        GeneratedText::assertAllInTheFormOfTheRule(array_map(static fn (ProductType $type): string => $type->name, $types), 'product type');
        foreach ($types as $type) {
            // A type may have no axis (a default variant) and no feature.
            foreach ($type->axes as $axis) {
                GeneratedText::assertInTheFormOfTheRule($axis->name, 'axis of '.$type->name);
            }
            foreach ($type->axes as $axis) {
                GeneratedText::assertAllInTheFormOfTheRule($axis->values, 'value of the axis '.$axis->name);
            }
            foreach ($type->features as $feature) {
                GeneratedText::assertInTheFormOfTheRule($feature, 'feature of '.$type->name);
            }
        }
        foreach ($dictionaries->departmentSlugs() as $department) {
            GeneratedText::assertAllInTheFormOfTheRule($dictionaries->brands($department), 'brand of '.$department);
            GeneratedText::assertAllInTheFormOfTheRule($dictionaries->closings($department), 'closing of '.$department);
        }
        GeneratedText::assertAllInTheFormOfTheRule($dictionaries->modelWords(), 'model word');
        // A suffix follows the number after one space; "" is no suffix.
        foreach (array_column($dictionaries->modelSuffixes(), 'text') as $suffix) {
            if ('' !== $suffix) {
                self::assertStringStartsWith(' ', $suffix);
                GeneratedText::assertInTheFormOfTheRule(substr($suffix, 1), 'model suffix');
            }
        }
    }

    public function testTheNamesAndSlugsOfTheCategoriesAreValidAndShort(): void
    {
        foreach (self::dictionaries()->categoryDefinitions() as $definition) {
            self::assertLessThanOrEqual(100, mb_strlen($definition['name']), $definition['name']);
            self::assertSame($definition['slug'], Slug::slugify($definition['slug']), $definition['slug']);
            new Slug($definition['slug']);
        }
    }

    public function testThereAreAboutOneHundredAndTwentyTypesAndEveryLeafHasOne(): void
    {
        $dictionaries = self::dictionaries();
        $types = $dictionaries->types();

        self::assertGreaterThanOrEqual(105, \count($types));
        self::assertLessThanOrEqual(135, \count($types));
        $covered = array_unique(array_map(static fn ($type): string => $type->leaf, $types));
        self::assertSame([], array_values(array_diff($dictionaries->leafPaths(), $covered)), 'A leaf category has no product type.');
        self::assertSame([], array_values(array_diff($covered, $dictionaries->leafPaths())), 'A type points at an unknown leaf.');
        $names = array_map(static fn ($type): string => $type->name, $types);
        self::assertSame(array_unique($names), $names, 'A type name is listed twice.');
    }

    public function testTheAxesAndValuesOfEveryTypeAreValidForTheCatalog(): void
    {
        foreach (self::dictionaries()->types() as $type) {
            $axes = VariantAxes::fromList(array_map(static fn ($axis): string => $axis->name, $type->axes));
            self::assertSame(\count($type->axes), $axes->count(), $type->name);
            foreach ($type->axes as $axis) {
                VariantValues::fromList($axis->values);
                self::assertSame(array_unique(array_map('mb_strtolower', $axis->values)), array_map('mb_strtolower', $axis->values), $type->name.': a repeated value');
            }
            self::assertGreaterThanOrEqual(1, $type->combinationCount(), $type->name);
            self::assertLessThanOrEqual(ProductVariants::MAX_VARIANTS, $type->combinationCount(), $type->name);
        }
    }

    public function testEveryTypeHasAPriceRangeAWeightFeaturesAndAKnownPhotoGroup(): void
    {
        $dictionaries = self::dictionaries();
        $groups = $dictionaries->photoGroupNames();

        foreach ($dictionaries->types() as $type) {
            self::assertGreaterThan(0, $type->priceMin, $type->name);
            self::assertGreaterThan($type->priceMin, $type->priceMax, $type->name);
            self::assertGreaterThan(0, $type->weight, $type->name);
            self::assertGreaterThanOrEqual(3, \count($type->features), $type->name);
            self::assertContains($type->photoGroup, $groups, $type->name);
            self::assertGreaterThanOrEqual(0.0, $type->single);
            self::assertLessThanOrEqual(1.0, $type->single);
        }
    }

    public function testTheTypesUseSeveralTaxClasses(): void
    {
        $classes = array_unique(array_map(static fn ($type): string => $type->taxClass->value, self::dictionaries()->types()));

        self::assertContains('standard', $classes);
        self::assertGreaterThanOrEqual(5, \count($classes));
    }

    public function testThereAreAboutThreeHundredBrandsDifferentFromEachOtherAndFromTheSeedCatalog(): void
    {
        $dictionaries = self::dictionaries();
        $all = [];
        foreach ($dictionaries->departmentSlugs() as $department) {
            $brands = $dictionaries->brands($department);
            self::assertGreaterThanOrEqual(30, \count($brands), $department);
            $all = [...$all, ...$brands];
        }

        self::assertGreaterThanOrEqual(280, \count($all));
        self::assertLessThanOrEqual(320, \count($all));
        self::assertSame(array_unique($all), $all, 'A brand is listed twice.');

        /** @var array{templates: list<array{brands: list<string>}>} $seed */
        $seed = Yaml::parseFile(\dirname(__DIR__, 3).'/fixtures/catalog.yaml');
        $seedBrands = array_unique(array_merge(...array_column($seed['templates'], 'brands')));
        self::assertSame([], array_values(array_intersect($all, $seedBrands)));
    }

    public function testTheModelsAndTheDescriptionsAreComplete(): void
    {
        $dictionaries = self::dictionaries();

        self::assertGreaterThanOrEqual(80, \count($dictionaries->modelWords()));
        self::assertGreaterThanOrEqual(10, \count($dictionaries->modelNumbers()));
        foreach ($dictionaries->departmentSlugs() as $department) {
            self::assertGreaterThanOrEqual(3, \count($dictionaries->closings($department)), $department);
        }
    }

    public function testTheParametersOfEveryTypeAreValidForTheCatalogEnoughToDrawAndNeverAnAxisOfTheType(): void
    {
        foreach (self::dictionaries()->types() as $type) {
            $axes = array_map(static fn ($axis): string => mb_strtolower($axis->name), $type->axes);
            $names = [];

            // Up to six are drawn, so a set has at least six.
            self::assertGreaterThanOrEqual(6, \count($type->parameters), $type->name);
            foreach ($type->parameters as $parameter) {
                self::assertNotContains(mb_strtolower($parameter->name), $axes, $type->name.': the parameter "'.$parameter->name.'" is an axis of the variants');
                self::assertNotContains(mb_strtolower($parameter->name), $names, $type->name.': "'.$parameter->name.'" repeats');
                $names[] = mb_strtolower($parameter->name);
                GeneratedText::assertInTheFormOfTheRule($parameter->name, 'parameter of '.$type->name);
                self::assertGreaterThanOrEqual(3, \count($parameter->values), $type->name.': '.$parameter->name);
                self::assertSame($parameter->values, array_values(array_unique($parameter->values)), $type->name.': '.$parameter->name);
                foreach ($parameter->values as $value) {
                    GeneratedText::assertInTheFormOfTheRule($value, 'value of '.$parameter->name.' of '.$type->name);
                    // The rule of the catalog keeps the pair as it is.
                    $built = ProductParameter::of($parameter->name, $value);
                    self::assertSame([$parameter->name, $value], [$built->name(), $built->value()], $type->name);
                }
            }
        }
    }

    public function testTheSentencesOfTheDescriptionsAreInTheFormOfTheRuleAndNeedNoAgreementWithTheName(): void
    {
        $dictionaries = self::dictionaries();

        foreach ($dictionaries->departmentSlugs() as $department) {
            $sentences = $dictionaries->sentences($department);
            self::assertGreaterThanOrEqual(8, \count($sentences), $department);
            self::assertSame($sentences, array_values(array_unique($sentences)), $department);
            GeneratedText::assertAllInTheFormOfTheRule($sentences, 'sentence of '.$department);
            foreach ($sentences as $sentence) {
                self::assertStringEndsWith('.', $sentence, $department);
                // No word that points back at the product (it may be singular or plural, of any gender).
                self::assertDoesNotMatchRegularExpression('/(?<![\p{L}])(ten|ta|to|te|ci|tym|tego|tej|tych|jego|jej|ich|go|ją|je)(?![\p{L}])/iu', $sentence, $department.': '.$sentence);
            }
        }
    }

    /**
     * A description is two paragraphs (three sentences, then the features) or three (two sentences, the features, two
     * sentences), the paragraphs joined by an empty line: the sentences come from the pool of the department, the
     * features paragraph is "Cechy: " and all features of the type. Whatever the draw, the length is 300 to 600.
     */
    public function testEveryTypeFitsTheDescriptionBudgetWhateverSentencesAreDrawn(): void
    {
        $dictionaries = self::dictionaries();

        foreach ($dictionaries->types() as $type) {
            $lengths = array_map(static fn (string $sentence): int => mb_strlen($sentence), $dictionaries->sentences($type->department()));
            $features = $type->features;
            $last = array_pop($features);
            $featuresParagraph = mb_strlen('Cechy: '.([] === $features ? '' : implode(', ', $features).' i ').$last.'.');

            // Sentences of a paragraph are joined by a space, the paragraphs by two line feeds.
            $two = [3 * min($lengths) + 2 + 2 + $featuresParagraph, 3 * max($lengths) + 2 + 2 + $featuresParagraph];
            $three = [4 * min($lengths) + 2 + 4 + $featuresParagraph, 4 * max($lengths) + 2 + 4 + $featuresParagraph];

            self::assertGreaterThanOrEqual(300, $two[0], $type->name.': two paragraphs, the shortest');
            self::assertLessThanOrEqual(600, $two[1], $type->name.': two paragraphs, the longest');
            self::assertGreaterThanOrEqual(300, $three[0], $type->name.': three paragraphs, the shortest');
            self::assertLessThanOrEqual(600, $three[1], $type->name.': three paragraphs, the longest');
        }
    }

    public function testNoDictionaryHoldsPlaceholderText(): void
    {
        foreach (['categories', 'product-types', 'parameters', 'brands', 'models', 'descriptions'] as $file) {
            $contents = (string) file_get_contents(self::directory().'/'.$file.'.yaml');
            self::assertDoesNotMatchRegularExpression('/lorem|ipsum|dolor sit|placeholder|TODO/i', $contents, $file);
        }
    }

    public function testEveryPhotoGroupMapsToExistingFilesOfTheFixtures(): void
    {
        $dictionaries = self::dictionaries();

        foreach ($dictionaries->photoGroupNames() as $group) {
            $files = $dictionaries->photoFiles($group);
            foreach ($files as $file) {
                self::assertFileExists(\dirname(__DIR__, 3).'/fixtures/images/'.$file, $group);
            }
        }
        $fixtures = array_map('basename', glob(\dirname(__DIR__, 3).'/fixtures/images/*.jpg') ?: []);
        sort($fixtures);
        $used = $dictionaries->allPhotoFiles();
        sort($used);
        self::assertCount(37, $fixtures);
        self::assertSame($fixtures, $used, 'Every photo of the fixtures belongs to a group of the small profile.');
    }

    public function testAMissingDictionaryIsReportedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('brands.yaml');

        new Dictionaries('/nonexistent-dir')->brands('bieganie');
    }
}
