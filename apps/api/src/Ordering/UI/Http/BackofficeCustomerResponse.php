<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\View\BackofficeCustomerView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The card of a customer.', required: ['customerId', 'contact', 'summary', 'addresses'])]
final readonly class BackofficeCustomerResponse
{
    /**
     * @param list<BackofficeCustomerAddressResponse> $addresses
     */
    public function __construct(
        #[OA\Property(description: 'The id of the account of the store (`sub` of its token).', example: '5f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b')]
        public string $customerId,
        #[OA\Property(ref: new Model(type: BackofficeCustomerContactResponse::class))]
        public BackofficeCustomerContactResponse $contact,
        #[OA\Property(ref: new Model(type: BackofficeCustomerSummaryResponse::class))]
        public BackofficeCustomerSummaryResponse $summary,
        #[OA\Property(description: 'Up to 10 distinct shipping addresses of the last 50 orders, the one used last first.', type: 'array', items: new OA\Items(ref: new Model(type: BackofficeCustomerAddressResponse::class)), maxItems: 10)]
        public array $addresses,
    ) {
    }

    public static function of(BackofficeCustomerView $customer): self
    {
        return new self(
            $customer->customerId,
            new BackofficeCustomerContactResponse($customer->email, $customer->fullName, $customer->phone),
            new BackofficeCustomerSummaryResponse(
                $customer->orderCount,
                $customer->paidCount,
                $customer->paidGross,
                $customer->currency,
                BackofficeCustomerListItemResponse::utc($customer->firstOrderAt),
                BackofficeCustomerListItemResponse::utc($customer->lastOrderAt),
            ),
            array_map(BackofficeCustomerAddressResponse::of(...), $customer->addresses),
        );
    }
}
