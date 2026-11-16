<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only an administrator or marketing changes the category tree (decision P10); every staff member with a staff role
 * reads it. Decided on the staff roles of the backoffice token, through the security contract of Backoffice.
 *
 * @extends Voter<string, null>
 */
final class CategoryVoter extends Voter
{
    public const string MANAGE_CATEGORIES = 'MANAGE_CATEGORIES';

    private const array MANAGING_ROLES = ['admin', 'marketing'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE_CATEGORIES === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof AuthenticatedStaffMember && [] !== array_intersect(self::MANAGING_ROLES, $user->staffRoles());
    }
}
