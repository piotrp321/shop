<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\CustomerSummaries;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\OrderId;

/**
 * The customer summaries in memory: every addition is kept, in order.
 */
final class InMemoryCustomerSummaries implements CustomerSummaries
{
    /** @var list<array{customerId: string, orderId: string, email: string, fullName: string, phone: string, placedAt: \DateTimeImmutable}> */
    public array $orders = [];
    /** @var list<array{customerId: string, orderId: string, gross: int}> */
    public array $payments = [];

    public function addOrder(string $customerId, OrderId $orderId, Contact $contact, \DateTimeImmutable $placedAt): void
    {
        $this->orders[] = ['customerId' => $customerId, 'orderId' => $orderId->toString(), 'email' => $contact->email()->toString(), 'fullName' => $contact->fullName(), 'phone' => $contact->phone(), 'placedAt' => $placedAt];
    }

    public function addPayment(string $customerId, OrderId $orderId, int $gross): void
    {
        $this->payments[] = ['customerId' => $customerId, 'orderId' => $orderId->toString(), 'gross' => $gross];
    }
}
