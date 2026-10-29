<?php

declare(strict_types=1);

namespace App\Tests\Support\Security;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Security exceptions thrown from controllers, outside any firewall (/_test) and inside the shop firewall
 * (/api/v1/_test). Test environment only (see config/routes.yaml).
 */
#[AsController]
final class TestSecurityController
{
    #[Route('/_test/security/authentication', methods: ['GET'])]
    #[Route('/api/v1/_test/security/authentication', methods: ['GET'])]
    public function authentication(): Response
    {
        throw new AuthenticationException('secret detail');
    }

    #[Route('/_test/security/access-denied', methods: ['GET'])]
    #[Route('/api/v1/_test/security/access-denied', methods: ['GET'])]
    public function accessDenied(): Response
    {
        throw new AccessDeniedException('secret detail');
    }

    #[Route('/_test/security/voter', methods: ['GET'])]
    #[Route('/api/v1/_test/security/voter', methods: ['GET'])]
    #[IsGranted(TestDenyingVoter::ATTRIBUTE)]
    public function voter(): Response
    {
        return new Response('reached');
    }

    #[Route('/_test/security/wrapped-authentication', methods: ['GET'])]
    #[Route('/api/v1/_test/security/wrapped-authentication', methods: ['GET'])]
    public function wrappedAuthentication(): Response
    {
        throw new \RuntimeException('secret detail', 0, new AuthenticationException('secret detail'));
    }

    #[Route('/_test/security/wrapped-access-denied', methods: ['GET'])]
    #[Route('/api/v1/_test/security/wrapped-access-denied', methods: ['GET'])]
    public function wrappedAccessDenied(): Response
    {
        throw new \RuntimeException('secret detail', 0, new AccessDeniedException('secret detail'));
    }

    #[Route('/_test/security/handler-authentication', methods: ['GET'])]
    public function handlerAuthentication(): Response
    {
        throw new HandlerFailedException(new Envelope(new \stdClass()), [new AuthenticationException('secret detail')]);
    }
}
