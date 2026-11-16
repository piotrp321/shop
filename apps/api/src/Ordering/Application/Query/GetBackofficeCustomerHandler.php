<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\BackofficeCustomerReader;
use App\Ordering\Application\View\BackofficeCustomerView;
use App\Ordering\Domain\ControlCharacters;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\OrderOwner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the summary of the customer and the distinct shipping addresses of its last 50 orders (at most 10, the one
 * used last first), without locks. An id no customer can have (empty, longer than a `sub`, with a control character)
 * is not found without a query.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetBackofficeCustomerHandler
{
    public const int ADDRESS_ORDERS = 50;
    public const int MAX_ADDRESSES = 10;

    public function __construct(private BackofficeCustomerReader $customers)
    {
    }

    /**
     * @throws CustomerNotFound
     */
    public function __invoke(GetBackofficeCustomer $query): BackofficeCustomerView
    {
        $id = $query->customerId;
        $length = mb_strlen($id);
        if ($length < 1 || $length > OrderOwner::MAX_CUSTOMER_ID_LENGTH || ControlCharacters::in($id)) {
            throw new CustomerNotFound();
        }
        $customer = $this->customers->find($id) ?? throw new CustomerNotFound();

        return new BackofficeCustomerView(
            $customer->customerId,
            $customer->email,
            $customer->fullName,
            $customer->phone,
            $customer->orderCount,
            $customer->paidCount,
            $customer->paidGross,
            Currency::PLN->value,
            $customer->firstOrderAt,
            $customer->lastOrderAt,
            $this->customers->addresses($id, self::ADDRESS_ORDERS, self::MAX_ADDRESSES),
        );
    }
}
