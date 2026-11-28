<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Infrastructure\Demo\YamlDemoCatalogSource;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The source rejects what the domain would reject, before anything is reset or created.
 */
final class YamlDemoCatalogSourceTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/demo/catalog.yaml';
    private const string IMAGES = __DIR__.'/../../../Fixtures/images';

    /** @var list<string> */
    private array $tempFiles = [];

    #[After]
    public function removeTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testValidFixtureLoadsWithPhotoPaths(): void
    {
        $catalog = $this->source([])->load();

        self::assertCount(10, $catalog->products);
        self::assertCount(4, $catalog->products[0]->variants);
        self::assertSame(['sample.jpg', 'sample.png'], array_keys($catalog->photoPaths));
    }

    /** @return iterable<string, array{list<string|int>, mixed, string}> */
    public static function invalidValues(): iterable
    {
        yield 'unknown tax class' => [['templates', 1, 'taxClass'], 'reduced', 'reduced'];
        yield 'over-long product name' => [['templates', 0, 'productType'], str_repeat('Buty ', 50), 'ProductName'];
        yield 'over-long category name' => [['categories', 0, 'name'], str_repeat('K', 101), 'CategoryName'];
        // A category no template refers to, so the slug is checked by the domain and not by the path lookup.
        yield 'category slug not in the slug format' => [['categories', 1, 'children', 1], ['name' => 'Namioty', 'slug' => 'Namioty'], 'category "turystyka/Namioty" is invalid: CategorySlug'];
        yield 'over-long category slug' => [['categories', 1, 'children', 1], ['name' => 'Namioty', 'slug' => str_repeat('n', 61)], 'CategorySlug'];
        yield 'over-long short description' => [['templates', 0, 'models', 0, 'description'], str_repeat('x', 501), 'ShortDescription'];
        yield 'empty photo alt' => [['templates', 0, 'images', 0, 'alt'], ' ', 'alt'];
        yield 'main category repeated as additional' => [['templates', 0, 'additionalCategories'], ['bieganie/buty/szosowe'], 'once'];
        yield 'over-long axis name' => [['templates', 0, 'variantAxes'], ['Kolor', str_repeat('R', 31)], 'product "buty-tarvik-asfalt-200"'];
        yield 'repeated axis name' => [['templates', 0, 'variantAxes'], ['Kolor', 'kolor'], 'product "buty-tarvik-asfalt-200"'];
        yield 'over-long axis value' => [['templates', 1, 'axisValues', 0], ['żółty', str_repeat('z', 41)], 'product "plecak-tarvik-zab-20"'];
        yield 'over-long brand' => [['templates', 0, 'brands'], [str_repeat('B', 61)], 'is invalid: ProductBrand must have at most 60'];
        yield 'over-long description' => [['templates', 0, 'description'], [str_repeat('x', 2600), str_repeat('y', 2600)], 'product "buty-tarvik-asfalt-200" is invalid: ProductDescription must have at most 5000'];
        yield 'forbidden character in a description' => [['templates', 0, 'description'], ["Akapit z\u{2028}separatorem."], 'product "buty-tarvik-asfalt-200" is invalid: ProductDescription must not contain forbidden characters'];
        yield 'over-long full description' => [['templates', 0, 'models', 1, 'fullDescription'], [str_repeat('x', 5000), 'y'], 'product "buty-tarvik-dystans-400" is invalid: ProductDescription must have at most 5000'];
        yield 'repeated parameter name in the type' => [['templates', 0, 'parameters'], [['name' => 'Waga', 'value' => '1 kg'], ['name' => 'waga', 'value' => '2 kg']], 'product "buty-tarvik-asfalt-200" is invalid: ProductParameters must have different names'];
        yield 'repeated parameter name in a model' => [['templates', 0, 'models', 0, 'parameters'], [['name' => 'Drop', 'value' => '8 mm'], ['name' => 'DROP', 'value' => '6 mm']], 'product "buty-tarvik-asfalt-200" is invalid: ProductParameters must have different names'];
        yield 'over-long parameter value' => [['templates', 0, 'parameters'], [['name' => 'Materiał cholewki', 'value' => str_repeat('x', 201)]], 'product "buty-tarvik-asfalt-200" is invalid: ProductParameter value must have from 1 to 200'];
        yield 'parameter of the type named like an axis' => [['templates', 0, 'parameters'], [['name' => 'kolor', 'value' => 'czarne']], 'product "buty-tarvik-asfalt-200" is invalid: The parameter "kolor" is named like a variant axis'];
        yield 'parameter of a model named like an axis with spaces and capitals' => [['templates', 0, 'models', 0, 'parameters'], [['name' => '  ROZMIAR ', 'value' => '42']], 'product "buty-tarvik-asfalt-200" is invalid: The parameter "ROZMIAR" is named like a variant axis'];
        yield 'over-long SKU' => [['templates', 0, 'models', 0, 'name'], 'Bardzo długa nazwa modelu przekraczająca limit', 'is invalid: Sku must have from 1 to 40 characters'];
        yield 'more than a hundred variants' => [['templates', 0, 'axisValues'], [self::numbered(11), self::numbered(10)], 'product "buty-tarvik-asfalt-200"'];
    }

    /** @return list<string> */
    private static function numbered(int $count): array
    {
        return array_map(static fn (int $n): string => 'w'.$n, range(1, $count));
    }

    /**
     * @param list<string|int> $path
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValueIsRejectedWithItsContext(array $path, mixed $value, string $expectedInMessage): void
    {
        $source = $this->source([[$path, $value]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedInMessage);

        $source->load();
    }

    /**
     * @param list<array{list<string|int>, mixed}> $changes
     */
    private function source(array $changes): YamlDemoCatalogSource
    {
        $data = Yaml::parseFile(self::FIXTURE);
        self::assertIsArray($data);
        foreach ($changes as [$path, $value]) {
            $data = self::set($data, $path, $value);
        }
        $file = tempnam(sys_get_temp_dir(), 'demo-catalog-');
        self::assertIsString($file);
        $this->tempFiles[] = $file;
        file_put_contents($file, Yaml::dump($data, 8, 2));

        return new YamlDemoCatalogSource($file, self::IMAGES);
    }

    /**
     * @param array<mixed>     $data
     * @param list<string|int> $path
     *
     * @return array<mixed>
     */
    private static function set(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        \assert(null !== $key);
        $child = $data[$key] ?? null;
        $data[$key] = [] === $path ? $value : self::set(\is_array($child) ? $child : [], $path, $value);

        return $data;
    }
}
