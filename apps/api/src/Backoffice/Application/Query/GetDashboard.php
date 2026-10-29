<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class GetDashboard
{
    /**
     * @param bool $includeSystem whether the system status is wanted; the caller decides by the permission of the user
     */
    public function __construct(public bool $includeSystem)
    {
    }
}
