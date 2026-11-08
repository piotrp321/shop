<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

/**
 * The attributes of OrderVoter (Ordering Infrastructure), which holds the rule of the roles: the controllers of the
 * panel only know their names and ask isGranted().
 */
final class BackofficeOrderAttributes
{
    public const string VIEW_ORDERS = 'VIEW_ORDERS';
    /** Asked with the StaffTransition as the subject. */
    public const string CHANGE_ORDER_STATUS = 'CHANGE_ORDER_STATUS';
    public const string COMPLETE_REFUND = 'COMPLETE_REFUND';
}
