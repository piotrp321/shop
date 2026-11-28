<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\VariantValues;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Random\Prng;

/**
 * Generates the products of the tail of the catalog (spec: "Realizm", "Katalog"). A product has a global 0-based
 * index; a part is a contiguous range of `partSize` indexes with its own stream of random numbers (`catalog`, part), so
 * the rows of a part never depend on the other parts or on the number of processes.
 *
 * Everything that is random is drawn in a fixed order from the stream of the part; everything else is a function of the
 * index: the ids (UUID v5 `product:<i>`, `variant:<i>:<k>`), the slug (the Catalog slug of the name and `-<i + 1>`, so
 * it is unique by construction), the SKU and the EAN of a variant (VariantCodes) and whether the product has photos
 * (every 50th has none). Times are counted from the anchor: the products are published over the 1140 days that end 16
 * days before it, in the order of their indexes.
 *
 * The brand, the long description and the parameters (docs/features/product-data/spec.md, "Dane demo") come from a
 * stream of their own (`catalog-content`, the same part): they never move a draw of the stream of the part, so the
 * ids, slugs, names, short descriptions, variants and prices of a profile are the ones the generator gave before they
 * existed. The brand is the one the name already carries.
 */
final class ProductGenerator
{
    public const string STREAM = 'catalog';
    public const string CONTENT_STREAM = 'catalog-content';
    public const int IMAGELESS_EVERY = 50;
    public const float DRAFT_SHARE = 0.02;
    public const float ADDITIONAL_CATEGORY_SHARE = 0.2;
    /** Variants of a product that has more than one: the weights of 2 to 8. */
    private const array VARIANT_COUNT_WEIGHTS = [12, 18, 20, 17, 14, 11, 8];
    private const int FIRST_COUNT = 2;
    private const int PUBLICATION_START_DAYS = 1156;
    private const int PUBLICATION_SPAN_DAYS = 1140;

    /** @var list<ProductType> */
    private array $types;
    /** @var non-empty-list<int> */
    private array $typeWeights;
    /** @var array<string, list<string>> the slug paths of the leaves by department */
    private array $leavesByDepartment = [];

    public function __construct(
        private readonly Dictionaries $dictionaries,
        private readonly string $namespace,
        private readonly \DateTimeImmutable $anchor,
        private readonly int $total,
        private readonly int $partSize,
    ) {
        $this->types = $dictionaries->types();
        $weights = array_map(static fn (ProductType $type): int => $type->weight, $this->types);
        $this->typeWeights = [] === $weights ? throw new \InvalidArgumentException('The dictionary has no product types.') : $weights;
        foreach ($dictionaries->leafPaths() as $path) {
            $this->leavesByDepartment[explode('/', $path)[0]][] = $path;
        }
    }

    /**
     * @return \Generator<int, ProductSpec> the products of the part in the order of their indexes; none after the last
     */
    public function part(int $part): \Generator
    {
        $first = $part * $this->partSize;
        $last = min($first + $this->partSize, $this->total);
        $prng = Prng::forPart($this->namespace, self::STREAM, $part);
        $content = Prng::forPart($this->namespace, self::CONTENT_STREAM, $part);
        for ($index = $first; $index < $last; ++$index) {
            yield $this->product($index, $prng, $content);
        }
    }

    /**
     * How many leading products (indexes below the returned one) are certainly published, whether or not they are
     * drafts, by the moment: a lower bound that ignores the jitter of at most a day that the publication time of each
     * product gets, so a product at or above it may still be published by the moment.
     */
    public function publishedBy(\DateTimeImmutable $moment): int
    {
        $seconds = $moment->getTimestamp() - $this->anchor->modify(\sprintf('-%d days', self::PUBLICATION_START_DAYS))->getTimestamp() - 86_399;
        if ($seconds <= 0) {
            return 0;
        }

        return min($this->total, intdiv($seconds * $this->total, self::PUBLICATION_SPAN_DAYS * 86_400));
    }

    private function product(int $index, Prng $prng, Prng $content): ProductSpec
    {
        $type = $this->types[$prng->weighted($this->typeWeights)];
        $department = $type->department();
        $brand = $prng->pick($this->dictionaries->brands($department));
        $name = $type->name.' '.$brand.' '.$this->model($prng);
        $draft = $prng->chance(self::DRAFT_SHARE);

        [$axes, $variants] = $this->variants($index, $type, $prng);

        $additional = [];
        if ($prng->chance(self::ADDITIONAL_CATEGORY_SHARE)) {
            $others = array_values(array_filter($this->leavesByDepartment[$department], static fn (string $path): bool => $path !== $type->leaf));
            if ([] !== $others) {
                $additional[] = CategoryTree::leafId($this->namespace, $prng->pick($others));
            }
        }

        $shortDescription = $this->shortDescription($name, $type, $department, $prng);

        $jitter = $prng->int(0, 86_399);
        if ($draft) {
            $firstPublishedAt = null;
            $updatedAt = $this->anchor->modify(\sprintf('-%d days', $prng->int(1, 365)))->modify(\sprintf('+%d seconds', $jitter));
        } else {
            $seconds = intdiv($index * self::PUBLICATION_SPAN_DAYS * 86_400, max(1, $this->total));
            $firstPublishedAt = $this->anchor->modify(\sprintf('-%d days', self::PUBLICATION_START_DAYS))->modify(\sprintf('+%d seconds', $seconds + $jitter));
            $updatedAt = $firstPublishedAt;
        }

        return new ProductSpec(
            $index,
            DemoUuid::v5($this->namespace, 'product:'.$index),
            $name,
            Slug::slugify($name).'-'.($index + 1),
            $shortDescription,
            $brand,
            $this->description($type, $department, $content),
            $this->parameters($type, $content),
            CategoryTree::leafId($this->namespace, $type->leaf),
            $additional,
            $type->taxClass->value,
            $draft ? 'draft' : 'published',
            $firstPublishedAt,
            $updatedAt,
            $axes,
            $variants,
            $type->key,
            $type->photoGroup,
            0 === ($index + 1) % self::IMAGELESS_EVERY,
        );
    }

