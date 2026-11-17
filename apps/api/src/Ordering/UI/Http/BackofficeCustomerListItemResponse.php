<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\BackofficeCustomerListItem;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A customer in the list: an account of the store with at least one order. Amounts in grosze.', required: ['customerId', 'email', 'fullName', 'orderCount', 'paidGross', 'currency', 'lastOrderAt'])]
final readonly class BackofficeCustomerListItemResponse
{
    public function __construct(
        #[OA\Property(description: 'The id of the account of the store (`sub` of its token).', example: '5f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b')]
        public string $customerId,
        #[OA\Property(description: 'The email of the newest order.', example: 'anna.nowak@example.com')]
        public string $email,
        #[OA\Property(description: 'The name of the newest order.', example: 'Anna Nowak')]
        public string $fullName,
        #[OA\Property(description: 'All orders of the customer.', minimum: 1, example: 3)]
        public int $orderCount,
        #[OA\Property(description: 'The gross total of the paid orders, refunds not taken off.', minimum: 0, example: 24600)]
        public int $paidGross,
        #[OA\Property(example: 'PLN')]
        public string $currency,
        #[OA\Property(description: 'The moment of the newest order, RFC 3339 in UTC.', format: 'date-time', example: '2026-11-05T12:30:00+00:00')]
        public string $lastOrderAt,
    ) {
    }

    public static function of(BackofficeCustomerListItem $item): self
    {
        return new self($item->customerId, $item->email, $item->fullName, $item->orderCount, $item->paidGross, $item->currency, self::utc($item->lastOrderAt));
    }

    public static function utc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
