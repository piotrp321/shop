<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The closed list of orders of the product list of the panel; the value is the key of the `sort` parameter (a leading
 * `-` is the descending direction). The id always breaks the ties, in the same direction, so the keyset is stable.
 */
enum ProductSort: string
{
    case Name = 'name';
    case NameDescending = '-name';
    case UpdatedAtDescending = '-updatedAt';
    case UpdatedAtAscending = 'updatedAt';

    public function isDescending(): bool
    {
        return str_starts_with($this->value, '-');
    }

    public function byUpdatedAt(): bool
    {
        return self::UpdatedAtDescending === $this || self::UpdatedAtAscending === $this;
    }
}
