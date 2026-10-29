<?php

declare(strict_types=1);

namespace App\Tests\Support\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Denies its attribute to everyone; used by TestSecurityController.
 *
 * @extends Voter<string, mixed>
 */
final class TestDenyingVoter extends Voter
{
    public const string ATTRIBUTE = 'TEST_DENIED';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return false;
    }
}
