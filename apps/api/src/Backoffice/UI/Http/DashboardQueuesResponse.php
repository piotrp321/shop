<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\QueuesSection;
use App\Shared\UI\Http\BoundedCountResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The work waiting for the staff member. Every queue is counted up to 1000 (`capped`: more than that) by the module that owns it, and is null when the staff member has no access to the queue: the roles are listed with each field.',
    required: ['paidToShip', 'awaitingPayment', 'pendingRefunds', 'overdueRefunds', 'outOfStockVariants', 'lowStockVariants', 'productsWithoutImages'],
)]
final readonly class DashboardQueuesResponse
{
    public function __construct(
        #[OA\Property(description: 'Paid orders waiting for shipping (`/orders?status=paid`). Administrators, support, warehouse.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $paidToShip,
        #[OA\Property(description: 'Orders not paid yet (`/orders?status=new`). Administrators and support.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $awaitingPayment,
        #[OA\Property(description: 'Refunds to do (`/orders?refund=pending`). Administrators and support.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $pendingRefunds,
        #[OA\Property(description: 'Refunds past their due date (`/orders?refund=overdue`). Administrators and support.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $overdueRefunds,
        #[OA\Property(description: 'Variants with nothing available (`/inventory?level=out_of_stock`). Administrators and warehouse.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $outOfStockVariants,
        #[OA\Property(description: 'Variants with 1 to 5 available (`/inventory?level=low_stock`). Administrators and warehouse.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $lowStockVariants,
        #[OA\Property(description: 'Products with no image (`/products?images=none`). Administrators and marketing.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $productsWithoutImages,
    ) {
    }

    public static function of(QueuesSection $queues): self
    {
        return new self(
            BoundedCountResponse::ofNullable($queues->paidToShip),
            BoundedCountResponse::ofNullable($queues->awaitingPayment),
            BoundedCountResponse::ofNullable($queues->pendingRefunds),
            BoundedCountResponse::ofNullable($queues->overdueRefunds),
            BoundedCountResponse::ofNullable($queues->outOfStockVariants),
            BoundedCountResponse::ofNullable($queues->lowStockVariants),
            BoundedCountResponse::ofNullable($queues->productsWithoutImages),
        );
    }
}
