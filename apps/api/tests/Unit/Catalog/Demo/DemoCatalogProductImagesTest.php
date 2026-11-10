<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoProduct;
use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A product (brand + model of a template) can have images of its own; every other product keeps the pool rule.
 */
final class DemoCatalogProductImagesTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/demo/catalog.yaml';
    private const string IMAGES = '/images';

    private const array OWN_IMAGES = [
        ['file' => 'pair.jpg', 'alt' => 'Cała para nart'],
        ['file' => 'detail.jpg', 'alt' => 'Detal nart'],
        ['file' => 'use.jpg', 'alt' => 'Narty w użyciu'],
    ];

    public function testProductWithOwnImagesTakesAllOfThemInFileOrder(): void
    {
        // Norvane Dystans 400 has the expansion index 4: without own images it would take pool[0].
        $catalog = $this->expand([['brand' => 'Norvane', 'model' => 'Dystans 400', 'images' => self::OWN_IMAGES]]);

        self::assertSame(self::OWN_IMAGES, $this->product($catalog, 'buty-norvane-dystans-400')->images);
    }

    public function testEveryOtherProductKeepsItsPoolImageAndTheImagelessRule(): void
    {
        $baseline = $this->expand([]);
        $catalog = $this->expand([['brand' => 'Norvane', 'model' => 'Dystans 400', 'images' => self::OWN_IMAGES]]);

        self::assertCount(\count($baseline->products), $catalog->products);
        foreach ($catalog->products as $product) {
            if ('buty-norvane-dystans-400' === $product->slug) {
                continue;
            }
            self::assertEquals($this->product($baseline, $product->slug), $product, $product->slug);
        }
        $imageless = array_keys(array_filter($catalog->products, static fn (DemoProduct $p): bool => [] === $p->images));
        self::assertSame([2, 5, 8], $imageless);
    }

    public function testProductWithOwnImagesHasThemEvenWhereTheImagelessRuleWouldRemoveThePhotos(): void
    {
        // Tarvik Tempo 600 has the expansion index 2, an imageless product without own images.
        $catalog = $this->expand([['brand' => 'Tarvik', 'model' => 'Tempo 600', 'images' => self::OWN_IMAGES]]);

        self::assertSame(self::OWN_IMAGES, $this->product($catalog, 'buty-tarvik-tempo-600')->images);
        self::assertSame([5, 8], $this->imagelessIndexes($catalog));
    }

    public function testOwnPhotosAreAddedToThePhotoPathsInTheImagesDirectory(): void
    {
        $catalog = $this->expand([['brand' => 'Norvane', 'model' => 'Dystans 400', 'images' => self::OWN_IMAGES]]);

        self::assertSame('/images/pair.jpg', $catalog->photoPaths['pair.jpg']);
        self::assertSame('/images/use.jpg', $catalog->photoPaths['use.jpg']);
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function invalidProductImages(): iterable
    {
        $images = [['file' => 'a.jpg', 'alt' => 'A']];

        yield 'brand not in the template' => [[['brand' => 'Stavro', 'model' => 'Dystans 400', 'images' => $images]], 'brand "Stavro"'];
        yield 'model not in the template' => [[['brand' => 'Tarvik', 'model' => 'Nie ma', 'images' => $images]], 'model "Nie ma"'];
        yield 'brand of another template' => [[['brand' => 'Dravik', 'model' => 'Asfalt 200', 'images' => $images]], 'brand "Dravik"'];
        yield 'empty image list' => [[['brand' => 'Tarvik', 'model' => 'Asfalt 200', 'images' => []]], '"images" of'];
        yield 'missing image list' => [[['brand' => 'Tarvik', 'model' => 'Asfalt 200']], '"images" of'];
        yield 'image without file' => [[['brand' => 'Tarvik', 'model' => 'Asfalt 200', 'images' => [['alt' => 'A']]]], '"file" of'];
        yield 'image without alt' => [[['brand' => 'Tarvik', 'model' => 'Asfalt 200', 'images' => [['file' => 'a.jpg']]]], '"alt" of'];
        yield 'missing brand' => [[['model' => 'Asfalt 200', 'images' => $images]], '"brand" of'];
        yield 'missing model' => [[['brand' => 'Tarvik', 'images' => $images]], '"model" of'];
        yield 'entry not a map' => [['Tarvik Asfalt 200'], 'must be a map'];
        yield 'product listed twice' => [
            [
                ['brand' => 'Tarvik', 'model' => 'Asfalt 200', 'images' => $images],
                ['brand' => 'Tarvik', 'model' => 'Asfalt 200', 'images' => $images],
            ],
            'more than once',
        ];
    }

    /**
     * @param list<mixed> $productImages
     */
    #[DataProvider('invalidProductImages')]
    public function testInvalidProductImagesAreRejectedWithAMessage(array $productImages, string $expectedInMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedInMessage);

        $this->expand($productImages);
    }

    /**
     * @param list<mixed> $productImages "productImages" of the first template, none when empty
     */
    private function expand(array $productImages): DemoCatalog
    {
        $data = Yaml::parseFile(self::FIXTURE);
        self::assertIsArray($data);
        $templates = $data['templates'] ?? null;
        self::assertIsArray($templates);
        $first = $templates[0] ?? null;
        self::assertIsArray($first);
        if ([] !== $productImages) {
            $first['productImages'] = $productImages;
        }
        $templates[0] = $first;
        $data['templates'] = $templates;

        return new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
    }

    private function product(DemoCatalog $catalog, string $slug): DemoProduct
    {
        foreach ($catalog->products as $product) {
            if ($product->slug === $slug) {
                return $product;
            }
        }

        self::fail(\sprintf('No product "%s".', $slug));
    }

    /**
     * @return list<int>
     */
    private function imagelessIndexes(DemoCatalog $catalog): array
    {
        return array_keys(array_filter($catalog->products, static fn (DemoProduct $p): bool => [] === $p->images));
    }
}
