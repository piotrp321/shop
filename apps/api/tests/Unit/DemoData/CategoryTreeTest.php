<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryPath;
use App\DemoData\Catalog\CategorySpec;
use App\DemoData\Catalog\CategoryTree;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Identity\DemoUuid;
use PHPUnit\Framework\TestCase;

final class CategoryTreeTest extends TestCase
{
    private const string NAMESPACE_ID = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';

    /**
     * @param array<string, string> $existingRoots
     *
     * @return list<CategorySpec>
     */
    private static function build(array $existingRoots = []): array
    {
        return CategoryTree::build(new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data'), self::NAMESPACE_ID, $existingRoots);
    }

    public function testTheTreeHasTheDepartmentsTheGroupsAndTheLeaves(): void
    {
        $dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
        $specs = self::build();

        $roots = array_filter($specs, static fn (CategorySpec $c): bool => null === $c->parentId);
        self::assertCount(7, $roots);
        $leaves = array_filter($specs, static fn (CategorySpec $c): bool => 3 === substr_count($c->slugPath, '/') + 1);
        self::assertCount(\count($dictionaries->leafPaths()), $leaves);
        self::assertSame($dictionaries->leafPaths(), array_values(array_map(static fn (CategorySpec $c): string => $c->slugPath, $leaves)));
        self::assertCount(array_sum(array_map(static fn (string $d): int => 1 + \count($dictionaries->groupSlugs($d)), $dictionaries->departmentSlugs())) + \count($leaves), $specs);
    }

    public function testTheIdsAreVersion5UuidsOfTheSlugPath(): void
    {
        foreach (self::build() as $spec) {
            self::assertSame(DemoUuid::v5(self::NAMESPACE_ID, 'category:'.$spec->slugPath), $spec->id);
        }
        self::assertSame(DemoUuid::v5(self::NAMESPACE_ID, 'category:bieganie/obuwie-startowe/startowe'), CategoryTree::leafId(self::NAMESPACE_ID, 'bieganie/obuwie-startowe/startowe'));
    }

    public function testThePathIsTheOneOfTheCatalogDomainFromTheRootToTheCategory(): void
    {
        $byId = [];
        foreach (self::build() as $spec) {
            $byId[$spec->id] = $spec;
        }

        foreach ($byId as $spec) {
            $path = CategoryPath::root(CategoryId::fromString($spec->parentId ?? $spec->id));
            $ancestors = [];
            for ($parent = $spec; null !== $parent->parentId; $parent = $byId[$parent->parentId]) {
                array_unshift($ancestors, $parent->id);
            }
            $expected = CategoryPath::root(CategoryId::fromString($parent->id));
            foreach ($ancestors as $id) {
                $expected = $expected->child(CategoryId::fromString($id));
            }
            self::assertSame($expected->toString(), $spec->path, $spec->slugPath);
            self::assertSame(null === $spec->parentId, 1 === \count($expected->ancestorIds()));
            unset($path);
        }
    }

    public function testAnExistingDepartmentKeepsItsIdAndIsNotCreatedAgain(): void
    {
        $existing = '11111111-1111-4111-8111-111111111111';
        $specs = self::build(['bieganie' => $existing]);

        $byPath = [];
        foreach ($specs as $spec) {
            $byPath[$spec->slugPath] = $spec;
        }
        self::assertSame($existing, $byPath['bieganie']->id);
        self::assertTrue($byPath['bieganie']->existing);
        self::assertFalse($byPath['rower']->existing);
        self::assertSame($existing, $byPath['bieganie/obuwie-startowe']->parentId);
        self::assertSame('/'.$existing.'/'.$byPath['bieganie/obuwie-startowe']->id.'/', $byPath['bieganie/obuwie-startowe']->path);
        // The leaf id does not depend on the id of the department.
        self::assertSame(CategoryTree::leafId(self::NAMESPACE_ID, 'bieganie/obuwie-startowe/startowe'), $byPath['bieganie/obuwie-startowe/startowe']->id);
    }

    public function testTheSiblingsHaveDifferentSlugsAndEveryParentComesBeforeItsChildren(): void
    {
        $seen = [];
        $siblings = [];
        foreach (self::build() as $spec) {
            if (null !== $spec->parentId) {
                self::assertArrayHasKey($spec->parentId, $seen, $spec->slugPath);
            }
            $seen[$spec->id] = true;
            $key = ($spec->parentId ?? 'root').'|'.$spec->slug;
            self::assertArrayNotHasKey($key, $siblings, $spec->slugPath);
            $siblings[$key] = true;
            self::assertSame(0, $spec->position);
        }
    }
}
