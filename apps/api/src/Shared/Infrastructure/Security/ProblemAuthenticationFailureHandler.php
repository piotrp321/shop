<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Answers a rejected access token (bad signature, issuer, audience, expired, malformed) exactly like a missing one,
 * so the response never tells which check failed.
 */
final readonly class ProblemAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private ProblemAuthenticationEntryPoint $entryPoint)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->entryPoint->start($request, $exception);
    }
}
