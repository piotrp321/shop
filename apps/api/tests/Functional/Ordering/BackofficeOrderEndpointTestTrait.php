<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * What the tests of the order endpoints of the panel share: a client, orders placed and paid through the commands
 * (with their reservation and history), requests with the tokens of staff, and the checks of problem documents,
 * `Cache-Control` and the schemas of openapi-admin.json. On shop_test, rolled back after each test.
 */
trait BackofficeOrderEndpointTestTrait
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    /**
     * @param list<string> $roles
     */
    private static function staff(array $roles, string $username = 'anna'): string
    {
        return TestTokenFactory::staff($roles, ['preferred_username' => $username]);
    }

    /**
     * An order of a guest placed from a cart of two units of a variant (5 in stock, so 2 reserved) and paid: version 2,
     * history placed and paid.
     *
     * @return array{string, string} the id of the order and the variant
     */
    private function paidOrder(string $email = 'jan@example.com'): array
    {
        [$variant] = OrderingFixture::variant($this->connection, 10_000, 5);
        $cart = CartFixture::cart($this->connection, expiresAt: '+3 days', lines: [[$variant, 2]]);
        $placed = $this->dispatch(new PlaceOrder(CartReference::guest($cart), $email, 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', 2 * 12_300, 'key-'.$cart));
        self::assertInstanceOf(PlacedOrder::class, $placed);
        $this->dispatch(new MarkOrderPaid($placed->order->orderId, Uuid::v7()->toRfc4122(), 2 * 12_300, 'PLN', new \DateTimeImmutable()));
        $this->entityManager->clear();

        return [$placed->order->orderId, $variant];
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $path, ?string $token): array
    {
        $server = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request('GET', $path, server: $server);

        return $this->decoded();
    }

    /**
     * @param array<mixed>|string $body an array is sent as JSON, a string as it is
     *
     * @return array<mixed>
     */
    private function postJson(string $path, array|string $body, ?string $token, string $contentType = 'application/json'): array
    {
        $server = ['CONTENT_TYPE' => $contentType];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request('POST', $path, server: $server, content: \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);
        $this->entityManager->clear();

        return $this->decoded();
    }

    /**
     * @return array<mixed>
     */
    private function decoded(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function response(): Response
    {
        return $this->client->getResponse();
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertProblem(int $status, array $problem): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $this->response()->headers->get('Content-Type'));
        self::assertSame($status, $problem['status'] ?? null);
        $this->assertPrivateAndNotStored();
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertUnauthorized(array $problem): void
    {
        $this->assertProblem(401, $problem);
        self::assertSame('Bearer', $this->response()->headers->get('WWW-Authenticate'));
    }

    /**
     * A 409 of the panel: its `type` is in the enum of BackofficeOrderConflictProblem.
     *
     * @param array<mixed> $problem
     */
    private function assertConflict(string $type, string $detail, array $problem): void
    {
        $this->assertProblem(409, $problem);
        self::assertSame(['type' => $type, 'title' => 'Conflict', 'status' => 409, 'detail' => $detail], $problem);
        $properties = self::schema('BackofficeOrderConflictProblem')['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertIsArray($properties['type'] ?? null);
        self::assertIsArray($properties['type']['enum'] ?? null);
        self::assertContains($type, $properties['type']['enum']);
    }

    /**
     * @param array<mixed> $problem
     *
     * @return list<string> the property paths of the violations
     */
    private function violationPaths(int $status, array $problem): array
    {
        $this->assertProblem($status, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        $paths = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            $paths[] = $violation['propertyPath'];
        }

        return array_values(array_unique($paths));
    }

    private function assertPrivateAndNotStored(): void
    {
        $directives = array_map(trim(...), explode(',', (string) $this->response()->headers->get('Cache-Control')));
        self::assertEqualsCanonicalizing(['private', 'no-store'], $directives);
    }

    /**
     * @return array<mixed>
     */
    private static function schema(string $name): array
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$name] ?? null;
        self::assertIsArray($schema, $name.' must be in openapi-admin.json.');

        return $schema;
    }

    /**
     * The keys of the body are the properties of the schema, and so on for every nested object and array of objects
     * that refers to a schema.
     *
     * @param array<mixed> $body
     */
    private static function assertMatchesSchema(string $name, array $body): void
    {
        $schema = self::schema($name);
        self::assertIsArray($schema['properties']);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($body), $name);
        foreach ($schema['properties'] as $property => $definition) {
            self::assertIsArray($definition);
            $items = \is_array($definition['items'] ?? null) ? $definition['items'] : null;
            $ref = $definition['$ref'] ?? ($items['$ref'] ?? null);
            if (!\is_array($definition['allOf'] ?? null) && null === $ref) {
                continue;
            }
            $ref ??= \is_array($definition['allOf'][0] ?? null) ? ($definition['allOf'][0]['$ref'] ?? null) : null;
            self::assertIsString($ref);
            $nested = substr($ref, \strlen('#/components/schemas/'));
            $value = $body[$property];
            if (!\is_array($value)) {
                continue;
            }
            foreach (null === $items ? [$value] : $value as $item) {
                // An item of a string schema (a status, a transition) has no keys to check.
                if (\is_array($item)) {
                    self::assertMatchesSchema($nested, $item);
                }
            }
        }
    }

    private static function assertUtc(mixed $moment): void
    {
        self::assertIsString($moment);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $moment, 'RFC 3339 in UTC');
    }
}
