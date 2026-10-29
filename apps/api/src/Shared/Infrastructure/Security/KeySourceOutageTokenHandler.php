<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * Decorates the `oidc` token handler of a firewall. When the discovery document or the JWKS cannot be fetched
 * (Keycloak unreachable or answering with an error), the token cannot be judged: that is an outage (503), not a
 * bad token (401), so clients keep their sessions. Every other rejection stays a 401.
 *
 * Symfony reports both as BadCredentialsException; a failed fetch is the one caused by an HTTP client exception.
 * Token validation itself makes no HTTP requests.
 */
final readonly class KeySourceOutageTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private AccessTokenHandlerInterface $inner)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            return $this->inner->getUserBadgeFrom($accessToken);
        } catch (BadCredentialsException $exception) {
            $cause = $exception->getPrevious();
            if ($cause instanceof HttpClientException) {
                // Rendered as a 503 without detail; the cause is logged with it. The BadCredentialsException must not
                // be in the chain: the security ExceptionListener searches `previous` and would turn it into a 401.
                throw new ServiceUnavailableHttpException(null, 'The keys of the token issuer cannot be fetched.', $cause);
            }

            throw $exception;
        }
    }
}
