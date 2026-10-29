<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use PHPUnit\Framework\TestCase;

final class ProductDocumentBuilderTest extends TestCase
{
    private const string ROOT = '00000000-0000-4000-8000-000000000001';
    private const string CHILD = '00000000-0000-4000-8000-000000000002';
    private const string LEAF = '00000000-0000-4000-8000-000000000003';
    private const string SIBLING = '00000000-0000-4000-8000-000000000004';
    private const string OTHER_ROOT = '00000000-0000-4000-8000-000000000005';

    public function testAncestorIdsAreTheUnionOfAllPathsWithoutDuplicates(): void
    {
        // Main category ROOT > CHILD > LEAF, additional ROOT > CHILD > SIBLING (same subtree) and OTHER_ROOT.
        $document = new ProductDocumentBuilder()->build($this->snapshot(
            mainCategoryId: self::LEAF,
            additionalCategoryIds: [self::SIBLING, self::OTHER_ROOT],
            categoryPaths: [
                [self::ROOT, self::CHILD, self::LEAF],
                [self::ROOT, self::CHILD, self::SIBLING],
                [self::OTHER_ROOT],
            ],
        ));

        self::assertSame([self::ROOT, self::CHILD, self::LEAF, self::SIBLING, self::OTHER_ROOT], $document->categoryAncestorIds);
    }

    public function testCategoryIdsStartWithTheMainCategory(): void
    {
        $document = new ProductDocumentBuilder()->build($this->snapshot(
            mainCategoryId: self::LEAF,
            additionalCategoryIds: [self::ROOT, self::OTHER_ROOT],
            categoryPaths: [[self::ROOT, self::CHILD, self::LEAF], [self::ROOT], [self::OTHER_ROOT]],
        ));

        self::assertSame(self::LEAF, $document->mainCategoryId);
        self::assertSame([self::LEAF, self::ROOT, self::OTHER_ROOT], $document->categoryIds);
    }

    public function testCopiesTheVersionAndFieldsFromTheSnapshot(): void
    {
        $document = new ProductDocumentBuilder()->build($this->snapshot(
            version: 7,
            mainImage: ['key' => 'originals/abc.jpg', 'alt' => 'Front'],
        ));

        self::assertSame('0198a1f0-0000-7000-8000-000000000001', $document->id);
        self::assertSame(7, $document->version);
        self::assertSame('Żel do mycia', $document->name);
        self::assertSame('zel-do-mycia', $document->slug);
        self::assertSame('Short text', $document->shortDescription);
        self::assertSame(['key' => 'originals/abc.jpg', 'alt' => 'Front'], $document->mainImage);
    }

    public function testMainImageIsNullWithoutImages(): void
    {
        $document = new ProductDocumentBuilder()->build($this->snapshot(mainImage: null));

        self::assertNull($document->mainImage);
    }

    /**
     * @param list<string>                         $additionalCategoryIds
     * @param list<list<string>>                   $categoryPaths
     * @param array{key: string, alt: string}|null $mainImage
     */
    private function snapshot(
        int $version = 1,
        string $mainCategoryId = self::ROOT,
        array $additionalCategoryIds = [],
        array $categoryPaths = [[self::ROOT]],
        ?array $mainImage = null,
    ): ProductSearchSnapshot {
        return new ProductSearchSnapshot(
            '0198a1f0-0000-7000-8000-000000000001',
            $version,
            'Żel do mycia',
            'zel-do-mycia',
            'Short text',
            $mainCategoryId,
            $additionalCategoryIds,
            $categoryPaths,
            $mainImage,
        );
    }
}
