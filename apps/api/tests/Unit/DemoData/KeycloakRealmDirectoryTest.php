<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Accounts\KeycloakAdmin;
use App\DemoData\Accounts\KeycloakRealmDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The adapter of Keycloak answers only what Keycloak said: a status other than the expected ones is an error of the
 * directory, never an empty answer (an account "without groups" would be skipped by the reconciliation).
 */
final class KeycloakRealmDirectoryTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatuses(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
        yield 'server error' => [500];
        yield 'unavailable' => [503];
    }

    #[DataProvider('errorStatuses')]
    public function testGroupsOfFailsWhenKeycloakAnswersWithAnError(int $status): void
    {
        $directory = self::directory(new MockResponse('{"error":"unknown_error"}', ['http_code' => $status, 'response_headers' => ['content-type: application/json']]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP '.$status);

        $directory->groupsOf('0b1c2d3e-0000-4000-8000-000000000001');
    }

    public function testGroupsOfIsNullForAnAccountThatDoesNotExist(): void
    {
        $directory = self::directory(new MockResponse('{"error":"User not found"}', ['http_code' => 404, 'response_headers' => ['content-type: application/json']]));

        self::assertNull($directory->groupsOf('0b1c2d3e-0000-4000-8000-000000000001'));
    }

    public function testGroupsOfListsTheIdsOfTheGroups(): void
    {
        $directory = self::directory(new MockResponse('[{"id":"g1","name":"a","path":"/a"},{"id":"g2","name":"b","path":"/b"}]', ['response_headers' => ['content-type: application/json']]));

        self::assertSame(['g1', 'g2'], $directory->groupsOf('0b1c2d3e-0000-4000-8000-000000000001'));
    }

    private static function directory(MockResponse $answer): KeycloakRealmDirectory
    {
        $token = new MockResponse('{"access_token":"t","expires_in":300}', ['response_headers' => ['content-type: application/json']]);
        $admin = new KeycloakAdmin(new MockHttpClient([$token, $answer]), 'http://keycloak.test', 'admin', 'secret');

        return $admin->realm('shop');
    }
}
