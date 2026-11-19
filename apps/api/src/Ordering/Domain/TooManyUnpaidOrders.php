<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Too many orders from the address of the client, of the email of a guest or of the account wait for their payment
 * (UnpaidOrderLimit). One message for every limit, so the answer does not tell which of them was reached.
 */
final class TooManyUnpaidOrders extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/too-many-unpaid-orders';

    public function __construct()
    {
        parent::__construct('Nie możemy teraz przyjąć kolejnego zamówienia, bo z tych danych albo z tej sieci czeka już kilka nieopłaconych. Spróbuj ponownie za ok. 35 minut.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
