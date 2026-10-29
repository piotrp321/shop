<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The system status of the dashboard (services, outbox, queues, search index) is for administrators only.
 *
 * @extends Voter<string, null>
 */
final class DashboardVoter extends Voter
{
    public const string VIEW_SYSTEM_STATUS = 'VIEW_SYSTEM_STATUS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW_SYSTEM_STATUS === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof StaffUser && \in_array('admin', $user->staffRoles(), true);
    }
}
