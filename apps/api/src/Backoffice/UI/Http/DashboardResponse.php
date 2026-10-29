<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\Dashboard;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'What the admin dashboard shows.', required: ['catalog', 'system'])]
final readonly class DashboardResponse
{
    public function __construct(
        #[OA\Property(ref: new Model(type: DashboardCatalogResponse::class))]
        public DashboardCatalogResponse $catalog,
        #[OA\Property(
            description: 'Null unless the staff member is an administrator.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: DashboardSystemResponse::class))],
        )]
        public ?DashboardSystemResponse $system,
    ) {
    }

    public static function of(Dashboard $dashboard): self
    {
        return new self(
            DashboardCatalogResponse::of($dashboard->catalog),
            null === $dashboard->system ? null : DashboardSystemResponse::of($dashboard->system),
        );
    }
}
