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
 * support and the warehouse see orders; an administrator and the warehouse ship and deliver; an administrator and
 * support cancel, register returns and complete refunds; marketing has no access. A status change is decided for its
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

    private const array VIEWERS = ['admin', 'support', 'warehouse'];
    private const array SHIPPERS = ['admin', 'warehouse'];
    private const array CUSTOMER_SERVICE = ['admin', 'support'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW_ORDERS, self::COMPLETE_REFUND => null === $subject,
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
            self::COMPLETE_REFUND => self::CUSTOMER_SERVICE,
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
