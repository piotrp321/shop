<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The list of the inputs of the contract is closed: an input that OpenAPI describes and the table does not cover, and
 * a route that OpenAPI does not describe, break the test, so a new parameter or route cannot be added without a row
 * (spec: docs/features/api-input-contract/spec.md, "Testy", Router i OpenAPI). The guard is shown on changed copies:
 * a parameter added to a copy of a document, a row removed from a copy of the table, a route no document describes.
 */
final class ClosedInputListTest extends KernelTestCase
{
    public function testEveryQueryParameterAndIdempotencyKeyOfTheDocumentsHasARow(): void
    {
        $documents = $this->documents();

        $described = ClosedInputList::describedInputs($documents);
        self::assertCount(63, array_filter($described, static fn (string $input): bool => str_contains($input, ', query ')));
        self::assertCount(5, array_filter($described, static fn (string $input): bool => str_contains($input, ', header Idempotency-Key')));
        self::assertSame([], ClosedInputList::inputsWithoutARow($documents, InputRows::all()));
    }

    public function testAParameterAddedToACopyOfADocumentIsReported(): void
    {
        $documents = $this->documents();
        $documents[0] = self::withParameter($documents[0], '/api/v1/products', 'get', ['name' => 'brand', 'in' => 'query', 'schema' => ['type' => 'string']]);
        $documents[1] = self::withParameter($documents[1], '/api/v1/admin/variants/{variantId}/stock/receipts', 'post', ['name' => 'Idempotency-Key-2', 'in' => 'header']);
        $documents[1] = self::withParameter($documents[1], '/api/v1/admin/products', 'get', ['name' => 'Idempotency-Key', 'in' => 'header']);

        self::assertSame(
            ['GET /api/v1/products, query brand', 'GET /api/v1/admin/products, header Idempotency-Key'],
            ClosedInputList::inputsWithoutARow($documents, InputRows::all()),
        );
    }

    public function testARowRemovedFromACopyOfTheTableIsReported(): void
    {
        $rows = array_values(array_filter(
            InputRows::all(),
            static fn (InputCase $row): bool => !('GET' === $row->method && '/api/v1/admin/stock' === $row->path && 'level' === $row->input),
        ));
        self::assertLessThan(\count(InputRows::all()), \count($rows));

        self::assertSame(['GET /api/v1/admin/stock, query level'], ClosedInputList::inputsWithoutARow($this->documents(), $rows));

        $withoutKey = array_values(array_filter(
            InputRows::all(),
            static fn (InputCase $row): bool => !('POST' === $row->method && '/api/v1/orders' === $row->path && 'Idempotency-Key' === $row->input),
        ));
        self::assertSame(['POST /api/v1/orders, header Idempotency-Key'], ClosedInputList::inputsWithoutARow($this->documents(), $withoutKey));
    }

    public function testEveryRouteOfTheApiIsDescribedInOneOfTheDocuments(): void
    {
        $routes = $this->routes();

        self::assertGreaterThanOrEqual(70, \count($routes));
        self::assertSame([], ClosedInputList::routesWithoutADocument($this->documents(), $routes));
    }

    public function testARouteNoDocumentDescribesIsReported(): void
    {
        $routes = [...$this->routes(), ['GET', '/api/v1/brands'], ['DELETE', '/api/v1/products']];

        self::assertSame(
            ['GET /api/v1/brands', 'DELETE /api/v1/products'],
            ClosedInputList::routesWithoutADocument($this->documents(), $routes),
        );
    }

    /**
     * A copy of the document whose operation has one more parameter.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $parameter
     *
     * @return array<string, mixed>
     */
    private static function withParameter(array $document, string $path, string $method, array $parameter): array
    {
        $paths = $document['paths'];
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item);
        $operation = $item[$method] ?? null;
        self::assertIsArray($operation);
        $operation['parameters'] = [...(\is_array($operation['parameters'] ?? null) ? $operation['parameters'] : []), $parameter];
        $item[$method] = $operation;
        $paths[$path] = $item;
        $document['paths'] = $paths;

        return $document;
    }

    /**
     * @return list<array<string, mixed>> openapi.json and openapi-admin.json
     */
    private function documents(): array
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        $documents = [];
        foreach (['openapi.json', 'openapi-admin.json'] as $file) {
            $document = json_decode((string) file_get_contents($projectDir.'/'.$file), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($document);
            /** @var array<string, mixed> $document */
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * Each method of each route of the API (not the routes tests/Support adds in the test environment).
     *
     * @return list<array{string, string}> the method and the path of the route
     */
    private function routes(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            if (str_starts_with($route->getPath(), '/api/v1/') && !str_starts_with($route->getPath(), '/api/v1/_test/')) {
                foreach ($route->getMethods() as $method) {
                    $routes[] = [$method, $route->getPath()];
                }
            }
        }

        return $routes;
    }
}
