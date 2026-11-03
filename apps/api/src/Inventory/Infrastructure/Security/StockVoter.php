<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only an administrator or the warehouse receives and corrects stock; every staff member reads it. Decided on the
 * staff roles of the backoffice token, through the security contract of Backoffice.
 *
 * @extends Voter<string, null>
 */
final class StockVoter extends Voter
{
    public const string MANAGE_STOCK = 'MANAGE_STOCK';

    private const array ROLES = ['admin', 'warehouse'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE_STOCK === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof AuthenticatedStaffMember && [] !== array_intersect(self::ROLES, $user->staffRoles());
    }
}
