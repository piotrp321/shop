<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The list of the text fields of the contract of texts is closed: every property of type string in the body of a
 * request of the two OpenAPI documents and every query parameter `q` has a row in the table of the contract of texts
 * (TextRows) or an entry with a reason in the list of exceptions (TextFieldExceptions); only properties with an enum or
 * with a format of the closed list (uuid, date-time, date, binary) are outside. The guard is shown on changed copies: a
 * text added to a copy of a document, a row for a field no document describes, an exception without a reason, a table
 * without the row of the email of an order (spec: docs/features/api-input-contract/spec.md, "Testy", Strażnik
 * zamkniętej listy pól tekstowych).
 */
final class ClosedTextFieldListTest extends KernelTestCase
{
    private const string NO_ROW = ': a text of the documents with no row of the table of texts and no exception';
    private const string NO_FIELD = ': a row of the table of texts for a field the documents do not describe';

    public function testEveryTextOfTheDocumentsHasARowOrAnException(): void
    {
        $documents = $this->documents();

        $described = ClosedTextFieldList::describedFields($documents);
        self::assertCount(7, array_filter($described, static fn (string $field): bool => str_contains($field, ', query q')));
        self::assertCount(48, array_filter($described, static fn (string $field): bool => !str_contains($field, ', query q')));
        self::assertContains('POST /api/v1/orders, email', $described, 'a format outside the closed list is a text');
        self::assertNotContains('POST /api/v1/admin/discount-codes, startsAt', $described, 'date-time is outside the guard');
        self::assertNotContains('POST /api/v1/admin/orders/{orderId}/status-changes, transition', $described, 'an enum is outside the guard');
        self::assertNotContains('POST /api/v1/admin/products, mainCategoryId', $described, 'uuid is outside the guard');

        self::assertSame([], ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()));
        // Each described text is covered once by a row or an exception: 40 rows of fields plus 15 excepted ones.
        self::assertCount(40, array_unique(ClosedTextFieldList::coveredFields(TextRows::all())));
        self::assertCount(15, ClosedTextFieldList::excepted(TextFieldExceptions::all()));
        self::assertCount(55, $described);
    }

    public function testTheExceptionsAreTheTextsOutsideTheRuleOfTheSpec(): void
    {
        $exceptions = TextFieldExceptions::all();

        self::assertSame(
            ['slug', 'taxClass', 'images[].key', 'code', 'trackingNumber', 'trackingUrlTemplate', 'discountCode'],
            array_column($exceptions, 'field'),
        );
        foreach ($exceptions as $exception) {
            self::assertNotSame('', trim($exception['reason']), $exception['field']);
        }
    }

    public function testATextWithNoFormatAddedToACopyOfADocumentIsReported(): void
    {
        $documents = $this->documents();
        $documents[0] = self::withBodyProperty($documents[0], '/api/v1/orders', 'post', 'nickname', ['type' => 'string']);
        $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions', 'post', 'label', ['type' => 'string', 'nullable' => true]);
        $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions/{id}', 'put', 'subtitle', ['type' => ['string', 'null']]);

        self::assertSame(
            [
                'POST /api/v1/orders, nickname'.self::NO_ROW,
                'POST /api/v1/admin/promotions, label'.self::NO_ROW,
                'PUT /api/v1/admin/promotions/{id}, subtitle'.self::NO_ROW,
            ],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    public function testATextInAListOfObjectsAndATextWithAFormatOutsideTheClosedListAreReported(): void
    {
        $documents = $this->documents();
        $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions', 'post', 'badges', [
            'type' => 'array',
            'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'color' => ['type' => 'string', 'format' => 'hostname']]],
        ]);
        $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions', 'post', 'contact', ['type' => 'string', 'format' => 'email', 'nullable' => true]);
        $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions', 'post', 'tags', ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'password']]);

        self::assertSame(
            [
                'POST /api/v1/admin/promotions, badges[].label'.self::NO_ROW,
                'POST /api/v1/admin/promotions, badges[].color'.self::NO_ROW,
                'POST /api/v1/admin/promotions, contact'.self::NO_ROW,
                'POST /api/v1/admin/promotions, tags[]'.self::NO_ROW,
            ],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    public function testAPhraseOfANewListAddedToACopyOfADocumentIsReported(): void
    {
        $documents = $this->documents();
        $documents[1] = self::withParameter($documents[1], '/api/v1/admin/promotions', 'get', ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']]);
        $documents[1] = self::withParameter($documents[1], '/api/v1/admin/promotions', 'get', ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']]);

        self::assertSame(
            ['GET /api/v1/admin/promotions, query q'.self::NO_ROW],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    public function testThePropertiesTheGuardLeavesOutAreNotReported(): void
    {
        $documents = $this->documents();
        foreach ([
            'identifier' => ['type' => 'string', 'format' => 'uuid'],
            'moment' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            'day' => ['type' => 'string', 'format' => 'date'],
            'file' => ['type' => 'string', 'format' => 'binary'],
            'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
            'count' => ['type' => 'integer'],
            'flag' => ['type' => 'boolean'],
            'identifiers' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
        ] as $name => $schema) {
            $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions', 'post', $name, $schema);
        }

        self::assertSame([], ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()));
    }

    public function testARowOfAFieldTheDocumentsDoNotDescribeIsReported(): void
    {
        $extra = TextRow::required('POST /api/v1/orders', null, 'guestCheckout', 'nickname', 'Kasia', ['nickname' => 'Kasia'],
            empty: 'Pusta.', forbidden: 'Znaki.', wrongType: 'Typ.', stored: TextRow::column('SELECT 1', []));
        $images = TextRow::required('POST /api/v1/admin/products', InputCase::STAFF, 'productWrite', 'images[3].caption', 'Zdjęcie', ['images' => [['caption' => 'Zdjęcie']]],
            empty: 'Pusta.', forbidden: 'Znaki.', wrongType: 'Typ.', stored: TextRow::column('SELECT 1', []));
        $phrase = TextRow::phrase('GET /api/v1/admin/promotions', InputCase::STAFF, 'product', 'namiot', empty: 'Krótka.', forbidden: 'Znaki.', phrase: 'namiot', record: '{productId}');
        $rows = [...TextRows::all(), $extra, $images, $phrase];

        self::assertSame(
            [
                'POST /api/v1/orders, nickname'.self::NO_FIELD,
                'POST /api/v1/admin/products, images[].caption'.self::NO_FIELD,
                'GET /api/v1/admin/promotions, query q'.self::NO_FIELD,
            ],
            ClosedTextFieldList::violations($this->documents(), $rows, TextFieldExceptions::all()),
        );
    }

    public function testAnExceptionWithoutAReasonIsReported(): void
    {
        $exceptions = TextFieldExceptions::all();
        $exceptions[0]['reason'] = '';
        $exceptions[5]['reason'] = " \u{A0}\t";

        self::assertSame(
            [
                'POST /api/v1/admin/categories, slug: an exception without a reason',
                'PUT /api/v1/admin/categories/{id}, slug: an exception without a reason',
                'POST /api/v1/admin/products, slug: an exception without a reason',
                'PUT /api/v1/admin/products/{id}, slug: an exception without a reason',
                'POST /api/v1/admin/delivery-methods, trackingUrlTemplate: an exception without a reason',
                'PUT /api/v1/admin/delivery-methods/{id}, trackingUrlTemplate: an exception without a reason',
            ],
            ClosedTextFieldList::violations($this->documents(), TextRows::all(), $exceptions),
        );
    }

    public function testAnExceptionForAFieldTheDocumentsDoNotDescribeIsReported(): void
    {
        $exceptions = [...TextFieldExceptions::all(), ['field' => 'nickname', 'operations' => ['POST /api/v1/orders'], 'reason' => 'no such field']];

        self::assertSame(
            ['POST /api/v1/orders, nickname: an exception for a field the documents do not describe'],
            ClosedTextFieldList::violations($this->documents(), TextRows::all(), $exceptions),
        );
    }

    public function testATableWithoutTheRowOfTheEmailOfAnOrderIsReported(): void
    {
        $rows = array_values(array_filter(
            TextRows::all(),
            static fn (TextRow $row): bool => !('POST' === $row->method && '/api/v1/orders' === $row->path && 'email' === $row->field),
        ));
        self::assertCount(\count(TextRows::all()) - 1, $rows);

        self::assertSame(
            ['POST /api/v1/orders, email'.self::NO_ROW],
            ClosedTextFieldList::violations($this->documents(), $rows, TextFieldExceptions::all()),
        );
    }

    public function testSeveralRowsOfOneOperationAndFieldAreTolerated(): void
    {
        $rows = TextRows::all();
        $rows = [...$rows, ...$rows];

        self::assertSame([], ClosedTextFieldList::violations($this->documents(), $rows, TextFieldExceptions::all()));
    }

    public function testTheGuardChangesNeitherTheTableNorTheList(): void
    {
        $names = static fn (): array => array_map(static fn (TextRow $row): string => $row->name(), TextRows::all());
        $before = [$names(), TextFieldExceptions::all()];

        ClosedTextFieldList::violations($this->documents(), TextRows::all(), TextFieldExceptions::all());

        self::assertSame($before, [$names(), TextFieldExceptions::all()]);
    }

    /**
     * A copy of the document whose request body of the operation has one more property.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function withBodyProperty(array $document, string $path, string $method, string $name, array $schema): array
    {
        $operation = self::operation($document, $path, $method);
        $body = $operation['requestBody'] ?? null;
        self::assertIsArray($body);
        $content = $body['content'] ?? null;
        self::assertIsArray($content);
        $json = $content['application/json'] ?? null;
        self::assertIsArray($json);
        $json['schema'] = ['allOf' => [$json['schema'], ['type' => 'object', 'properties' => [$name => $schema]]]];
        $content['application/json'] = $json;
        $body['content'] = $content;
        $operation['requestBody'] = $body;

        return self::withOperation($document, $path, $method, $operation);
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
        $operation = self::operation($document, $path, $method);
        $operation['parameters'] = [...(\is_array($operation['parameters'] ?? null) ? $operation['parameters'] : []), $parameter];

        return self::withOperation($document, $path, $method, $operation);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function operation(array $document, string $path, string $method): array
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item);
        $operation = $item[$method] ?? null;
        self::assertIsArray($operation);

        return $operation;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private static function withOperation(array $document, string $path, string $method, array $operation): array
    {
        $paths = $document['paths'];
        self::assertIsArray($paths);
        $item = $paths[$path];
        self::assertIsArray($item);
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
}
