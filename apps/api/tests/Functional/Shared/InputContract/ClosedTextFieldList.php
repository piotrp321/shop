<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * The closed list of the text fields of the contract of texts (spec: docs/features/api-input-contract/spec.md,
 * "Testy", Strażnik zamkniętej listy pól tekstowych): every property of type string in the body of a request of the
 * OpenAPI documents (nested, in a list, also nullable) and every query parameter `q` has a row in the table of the
 * contract of texts (TextRows) or an entry with a reason in the list of exceptions (TextFieldExceptions). Outside the
 * guard are only the properties with an `enum` and with a format of the closed list (uuid, date-time, date, binary);
 * any other format, `email` too, is a text. The guard compares lists that exist already; it generates no case and
 * changes no table.
 *
 * A text is "METHOD /path, field" in the form of the documents (upper-case method, a list as `[]`: `images[].alt`), a
 * phrase is "METHOD /path, query q". The other query parameters are inputs of the closed list of the inputs
 * (ClosedInputList), not texts.
 */
final class ClosedTextFieldList
{
    /** The formats of a string that are not a text typed by people. */
    private const array CLOSED_FORMATS = ['uuid', 'date-time', 'date', 'binary'];

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return list<string> each text of the body and each phrase `q` the documents describe
     */
    public static function describedFields(array $documents): array
    {
        $fields = [];
        foreach ($documents as $document) {
            $paths = $document['paths'] ?? [];
            foreach (\is_array($paths) ? $paths : [] as $path => $item) {
                foreach (\is_array($item) ? $item : [] as $method => $operation) {
                    if (!\is_array($operation)) {
                        continue;
                    }
                    $name = strtoupper((string) $method).' '.$path;
                    foreach (self::parameters($document, $operation) as $parameter) {
                        if ('query' === ($parameter['in'] ?? null) && 'q' === ($parameter['name'] ?? null)) {
                            $fields["$name, query q"] = "$name, query q";
                        }
                    }
                    foreach (self::bodySchemas($operation) as $schema) {
                        foreach (self::texts($document, $schema, '', []) as $text) {
                            $fields["$name, $text"] = "$name, $text";
                        }
                    }
                }
            }
        }

        return array_values($fields);
    }

    /**
     * @param iterable<TextRow> $rows
     *
     * @return list<string> the text of each row (several rows may cover the same one)
     */
    public static function coveredFields(iterable $rows): array
    {
        $fields = [];
        foreach ($rows as $row) {
            $fields[] = $row->method.' '.$row->path.', '.(TextRow::PHRASE === $row->kind ? 'query q' : self::documented($row->field));
        }

        return $fields;
    }

    /**
     * @param list<array{field: string, operations: list<string>, reason: string}> $exceptions
     *
     * @return list<string> each text an exception covers, once for each of its operations
     */
    public static function excepted(array $exceptions): array
    {
        $fields = [];
        foreach ($exceptions as $exception) {
            foreach ($exception['operations'] as $operation) {
                $fields[] = $operation.', '.$exception['field'];
            }
        }

        return $fields;
    }

    /**
     * @param list<array<string, mixed>>                                           $documents
     * @param iterable<TextRow>                                                    $rows
     * @param list<array{field: string, operations: list<string>, reason: string}> $exceptions
     *
     * @return list<string> what breaks the closed list: a text with no row and no exception, a row for a field no
     *                      document describes, an exception without a reason or for a field no document describes
     */
    public static function violations(array $documents, iterable $rows, array $exceptions): array
    {
        $described = self::describedFields($documents);
        $known = array_flip($described);
        $covered = array_flip(self::coveredFields($rows));
        $excepted = array_flip(self::excepted($exceptions));

        $violations = [];
        foreach ($described as $field) {
            if (!isset($covered[$field]) && !isset($excepted[$field])) {
                $violations[] = $field.': a text of the documents with no row of the table of texts and no exception';
            }
        }
        foreach (array_keys($covered) as $field) {
            if (!isset($known[$field])) {
                $violations[] = $field.': a row of the table of texts for a field the documents do not describe';
            }
        }
        foreach ($exceptions as $exception) {
            foreach ($exception['operations'] as $operation) {
                $field = $operation.', '.$exception['field'];
                if (1 === preg_match('/^[\s\p{Z}]*$/u', $exception['reason'])) {
                    $violations[] = $field.': an exception without a reason';
                }
                if (!isset($known[$field])) {
                    $violations[] = $field.': an exception for a field the documents do not describe';
                }
            }
        }

        return $violations;
    }

    /**
     * The name of a field of a row as the documents write it: every index of a list is `[]`.
     */
    private static function documented(string $field): string
    {
        return preg_replace('/\[\d+\]/', '[]', $field) ?? $field;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<mixed>         $operation
     *
     * @return list<array<mixed>>
     */
    private static function parameters(array $document, array $operation): array
    {
        $parameters = [];
        foreach (\is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [] as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['$ref'] ?? null)) {
                $parameter = self::resolve($document, $parameter['$ref']);
            }
            if (\is_array($parameter)) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * @param array<mixed> $operation
     *
     * @return list<array<mixed>> the schema of the body for each content type
     */
    private static function bodySchemas(array $operation): array
    {
        $body = $operation['requestBody'] ?? null;
        $content = \is_array($body) && \is_array($body['content'] ?? null) ? $body['content'] : [];
        $schemas = [];
        foreach ($content as $media) {
            if (\is_array($media) && \is_array($media['schema'] ?? null)) {
                $schemas[] = $media['schema'];
            }
        }

        return $schemas;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<mixed>         $schema
     * @param list<string>         $followed the references on the way down, so a schema that contains itself ends
     *
     * @return list<string> each text of the schema, by its name from the body (`images[].alt`)
     */
    private static function texts(array $document, array $schema, string $name, array $followed): array
    {
        if (\is_string($schema['$ref'] ?? null)) {
            if (\in_array($schema['$ref'], $followed, true)) {
                return [];
            }
            $resolved = self::resolve($document, $schema['$ref']);

            return \is_array($resolved) ? self::texts($document, $resolved, $name, [...$followed, $schema['$ref']]) : [];
        }

        $texts = [];
        foreach (['allOf', 'oneOf', 'anyOf'] as $combination) {
            foreach (\is_array($schema[$combination] ?? null) ? $schema[$combination] : [] as $part) {
                if (\is_array($part)) {
                    $texts = [...$texts, ...self::texts($document, $part, $name, $followed)];
                }
            }
        }

        $types = \is_array($schema['type'] ?? null) ? $schema['type'] : [$schema['type'] ?? null];
        if ('' !== $name && \in_array('string', $types, true) && !isset($schema['enum']) && !\in_array($schema['format'] ?? null, self::CLOSED_FORMATS, true)) {
            $texts[] = $name;
        }
        foreach (\is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $property => $child) {
            if (\is_array($child)) {
                $texts = [...$texts, ...self::texts($document, $child, '' === $name ? (string) $property : $name.'.'.$property, $followed)];
            }
        }
        if (\is_array($schema['additionalProperties'] ?? null)) {
            $texts = [...$texts, ...self::texts($document, $schema['additionalProperties'], $name.'.*', $followed)];
        }
        if (\is_array($schema['items'] ?? null)) {
            $texts = [...$texts, ...self::texts($document, $schema['items'], $name.'[]', $followed)];
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function resolve(array $document, string $reference): mixed
    {
        $node = $document;
        foreach (explode('/', ltrim($reference, '#/')) as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }
}
