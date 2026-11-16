<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class GetDashboard
{
    /**
     * @param bool        $includeSystem whether the system status is wanted; the caller decides by the permission of the user
     * @param QueueAccess $queues        the queues the user may see, decided by the voters of their modules
     * @param bool        $includeSales  whether the sales are wanted, decided by the voter of Ordering (VIEW_SALES)
     */
    public function __construct(public bool $includeSystem, public QueueAccess $queues, public bool $includeSales = false)
    {
    }
}
