<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryPath;
use App\DemoData\Identity\DemoUuid;

/**
 * The rows of catalog_category of the generator: the departments of the dictionary (a department that exists already,
 * the one of the seed catalog, keeps its id), their groups and leaves. The id of a category is UUID v5 of
 * `category:<slug path>`, the path column is the one of the Catalog domain (CategoryPath), the position stays 0 like in
 * the seed.
 */
final class CategoryTree
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $existingDepartments the ids of the departments found in the table, by slug
     *
     * @return list<CategorySpec> parents before children
     */
    public static function build(Dictionaries $dictionaries, string $namespace, array $existingDepartments = []): array
    {
        $specs = [];
        foreach ($dictionaries->departments() as $department) {
            $existing = isset($existingDepartments[$department['slug']]);
            $rootId = $existingDepartments[$department['slug']] ?? self::leafId($namespace, $department['slug']);
            $rootPath = CategoryPath::root(CategoryId::fromString($rootId));
            $specs[] = new CategorySpec($rootId, $department['name'], $department['slug'], null, $department['slug'], $rootPath->toString(), 0, $existing);

            foreach ($department['groups'] as $group) {
                $groupSlugPath = $department['slug'].'/'.$group['slug'];
                $groupId = self::leafId($namespace, $groupSlugPath);
                $groupPath = $rootPath->child(CategoryId::fromString($groupId));
                $specs[] = new CategorySpec($groupId, $group['name'], $group['slug'], $rootId, $groupSlugPath, $groupPath->toString(), 0, false);

                foreach ($group['leaves'] as $leaf) {
                    $leafSlugPath = $groupSlugPath.'/'.$leaf['slug'];
                    $leafId = self::leafId($namespace, $leafSlugPath);
                    $specs[] = new CategorySpec($leafId, $leaf['name'], $leaf['slug'], $groupId, $leafSlugPath, $groupPath->child(CategoryId::fromString($leafId))->toString(), 0, false);
                }
            }
        }

        return $specs;
    }

    /**
     * The id of the category with the slug path (`department/group/leaf`), independent of the department's own id.
     */
    public static function leafId(string $namespace, string $slugPath): string
    {
        return DemoUuid::v5($namespace, 'category:'.$slugPath);
    }
}
