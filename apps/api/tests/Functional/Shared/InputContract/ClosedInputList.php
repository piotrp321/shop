<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * The closed list of the inputs of the contract (spec: docs/features/api-input-contract/spec.md, "Testy", Router
 * i OpenAPI): every query parameter and the header Idempotency-Key that the OpenAPI documents describe has a row in the
 * table, and every route of the API is described in one of the two documents. Both checks only compare lists that
 * exist already; they generate no case.
 *
 * An operation is "METHOD /path" in the form of the OpenAPI documents (upper-case method), an input is
 * "METHOD /path, query name" or "METHOD /path, header Idempotency-Key".
 */
final class ClosedInputList
{
    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return list<string> each described input
     */
    public static function describedInputs(array $documents): array
    {
        $inputs = [];
        foreach ($documents as $document) {
            $paths = $document['paths'] ?? [];
            foreach (\is_array($paths) ? $paths : [] as $path => $item) {
                foreach (\is_array($item) ? $item : [] as $method => $operation) {
                    $parameters = \is_array($operation) && \is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
                    foreach ($parameters as $parameter) {
                        if (!\is_array($parameter) || !\is_string($parameter['name'] ?? null)) {
                            continue;
                        }
                        $input = self::input(strtoupper((string) $method), (string) $path, \is_string($parameter['in'] ?? null) ? $parameter['in'] : '', $parameter['name']);
                        if (null !== $input) {
                            $inputs[$input] = $input;
                        }
                    }
                }
            }
        }

        return array_values($inputs);
    }

    /**
     * @param iterable<InputCase> $rows
     *
     * @return list<string> each input that a row covers
     */
    public static function coveredInputs(iterable $rows): array
    {
        $inputs = [];
        foreach ($rows as $row) {
            $input = self::input($row->method, $row->path, $row->location, $row->input);
            if (null !== $input) {
                $inputs[$input] = $input;
            }
        }

        return array_values($inputs);
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param iterable<InputCase>        $rows
     *
     * @return list<string> each input the documents describe and no row covers
     */
    public static function inputsWithoutARow(array $documents, iterable $rows): array
    {
        return array_values(array_diff(self::describedInputs($documents), self::coveredInputs($rows)));
    }

    /**
     * @param list<array<string, mixed>>  $documents
     * @param list<array{string, string}> $routes    the method and the path of each route
     *
     * @return list<string> each route ("METHOD /path") that no document describes
     */
    public static function routesWithoutADocument(array $documents, array $routes): array
    {
        $described = [];
        foreach ($documents as $document) {
            $paths = $document['paths'] ?? [];
            foreach (\is_array($paths) ? $paths : [] as $path => $item) {
                foreach (array_keys(\is_array($item) ? $item : []) as $method) {
                    $described[strtoupper((string) $method).' '.$path] = true;
                }
            }
        }

        $missing = [];
        foreach ($routes as [$method, $path]) {
            if (!isset($described["$method $path"])) {
                $missing["$method $path"] = "$method $path";
            }
        }

        return array_values($missing);
    }

    /**
     * The guard covers query parameters and Idempotency-Key only: the other headers (the access token of an order,
     * the signature of a webhook) are outside the table.
     */
    private static function input(string $method, string $path, string $location, string $name): ?string
    {
        if ('query' === $location) {
            return "$method $path, query $name";
        }

        return 'header' === $location && 'Idempotency-Key' === $name ? "$method $path, header $name" : null;
    }
}
