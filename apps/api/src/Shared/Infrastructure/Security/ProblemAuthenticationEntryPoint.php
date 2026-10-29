<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Http\ProblemDetailsSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers a request that needs authentication and has none (no token, or a security exception inside the
 * firewall) with a 401 problem and `WWW-Authenticate: Bearer`. The reason is never disclosed.
 */
final class ProblemAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ProblemDetailsSubscriber::genericProblem(Response::HTTP_UNAUTHORIZED);
    }
}
