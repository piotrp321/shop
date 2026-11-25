<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Shared\Domain\Text;

/**
 * The closed list of the text fields of the contract of texts (spec: docs/features/api-input-contract/spec.md,
 * "Testy", Strażnik zamkniętej listy pól tekstowych): every property of type string in the body of a request of the
 * OpenAPI documents (nested, in a list, also nullable) and every query parameter `q` has a row in the table of the
 * contract of texts (TextRows) or an entry with a reason in the list of exceptions (TextFieldExceptions). Outside the
 * guard are only the properties with an `enum` and with a format of the closed list (uuid, date-time, date, binary);
 * any other format, `email` too, is a text. The guard compares lists that exist already; it generates no case and
 * changes no table.
 *
 * The documents describe the same field in more than one way, and the guard reads each of them: a path item referenced
 * by `$ref`; parameters of the path item inherited by its operations (an operation parameter of the same name and place
 * takes the place of the inherited one) and parameters referenced from `components/parameters`; a request body
 * referenced from `components/requestBodies`, in every content type; a schema through `$ref` (any depth, a cycle
 * ends), `allOf`, `oneOf`, `anyOf`, `properties`, `items` and `additionalProperties`; a `type` that is a list with
 * `null`. A body that is a text itself is named `(body)`. Left out on purpose: references outside the document (the
 * documents are single files), `not`, the schema keywords of OpenAPI 3.1 (`prefixItems`, `patternProperties`, `if`,
 * `then`, `else`; the documents are 3.0.3), and an object query parameter that the style `form` expands into
 * parameters (the generator writes every query parameter on its own).
 *
 * A text is "METHOD /path, field" in the form of the documents (upper-case method, a list as `[]`: `images[].alt`), a
 * phrase is "METHOD /path, query q". The other query parameters are inputs of the closed list of the inputs
 * (ClosedInputList), not texts.
 */
final class ClosedTextFieldList
{
    /** The formats of a string that are not a text typed by people. */
    private const array CLOSED_FORMATS = ['uuid', 'date-time', 'date', 'binary'];
    /** The name of the text of a body that is a text itself. */
    private const string BODY = '(body)';
    /** The keys of a path item that are operations. */
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return list<string> each text of the body and each phrase `q` the documents describe
     */
    public static function describedFields(array $documents): array
    {
        $fields = [];
        foreach ($documents as $document) {
            foreach (self::operations($document) as ['method' => $method, 'path' => $path, 'operation' => $operation, 'parameters' => $parameters]) {
                $name = "$method $path";
                foreach ($parameters as $parameter) {
                    if ('query' === ($parameter['in'] ?? null) && 'q' === ($parameter['name'] ?? null)) {
                        $fields["$name, query q"] = "$name, query q";
                    }
                }
                foreach (self::bodySchemas($document, $operation) as $schema) {
                    foreach (self::texts($document, $schema, '', []) as $text) {
                        $fields["$name, $text"] = "$name, $text";
                    }
                }
            }
        }

        return array_values($fields);
    }

    /**
     * Each operation of a document, as the guards of the inputs read it (this one and ClosedInputList): the path item
     * is followed through `$ref`, only the keys that are methods are operations, and the parameters are those of the
     * path item and of the operation, each resolved, where an operation parameter of the same name and place takes the
     * place of the inherited one (so one parameter is never two).
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{method: string, path: string, operation: array<mixed>, parameters: list<array<mixed>>}> the method in upper case
     */
    public static function operations(array $document): array
    {
        $operations = [];
        $paths = $document['paths'] ?? [];
        foreach (\is_array($paths) ? $paths : [] as $path => $item) {
            $item = self::dereference($document, $item);
            if (null === $item) {
                continue;
            }
            $inherited = self::parameters($document, $item['parameters'] ?? null);
            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (\is_array($operation)) {
                    $operations[] = [
                        'method' => strtoupper($method),
                        'path' => (string) $path,
                        'operation' => $operation,
                        'parameters' => self::effectiveParameters($inherited, self::parameters($document, $operation['parameters'] ?? null)),
                    ];
                }
            }
        }

        return $operations;
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
                if (!self::hasReason($exception['reason'])) {
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
     * Whether the reason of an exception says anything: by the rule of texts (Text) it is not empty, so a reason made
     * only of white space and invisible characters is none.
     */
    public static function hasReason(string $reason): bool
    {
        return '' !== Text::normalize($reason);
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
     *
     * @return list<array<mixed>> the parameters of a list of an operation or of a path item, each resolved
     */
    private static function parameters(array $document, mixed $list): array
    {
        $parameters = [];
        foreach (\is_array($list) ? $list : [] as $parameter) {
            $parameter = self::dereference($document, $parameter);
            if (null !== $parameter) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * The parameters an operation has: those of its path item, where an operation parameter of the same name and place
     * takes the place of the inherited one (so one parameter is never two).
     *
     * @param list<array<mixed>> $inherited
     * @param list<array<mixed>> $own
     *
     * @return list<array<mixed>>
     */
    private static function effectiveParameters(array $inherited, array $own): array
    {
        $effective = [];
        foreach ([...$inherited, ...$own] as $parameter) {
            $in = \is_string($parameter['in'] ?? null) ? $parameter['in'] : '';
            $name = \is_string($parameter['name'] ?? null) ? $parameter['name'] : '';
            $effective["$in:$name"] = $parameter;
        }

        return array_values($effective);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<mixed>         $operation
     *
     * @return list<array<mixed>> the schema of the body for each content type
     */
    private static function bodySchemas(array $document, array $operation): array
    {
        $body = self::dereference($document, $operation['requestBody'] ?? null);
        $content = null !== $body && \is_array($body['content'] ?? null) ? $body['content'] : [];
        $schemas = [];
        foreach ($content as $media) {
            if (\is_array($media) && \is_array($media['schema'] ?? null)) {
                $schemas[] = $media['schema'];
            }
        }

        return $schemas;
    }

    /**
     * The object a node is: the node itself, or the one its `$ref` points to (through any number of references; one
     * that circles, points outside the document or at nothing is no object).
     *
     * @param array<string, mixed> $document
     *
     * @return array<mixed>|null
     */
    private static function dereference(array $document, mixed $node): ?array
    {
        $followed = [];
        while (\is_array($node) && \is_string($node['$ref'] ?? null)) {
            if (\in_array($node['$ref'], $followed, true)) {
                return null;
            }
            $followed[] = $node['$ref'];
            $node = self::resolve($document, $node['$ref']);
        }

        return \is_array($node) ? $node : null;
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
        if (\in_array('string', $types, true) && !isset($schema['enum']) && !\in_array($schema['format'] ?? null, self::CLOSED_FORMATS, true)) {
            $texts[] = '' === $name ? self::BODY : $name;
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
     * The node a reference of the document points to (`#/components/schemas/Name`; the keys of a pointer are decoded:
     * `~1` is `/`, `~0` is `~`, and percent encoding). A reference outside the document points at nothing.
     *
     * @param array<string, mixed> $document
     */
    private static function resolve(array $document, string $reference): mixed
    {
        if (!str_starts_with($reference, '#/')) {
            return null;
        }
        $node = $document;
        foreach (explode('/', substr($reference, 2)) as $key) {
            $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($key));
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }
}
