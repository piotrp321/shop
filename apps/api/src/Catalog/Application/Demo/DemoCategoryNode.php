<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

/**
 * A category of the demo definition with its children.
 */
final readonly class DemoCategoryNode
{
    /**
     * @param list<DemoCategoryNode> $children
     */
    public function __construct(
        public string $name,
        public string $slug,
        public array $children,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $where): self
    {
        $children = [];
        $rawChildren = $data['children'] ?? [];
        if (!\is_array($rawChildren)) {
            throw new \InvalidArgumentException(\sprintf('"children" of %s must be a list.', $where));
        }
        $name = DemoCatalogDefinition::string($data, 'name', $where);
        $slug = DemoCatalogDefinition::string($data, 'slug', $where);
        foreach ($rawChildren as $child) {
            if (!\is_array($child)) {
                throw new \InvalidArgumentException(\sprintf('A child of %s must be a map.', $where));
            }
            $children[] = self::fromArray($child, $where.'/'.$slug);
        }

        return new self($name, $slug, $children);
    }
}
