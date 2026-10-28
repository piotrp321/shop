<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

final class Category
{
    private function __construct(
        private readonly CategoryId $id,
        private readonly CategoryName $name,
        private readonly ?CategoryId $parentId,
        private readonly CategoryPath $path,
    ) {
    }

    public static function createRoot(CategoryId $id, CategoryName $name): self
    {
        return new self($id, $name, null, CategoryPath::root($id));
    }

    public static function createChild(CategoryId $id, CategoryName $name, self $parent): self
    {
        return new self($id, $name, $parent->id, $parent->path->child($id));
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function name(): CategoryName
    {
        return $this->name;
    }

    public function parentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function path(): CategoryPath
    {
        return $this->path;
    }
}
