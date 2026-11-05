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
        yield 'over-long short description' => [['templates', 0, 'models', 0, 'description'], str_repeat('x', 501), 'ShortDescription'];
        yield 'empty photo alt' => [['templates', 0, 'images', 0, 'alt'], ' ', 'alt'];
        yield 'main category repeated as additional' => [['templates', 0, 'additionalCategories'], ['bieganie/buty/szosowe'], 'once'];
        yield 'over-long axis name' => [['templates', 0, 'variantAxes'], ['Kolor', str_repeat('R', 31)], 'product "buty-tarvik-asfalt-200"'];
        yield 'repeated axis name' => [['templates', 0, 'variantAxes'], ['Kolor', 'kolor'], 'product "buty-tarvik-asfalt-200"'];
        yield 'over-long axis value' => [['templates', 1, 'axisValues', 0], ['żółty', str_repeat('z', 41)], 'product "plecak-tarvik-zab-20"'];
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
