<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\BackofficeCustomerReader;
use App\Ordering\Application\Port\CustomerSummary;
use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Application\Query\BackofficeCustomerCursor;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\View\ShippingAddressView;
use App\Shared\Domain\BoundedCount;

/**
 * Customers in memory, given in the order of the sort the test asks for: a page is the rows after the cursor's
 * customer, the fragment is matched in the email and the name; every call is recorded.
 */
final class InMemoryBackofficeCustomerReader implements BackofficeCustomerReader
{
    /** @var list<array{string, ?string, string, ?string, int}> page calls: method, fragment, sort, cursor id, limit */
    public array $pages = [];
    /** @var list<?string> count calls: fragment */
    public array $counts = [];
    /** @var list<array{string, int, int}> address calls: customer, orders, limit */
    public array $addressCalls = [];

    /**
     * @param list<ListedCustomer>                     $customers
     * @param array<string, CustomerSummary>           $summaries
     * @param array<string, list<ShippingAddressView>> $addresses
     */
    public function __construct(
        private readonly array $customers = [],
        private readonly array $summaries = [],
        private readonly array $addresses = [],
        private readonly ?BoundedCount $total = null,
    ) {
    }

    public function page(?string $fragment, BackofficeCustomerSort $sort, ?BackofficeCustomerCursor $after, int $limit): array
    {
        $this->pages[] = ['page', $fragment, $sort->value, $after?->customerId, $limit];
        $rows = $this->matching($fragment);
        if (null !== $after) {
            $ids = array_map(static fn (ListedCustomer $customer): string => $customer->customerId, $rows);
            $position = array_search($after->customerId, $ids, true);
            $rows = false === $position ? [] : \array_slice($rows, $position + 1);
        }

        return \array_slice($rows, 0, $limit);
    }

    public function count(?string $fragment, int $limit = 10_000): BoundedCount
    {
        $this->counts[] = $fragment;

        return $this->total ?? new BoundedCount(\count($this->matching($fragment)), false);
    }

    public function find(string $customerId): ?CustomerSummary
    {
        return $this->summaries[$customerId] ?? null;
    }

    public function addresses(string $customerId, int $orders, int $limit): array
    {
        $this->addressCalls[] = [$customerId, $orders, $limit];

        return $this->addresses[$customerId] ?? [];
    }

    /**
     * @return list<ListedCustomer>
     */
    private function matching(?string $fragment): array
    {
        if (null === $fragment) {
            return $this->customers;
        }

        return array_values(array_filter(
            $this->customers,
            static fn (ListedCustomer $customer): bool => str_contains(mb_strtolower($customer->email), mb_strtolower($fragment)) || str_contains(mb_strtolower($customer->fullName), mb_strtolower($fragment)),
        ));
    }
}
