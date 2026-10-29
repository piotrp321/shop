<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * On the real Keycloak: the discovery documents the API reads through the internal URL announce the public issuer
 * the firewalls require, and keys the API can verify RS256 signatures with.
 */
final class KeycloakDiscoveryTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function realms(): iterable
    {
        yield 'backoffice' => ['backoffice'];
        yield 'shop' => ['shop'];
    }

    #[DataProvider('realms')]
    public function testDiscoveryAnnouncesTheConfiguredPublicIssuer(string $realm): void
    {
        self::bootKernel();
        $discoveryUrl = self::getContainer()->getParameter("app.oidc.{$realm}.discovery_url");
        $issuer = self::getContainer()->getParameter("app.oidc.{$realm}.issuer");
        self::assertIsString($discoveryUrl);
        self::assertIsString($issuer);
        self::assertSame("http://keycloak:8080/realms/$realm/", $discoveryUrl);

        $http = HttpClient::create(['timeout' => 5]);
        $configuration = $http->request('GET', $discoveryUrl.'.well-known/openid-configuration')->toArray();

        self::assertSame($issuer, $configuration['issuer']);
        self::assertIsString($configuration['jwks_uri']);
        self::assertStringStartsWith($discoveryUrl, $configuration['jwks_uri']);

        $keys = $http->request('GET', $configuration['jwks_uri'])->toArray()['keys'];
        self::assertIsArray($keys);
        $signingKeys = array_filter($keys, static fn (mixed $key): bool => \is_array($key) && 'RS256' === ($key['alg'] ?? null) && 'sig' === ($key['use'] ?? null));
        self::assertNotEmpty($signingKeys, 'The realm must publish an RS256 signing key.');
    }
}
