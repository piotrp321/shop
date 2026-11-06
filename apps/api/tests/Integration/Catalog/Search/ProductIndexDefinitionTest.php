<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\TestCase;

/**
 * The analysis and the mapping of a product index on the real Elasticsearch (with the analysis-stempel plugin).
 */
final class ProductIndexDefinitionTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private const string ID = '0198a1f0-0000-7000-8000-000000000001';

    public function testTextFieldsAreStemmedInPolish(): void
    {
        $this->maintenance()->setup();

        foreach (['name', 'category_names', 'short_description'] as $field) {
            self::assertSame($this->tokens($field, 'kurtki'), $this->tokens($field, 'kurtka'), $field);
            self::assertSame($this->tokens($field, 'Kurtki'), $this->tokens($field, 'kurtkami'), $field);
        }
    }

    public function testFoldedSubfieldsDropPolishCharactersWithoutStemming(): void
    {
        $this->maintenance()->setup();

        foreach (['name.folded', 'category_names.folded', 'short_description.folded'] as $field) {
            self::assertSame(['spiwor', 'zolty'], $this->tokens($field, 'Śpiwór ŻÓŁTY'), $field);
            self::assertSame(['kurtki'], $this->tokens($field, 'kurtki'), $field);
        }
    }

    public function testADocumentWithVariantsIsStoredAndReadBack(): void
    {
        $this->maintenance()->setup();
        $document = self::document();

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index($document));

        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        self::assertIsArray($source);
        self::assertSame(['Odzież', 'Kurtki'], $source['category_names']);
        self::assertSame(19_900, $source['from_gross']);
        self::assertSame([
            ['gross' => 18_900, 'purchasable' => false, 'axes' => ['Kolor', 'Rozmiar'], 'options' => ["Kolor\u{1F}czarne", "Rozmiar\u{1F}42"]],
            ['gross' => 19_900, 'purchasable' => true, 'axes' => ['Kolor', 'Rozmiar'], 'options' => ["Kolor\u{1F}Białe", "Rozmiar\u{1F}43"]],
            ['purchasable' => false, 'axes' => ['Kolor', 'Rozmiar'], 'options' => ["Kolor\u{1F}zielone", "Rozmiar\u{1F}44"]],
        ], $source['variants']);
        self::assertEquals($document, ProductIndexDefinition::document($source));
    }

    public function testADocumentWithoutAFromPriceHasNoSuchField(): void
    {
        $this->maintenance()->setup();
        $document = self::document(fromGross: null, variants: [new VariantDocument(null, false, [], [])]);

        $this->indexer()->index($document);

        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        self::assertIsArray($source);
        self::assertArrayNotHasKey('from_gross', $source);
        self::assertSame([['purchasable' => false, 'axes' => [], 'options' => []]], $source['variants']);
        self::assertEquals($document, ProductIndexDefinition::document($source));
    }

    public function testVariantConditionsMatchOneVariantNotTwo(): void
    {
        $this->maintenance()->setup();
        $this->indexer()->index(self::document());
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        // Black exists and a purchasable variant exists, but not a purchasable black one.
        self::assertSame(0, $this->countVariants([
            ['term' => ['variants.options' => "Kolor\u{1F}czarne"]],
            ['term' => ['variants.purchasable' => true]],
        ]));
        self::assertSame(1, $this->countVariants([
            ['term' => ['variants.options' => "Kolor\u{1F}Białe"]],
            ['term' => ['variants.purchasable' => true]],
            ['range' => ['variants.gross' => ['lte' => 19_900]]],
        ]));
        // Options keep the case of Catalog.
        self::assertSame(0, $this->countVariants([['term' => ['variants.options' => "Kolor\u{1F}białe"]]]));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $field, string $text): array
    {
        $response = self::esClient()->indices()->analyze(['index' => $this->alias(), 'body' => ['field' => $field, 'text' => $text]]);
        \assert($response instanceof Elasticsearch);
        $tokens = $response->asArray()['tokens'] ?? null;
        \assert(\is_array($tokens));

        return array_map(static fn (mixed $token): string => \is_array($token) && \is_string($token['token']) ? $token['token'] : '', array_values($tokens));
    }

    /**
     * @param list<array<string, mixed>> $conditions
     */
    private function countVariants(array $conditions): int
    {
        $response = self::esClient()->count(['index' => $this->alias(), 'body' => [
            'query' => ['nested' => ['path' => 'variants', 'query' => ['bool' => ['filter' => $conditions]]]],
        ]]);
        \assert($response instanceof Elasticsearch);
        $count = $response['count'];
        \assert(\is_int($count));

        return $count;
    }

    private function indexer(): ElasticsearchProductIndexer
    {
        return new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
    }

    /**
     * @param list<VariantDocument>|null $variants
     */
    private static function document(?int $fromGross = 19_900, ?array $variants = null): ProductDocument
    {
        $axes = ['Kolor', 'Rozmiar'];

        return new ProductDocument(
            self::ID,
            1_762_400_000_123_456,
            'Kurtka puchowa',
            'kurtka-puchowa',
            'Ciepła kurtka na zimę',
            'c',
            ['c'],
            ['p', 'c'],
            null,
            ['Odzież', 'Kurtki'],
            $variants ?? [
                new VariantDocument(18_900, false, $axes, ["Kolor\u{1F}czarne", "Rozmiar\u{1F}42"]),
                new VariantDocument(19_900, true, $axes, ["Kolor\u{1F}Białe", "Rozmiar\u{1F}43"]),
                new VariantDocument(null, false, $axes, ["Kolor\u{1F}zielone", "Rozmiar\u{1F}44"]),
            ],
            $fromGross,
        );
    }
}
