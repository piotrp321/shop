<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Ordering\Domain\StaffTransition;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The only place of the rule of the roles for orders (P6 of docs/features/order-management/spec.md): an administrator,
 * support and the warehouse see orders (the orders awaiting payment only an administrator and support, who follow up on them); an administrator and the warehouse ship and deliver; an administrator and
 * support cancel, register returns and complete refunds; an administrator and the warehouse also add or correct the
 * tracking number of a shipped order (P18 of docs/features/delivery/spec.md); only an administrator sees the sales (P2 of
 * docs/features/admin-features/spec.md), an administrator and support the customers (P11); marketing has no access. A status change is decided for its
 * transition (the subject, a StaffTransition); without one the voter abstains, which denies. Decided on the staff
 * roles of the backoffice token, through the security contract of Backoffice.
 *
 * @extends Voter<string, StaffTransition|null>
 */
final class OrderVoter extends Voter
{
    public const string VIEW_ORDERS = 'VIEW_ORDERS';
    public const string CHANGE_ORDER_STATUS = 'CHANGE_ORDER_STATUS';
    public const string COMPLETE_REFUND = 'COMPLETE_REFUND';
    public const string CHANGE_TRACKING_NUMBER = 'CHANGE_TRACKING_NUMBER';
    public const string VIEW_PENDING_PAYMENTS = 'VIEW_PENDING_PAYMENTS';
    public const string VIEW_SALES = 'VIEW_SALES';
    public const string VIEW_CUSTOMERS = 'VIEW_CUSTOMERS';

    private const array VIEWERS = ['admin', 'support', 'warehouse'];
    private const array SHIPPERS = ['admin', 'warehouse'];
    private const array CUSTOMER_SERVICE = ['admin', 'support'];
    private const array SALES_VIEWERS = ['admin'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW_ORDERS, self::COMPLETE_REFUND, self::CHANGE_TRACKING_NUMBER, self::VIEW_PENDING_PAYMENTS, self::VIEW_SALES, self::VIEW_CUSTOMERS => null === $subject,
            self::CHANGE_ORDER_STATUS => $subject instanceof StaffTransition,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AuthenticatedStaffMember) {
            return false;
        }

        $roles = match ($attribute) {
            self::VIEW_ORDERS => self::VIEWERS,
            self::CHANGE_TRACKING_NUMBER => self::SHIPPERS,
            self::COMPLETE_REFUND, self::VIEW_PENDING_PAYMENTS, self::VIEW_CUSTOMERS => self::CUSTOMER_SERVICE,
            self::VIEW_SALES => self::SALES_VIEWERS,
            self::CHANGE_ORDER_STATUS => match ($subject) {
                StaffTransition::Ship, StaffTransition::Deliver => self::SHIPPERS,
                StaffTransition::Cancel, StaffTransition::Return => self::CUSTOMER_SERVICE,
                default => [],
            },
            default => [],
        };

        return [] !== array_intersect($roles, $user->staffRoles());
    }
}
