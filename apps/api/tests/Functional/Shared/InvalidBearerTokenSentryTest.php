<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use App\Tests\Support\Security\TestTokenFactory;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A Bearer token that cannot be verified is a 401 and no Sentry event (spec: docs/features/abuse-limits/spec.md, L10 and
 * D13): the token handler logs it as an error of the `security` channel, which the Sentry handler skips, so a caller
 * with a made-up token can neither use up the event limit nor delay the response with the synchronous send. An explicit
 * error of the application is still an event.
 */
final class InvalidBearerTokenSentryTest extends WebTestCase
{
    use SentryEnvironment;

    /**
     * @return iterable<string, array{string, \Closure(): string}>
     */
    public static function invalidTokens(): iterable
    {
        foreach (['/api/v1/me', '/api/v1/admin/orders'] as $path) {
            yield "$path: not a token" => [$path, static fn (): string => 'x'];
            yield "$path: foreign signature" => [$path, static fn (): string => TestTokenFactory::sign(
                TestTokenFactory::claimsFor('shop'),
                JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'foreign']),
            )];
            yield "$path: expired" => [$path, static fn (): string => TestTokenFactory::customer(['iat' => time() - 900, 'exp' => time() - 600])];
            yield "$path: another issuer" => [$path, static fn (): string => TestTokenFactory::customer(['iss' => 'https://evil.example/realms/shop'])];
            yield "$path: alg none" => [$path, static fn (): string => self::base64Url('{"alg":"none","typ":"JWT"}').'.'.self::base64Url((string) json_encode(TestTokenFactory::claimsFor('shop'))).'.'];
        }
    }

    /**
     * @param \Closure(): string $token
     */
    #[DataProvider('invalidTokens')]
    public function testAnInvalidTokenIsUnauthorizedAndNoEvent(string $path, \Closure $token): void
    {
        $this->enableSentry();
        $client = static::createClient();

        $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token()]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], self::sentry($client->getContainer())->envelopes(), 'a bad token is no event');
    }

    public function testAnExplicitErrorOfTheApplicationIsStillAnEvent(): void
    {
        $this->enableSentry();
        $client = static::createClient();
        $logger = $client->getContainer()->get(LoggerInterface::class);
        \assert($logger instanceof LoggerInterface);

        $logger->error('Payment requires a manual refund');

        self::assertCount(1, self::sentry($client->getContainer())->items('event'));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
