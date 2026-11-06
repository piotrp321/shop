<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Http;

use App\PaymentSimulator\Provider;
use App\PaymentSimulator\ProviderCredentials;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authentication of the provider APIs with the API keys of the environment: demo-transfer takes
 * `Authorization: Bearer <key>`, demo-card HTTP Basic with the user `merchant` and the key as the password. Keys are
 * compared with hash_equals; an empty configured key accepts nothing.
 */
final readonly class ApiAuthentication
{
    private const string CARD_USER = 'merchant';

    public function __construct(private ProviderCredentials $credentials)
    {
    }

    public function isAuthorized(Request $request, Provider $provider): bool
    {
        $expected = $this->credentials->apiKey($provider);
        if ('' === $expected) {
            return false;
        }
        $header = (string) $request->headers->get('Authorization');

        return match ($provider) {
            Provider::DemoTransfer => 1 === preg_match('/\ABearer (.+)\z/i', $header, $match) && hash_equals($expected, $match[1]),
            Provider::DemoCard => $this->basicMatches($header, $expected),
        };
    }

    public function refuse(Provider $provider): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'unauthorized'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => Provider::DemoTransfer === $provider ? 'Bearer' : 'Basic realm="demo-card"'],
        );
    }

    private function basicMatches(string $header, string $expectedKey): bool
    {
        if (1 !== preg_match('/\ABasic (.+)\z/i', $header, $match)) {
            return false;
        }
        $decoded = base64_decode($match[1], true);
        if (false === $decoded || !str_contains($decoded, ':')) {
            return false;
        }
        [$user, $password] = explode(':', $decoded, 2);

        return hash_equals(self::CARD_USER, $user) && hash_equals($expectedKey, $password);
    }
}
