<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Every staff member views products; only an administrator or marketing creates, edits, publishes and uploads
 * images (decision K3). Decided on the staff roles of the backoffice token, through the security contract of Backoffice.
 *
 * @extends Voter<string, null>
 */
final class ProductVoter extends Voter
{
    public const string VIEW_PRODUCTS = 'VIEW_PRODUCTS';
    public const string MANAGE_PRODUCTS = 'MANAGE_PRODUCTS';

    private const array MANAGING_ROLES = ['admin', 'marketing'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW_PRODUCTS, self::MANAGE_PRODUCTS], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AuthenticatedStaffMember) {
            return false;
        }

        return self::VIEW_PRODUCTS === $attribute || [] !== array_intersect(self::MANAGING_ROLES, $user->staffRoles());
    }
}
