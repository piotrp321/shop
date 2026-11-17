<?php

declare(strict_types=1);

namespace App\Catalog\Application\Event;

/**
 * What changed in the tree: a new category, a new name or slug, a new parent, a new place among the same siblings, a
 * deleted category. A new name and a new parent change the category names of the products of the subtree.
 */
enum CategoryTreeChangeKind: string
{
    case Created = 'created';
    case Revised = 'revised';
    case Moved = 'moved';
    case Reordered = 'reordered';
    case Deleted = 'deleted';
}
