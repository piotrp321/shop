<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\BackofficeCustomerReader;
use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Domain\Currency;
use App\Shared\Domain\BoundedCount;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListBackofficeCustomersHandler
{
    public function __construct(private BackofficeCustomerReader $customers)
    {
    }

    /**
     * The search is a fragment of the email or the name, sorted by the last order only (no index serves a fragment in
     * another order). One customer more than the page is read: its presence is what tells there is a next page. The
     * first page (no cursor) also carries the bounded total: the number of its rows when there is no next page,
     * otherwise the reader counts, never the table whole.
     *
     * @throws InvalidBackofficeCustomerList   a refused search term, the search with another sort
     * @throws InvalidBackofficeCustomerCursor
     */
    public function __invoke(ListBackofficeCustomers $query): BackofficeCustomerList
    {
        $sort = $query->sort ?? BackofficeCustomerSort::LastOrderDescending;
        $fragment = null === $query->q ? null : BackofficeCustomerSearchTerm::fragment($query->q);
        if (null !== $fragment && BackofficeCustomerSort::LastOrderDescending !== $sort) {
            throw InvalidBackofficeCustomerList::searchWithSort();
        }
        $after = null === $query->after ? null : BackofficeCustomerCursor::decode($query->after, $sort);

        $customers = $this->customers->page($fragment, $sort, $after, $query->perPage + 1);
        $next = null;
        if (\count($customers) > $query->perPage) {
            $customers = \array_slice($customers, 0, $query->perPage);
            $next = BackofficeCustomerCursor::after($customers[$query->perPage - 1], $sort)->encode();
            $total = null === $after ? $this->customers->count($fragment) : null;
        } else {
            $total = null === $after ? new BoundedCount(\count($customers), false) : null;
        }

        return new BackofficeCustomerList(array_map(self::item(...), $customers), $next, $total);
    }

    private static function item(ListedCustomer $customer): BackofficeCustomerListItem
    {
        return new BackofficeCustomerListItem($customer->customerId, $customer->email, $customer->fullName, $customer->orderCount, $customer->paidGross, Currency::PLN->value, $customer->lastOrderAt);
    }
}
