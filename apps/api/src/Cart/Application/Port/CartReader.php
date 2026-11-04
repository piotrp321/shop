<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;

/**
 * Carts for reading: as stored, without a lock. The reader opens no transaction; a command may read inside its own.
 */
interface CartReader
{
    public function find(CartId $id): ?Cart;

    public function findForCustomer(string $customerId): ?Cart;
}