    private function model(Prng $prng): string
    {
        $suffixes = $this->dictionaries->modelSuffixes();
        $suffix = $suffixes[$prng->weighted(array_map(static fn (array $suffix): int => $suffix['weight'], $suffixes))]['text'];

        return $prng->pick($this->dictionaries->modelWords()).' '.$prng->pick($this->dictionaries->modelNumbers()).$suffix;
    }

    /**
     * @return array{list<string>, non-empty-list<VariantSpec>}
     */
    private function variants(int $index, ProductType $type, Prng $prng): array
    {
        $axes = array_map(static fn (TypeAxis $axis): string => $axis->name, $type->axes);
        $combinations = $this->combinations($type);

        $single = [] === $axes || $type->combinationCount() < self::FIRST_COUNT || $prng->chance($type->single);
        if ($single) {
            $chosen = [] === $axes ? [0] : [$prng->int(0, \count($combinations) - 1)];
        } else {
            $count = self::FIRST_COUNT + $prng->weighted(self::VARIANT_COUNT_WEIGHTS);
            $chosen = \array_slice($prng->shuffle(range(0, \count($combinations) - 1)), 0, min($count, \count($combinations)));
            sort($chosen);
        }

        $variants = [];
        foreach ($chosen as $position => $combination) {
            $values = $combinations[$combination];
            $variants[] = new VariantSpec(
                DemoUuid::v5($this->namespace, 'variant:'.$index.':'.$position),
                $position,
                $values,
                json_encode(VariantValues::fromList($values)->comparisonKey(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                VariantCodes::sku($index, $position),
                VariantCodes::ean($index, $position),
            );
        }

        if ([] === $variants) {
            throw new \LogicException('A product needs at least one variant.');
        }

        return [$axes, $variants];
    }

    /**
     * The product of the value lists of the axes, the last axis varying fastest; one empty combination without axes.
     *
     * @return non-empty-list<list<string>>
     */
    private function combinations(ProductType $type): array
    {
        $combinations = [[]];
        foreach ($type->axes as $axis) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($axis->values as $value) {
                    $next[] = [...$combination, $value];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    private function shortDescription(string $name, ProductType $type, string $department, Prng $prng): string
    {
        $features = $prng->shuffle($type->features);
        $features = \array_slice($features, 0, $prng->chance(0.5) ? 3 : 2);
        $last = array_pop($features);
        $list = [] === $features ? $last : implode(', ', $features).' i '.$last;

        return $name.': '.$list.'. '.$prng->pick($this->dictionaries->closings($department));
    }

    /**
     * The long description: two paragraphs (three sentences of the department, then the features of the type) or three
     * (two sentences, the features, two sentences), joined by an empty line. Every sentence of a department is 80 to 115
     * characters and a type has three features, so the text is 300 to 600 characters whatever is drawn (DictionariesTest).
     */
    private function description(ProductType $type, string $department, Prng $content): string
    {
        $sentences = $content->shuffle($this->dictionaries->sentences($department));
        $features = $content->shuffle($type->features);
        $last = array_pop($features);
        $featureParagraph = 'Cechy: '.([] === $features ? '' : implode(', ', $features).' i ').$last.'.';

        if ($content->chance(0.5)) {
            return implode("\n\n", [implode(' ', \array_slice($sentences, 0, 3)), $featureParagraph]);
        }

        return implode("\n\n", [implode(' ', \array_slice($sentences, 0, 2)), $featureParagraph, implode(' ', \array_slice($sentences, 2, 2))]);
    }

    /**
     * Three to six parameters of the set of the type, in the order of the set, each with one of its values.
     *
     * @return list<array{name: string, value: string}>
     */
    private function parameters(ProductType $type, Prng $content): array
    {
        $count = $content->int(3, min(6, \count($type->parameters)));
        $chosen = \array_slice($content->shuffle(range(0, \count($type->parameters) - 1)), 0, $count);
        sort($chosen);

        $parameters = [];
        foreach ($chosen as $position) {
            $parameter = $type->parameters[$position];
            $parameters[] = ['name' => $parameter->name, 'value' => $content->pick($parameter->values)];
        }

        return $parameters;
    }
}
