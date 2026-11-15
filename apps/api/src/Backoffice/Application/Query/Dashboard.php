<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Backoffice\Application\Port\SalesFigures;

final readonly class Dashboard
{
    /**
     * @param ?SalesFigures $sales null unless the staff member may see the sales
     */
    public function __construct(
        public CatalogSection $catalog,
        public QueuesSection $queues,
        public ?SystemSection $system,
        public ?SalesFigures $sales = null,
    ) {
    }
}
