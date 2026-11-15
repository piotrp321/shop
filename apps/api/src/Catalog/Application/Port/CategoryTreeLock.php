<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * One lock for all changes of the category tree, held until the end of the transaction of the change: the changes
 * run one after another, so two moves cannot form a cycle and the depth, the slugs and the emptiness are checked on
 * what the others committed. Changes of the tree are rare panel operations.
 *
 * Must be called inside the transaction of the change (command.bus), before the categories are read.
 */
interface CategoryTreeLock
{
    public function exclusive(): void;
}
