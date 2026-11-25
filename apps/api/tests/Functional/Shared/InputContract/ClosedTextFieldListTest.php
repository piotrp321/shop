<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use PHPUnit\Framework\Attributes\DataProvider;
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
            self::assertTrue(ClosedTextFieldList::hasReason($exception['reason']), $exception['field']);
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
                'PUT /api/v1/admin/promotions/{id}, subtitle'.self::NO_ROW,
                'POST /api/v1/admin/promotions, label'.self::NO_ROW,
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

    /**
     * Circumvention 1: the body of a request is not written in the operation but referenced from
     * `components/requestBodies` (also through a chain of references).
     */
    public function testATextOfARequestBodyReferencedFromTheComponentsIsReported(): void
    {
        $body = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['nickname' => ['type' => 'string']]]]]];
        $documents = $this->documents();
        $documents[1] = self::withComponent($documents[1], 'requestBodies', 'NewThing', $body);
        $documents[1] = self::withComponent($documents[1], 'requestBodies', 'NewThingAlias', ['$ref' => '#/components/requestBodies/NewThing']);
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/new-things', ['post' => ['requestBody' => ['$ref' => '#/components/requestBodies/NewThing']]]);
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/new-aliases', ['put' => ['requestBody' => ['$ref' => '#/components/requestBodies/NewThingAlias']]]);

        self::assertSame(
            [
                'POST /api/v1/admin/new-things, nickname'.self::NO_ROW,
                'PUT /api/v1/admin/new-aliases, nickname'.self::NO_ROW,
            ],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}> a path item, the violations it must give
     */
    public static function phrasesDeclaredAboveTheOperation(): iterable
    {
        $query = ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']];
        $header = ['name' => 'q', 'in' => 'header', 'schema' => ['type' => 'string']];
        $violation = 'GET /api/v1/admin/new-list, query q'.self::NO_ROW;

        // Circumvention 2: the parameter of the path item is inherited by every operation of it.
        yield 'a parameter of the path item' => [['parameters' => [$query], 'get' => []], [$violation]];
        yield 'a reference to a parameter of the components in the path item' => [['parameters' => [['$ref' => '#/components/parameters/Phrase']], 'get' => []], [$violation]];
        yield 'a reference to a parameter of the components in the operation' => [['get' => ['parameters' => [['$ref' => '#/components/parameters/Phrase']]]], [$violation]];
        yield 'the same parameter in the path item and in the operation is one' => [['parameters' => [$query], 'get' => ['parameters' => [$query]]], [$violation]];
        yield 'the operation takes over the parameter of the path item' => [['parameters' => [$query], 'get' => ['parameters' => [['$ref' => '#/components/parameters/Phrase']]]], [$violation]];
        yield 'a parameter of the path item serves every operation of it' => [
            ['parameters' => [$query], 'get' => [], 'post' => [], 'delete' => ['parameters' => []]],
            [$violation, 'POST /api/v1/admin/new-list, query q'.self::NO_ROW, 'DELETE /api/v1/admin/new-list, query q'.self::NO_ROW],
        ];
        yield 'a header of the path item is no phrase' => [['parameters' => [$header], 'get' => ['parameters' => []]], []];
        yield 'a header of the operation does not take over the query of the path item' => [['parameters' => [$query], 'get' => ['parameters' => [$header]]], [$violation]];
        yield 'a path item without a phrase' => [['parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'string']]], 'get' => []], []];
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string>         $violations
     */
    #[DataProvider('phrasesDeclaredAboveTheOperation')]
    public function testAPhraseDeclaredInThePathItemOrInTheComponentsIsReportedOnceForEachOperation(array $item, array $violations): void
    {
        $documents = $this->documents();
        $documents[1] = self::withComponent($documents[1], 'parameters', 'Phrase', ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']]);
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/new-list', $item);

        self::assertSame($violations, ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()));
    }

    public function testAPathItemReferencingAnotherOneIsReadAsIt(): void
    {
        $documents = $this->documents();
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/stock-alias', ['$ref' => '#/paths/~1api~1v1~1admin~1stock']);

        self::assertSame(
            ['GET /api/v1/admin/stock-alias, query q'.self::NO_ROW],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    /**
     * Circumvention 3: a body that is a text itself has no property to name; it is reported as `(body)`.
     */
    /**
     * The path item of OpenAPI 3.0.3 allows fields next to `$ref`: they are read with the item referenced, and a field
     * of the same name (a method) takes the place of the referenced one.
     */
    public function testAnOperationNextToTheReferenceOfAPathItemIsReadWithTheReferencedOne(): void
    {
        $body = static fn (string $property): array => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [$property => ['type' => 'string']]]]]];
        $query = ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']];
        $documents = $this->documents();
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/shared', ['get' => ['parameters' => [$query]], 'post' => ['requestBody' => $body('old')]]);
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/alias', ['$ref' => '#/paths/~1api~1v1~1admin~1shared', 'post' => ['parameters' => [$query], 'requestBody' => $body('note')]]);

        self::assertSame(
            [
                'GET /api/v1/admin/shared, query q'.self::NO_ROW,
                'POST /api/v1/admin/shared, old'.self::NO_ROW,
                'GET /api/v1/admin/alias, query q'.self::NO_ROW,
                'POST /api/v1/admin/alias, query q'.self::NO_ROW,
                'POST /api/v1/admin/alias, note'.self::NO_ROW,
            ],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    public function testAPhraseOfThePathItemNextToTheReferenceServesTheOperationsOfBoth(): void
    {
        $documents = $this->documents();
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/shared', ['get' => []]);
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/alias', [
            '$ref' => '#/paths/~1api~1v1~1admin~1shared',
            'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']]],
            'delete' => [],
        ]);

        self::assertSame(
            ['GET /api/v1/admin/alias, query q'.self::NO_ROW, 'DELETE /api/v1/admin/alias, query q'.self::NO_ROW],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    public function testABodyThatIsATextItselfIsReported(): void
    {
        $documents = $this->documents();
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/notes', ['put' => ['requestBody' => ['content' => ['text/plain' => ['schema' => ['type' => 'string']]]]]]);

        self::assertSame(
            ['PUT /api/v1/admin/notes, (body)'.self::NO_ROW],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    /**
     * The schema of the property `data`, the schemas of the components it needs and the texts it holds.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, array<string, mixed>>, list<string>}>
     */
    public static function constructsThatDescribeATextOfTheBody(): iterable
    {
        $string = ['type' => 'string'];

        yield 'a reference to a schema of the components' => [['$ref' => '#/components/schemas/Text'], ['Text' => $string], ['data']];
        yield 'a chain of references through three schemas' => [
            ['$ref' => '#/components/schemas/A'],
            ['A' => ['$ref' => '#/components/schemas/B'], 'B' => ['type' => 'object', 'properties' => ['deep' => ['$ref' => '#/components/schemas/C']]], 'C' => $string],
            ['data.deep'],
        ];
        yield 'a schema that contains itself' => [
            ['$ref' => '#/components/schemas/Tree'],
            ['Tree' => ['type' => 'object', 'properties' => ['label' => $string, 'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tree']]]]],
            ['data.label'],
        ];
        yield 'allOf' => [['allOf' => [['type' => 'object', 'properties' => ['a' => $string]], ['type' => 'object', 'properties' => ['b' => $string]]]], [], ['data.a', 'data.b']];
        yield 'oneOf' => [['oneOf' => [['type' => 'object', 'properties' => ['a' => $string]], ['type' => 'object', 'properties' => ['b' => $string]]]], [], ['data.a', 'data.b']];
        yield 'anyOf of a text and a number' => [['anyOf' => [$string, ['type' => 'integer']]], [], ['data']];
        yield 'oneOf of references' => [['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]], ['A' => $string, 'B' => ['type' => 'object', 'properties' => ['b' => $string]]], ['data', 'data.b']];
        yield 'additionalProperties as a text' => [['type' => 'object', 'additionalProperties' => $string], [], ['data.*']];
        yield 'additionalProperties as a reference' => [['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Text']], ['Text' => $string], ['data.*']];
        yield 'additionalProperties as true' => [['type' => 'object', 'additionalProperties' => true], [], ['data.*']];
        yield 'items of items' => [['type' => 'array', 'items' => ['type' => 'array', 'items' => $string]], [], ['data[][]']];
        yield 'items of an object' => [['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => $string]]]], [], ['data[].a[]']];
        yield 'a type that is a list with null' => [['type' => ['string', 'null']], [], ['data']];
        yield 'a nullable text' => [['type' => 'string', 'nullable' => true], [], ['data']];
        yield 'a text with a format of no closed list' => [['type' => 'string', 'format' => 'password'], [], ['data']];
        yield 'a text with an enum is a closed list' => [['type' => 'string', 'enum' => ['a']], [], []];
    }

    /**
     * @param array<string, mixed>                $schema
     * @param array<string, array<string, mixed>> $components
     * @param list<string>                        $texts
     */
    #[DataProvider('constructsThatDescribeATextOfTheBody')]
    public function testEveryConstructThatDescribesATextOfTheBodyIsRead(array $schema, array $components, array $texts): void
    {
        $documents = $this->documents();
        foreach ($components as $name => $component) {
            $documents[1] = self::withComponent($documents[1], 'schemas', $name, $component);
        }
        $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions', 'post', 'data', $schema);

        self::assertSame(
            array_map(static fn (string $text): string => 'POST /api/v1/admin/promotions, '.$text.self::NO_ROW, $texts),
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    /**
     * A property of a body with no `type` is a possible text: the class of a request takes `mixed` parameters, so the
     * `type` of its schema comes only from an attribute that may be forgotten. What the schema says in depth is read
     * on; one that says nothing of a type is reported as a text.
     *
     * @return iterable<string, array{array<string, mixed>, list<string>}> the schema of the property `data` and the texts it holds
     */
    public static function schemasWithNoType(): iterable
    {
        $string = ['type' => 'string'];

        yield 'only a maximum length' => [['maxLength' => 100], ['data']];
        yield 'only nullable' => [['nullable' => true], ['data']];
        yield 'only a default' => [['default' => null, 'maxLength' => 50], ['data']];
        yield 'an empty schema' => [[], ['data']];
        yield 'the items of a list are an empty schema' => [['type' => 'array', 'items' => []], ['data[]']];
        yield 'the items of a list with no type are a maximum length' => [['items' => ['maxLength' => 5]], ['data[]']];
        yield 'additionalProperties true' => [['type' => 'object', 'additionalProperties' => true], ['data.*']];
        yield 'additionalProperties an empty schema' => [['type' => 'object', 'additionalProperties' => []], ['data.*']];
        yield 'a part of anyOf with no type' => [['anyOf' => [['maxLength' => 5], ['type' => 'integer']]], ['data']];
        yield 'a property with no type in an object with no type' => [['properties' => ['note' => ['maxLength' => 5]]], ['data.note']];

        // What is read in depth or is no text.
        yield 'properties with no type' => [['properties' => ['a' => $string]], ['data.a']];
        yield 'items with no type' => [['items' => $string], ['data[]']];
        yield 'allOf with no type' => [['allOf' => [['type' => 'object', 'properties' => ['a' => $string]]]], ['data.a']];
        yield 'a reference with no type' => [['$ref' => '#/components/schemas/Text'], ['data']];
        yield 'an enum with no type' => [['enum' => ['a', 'b']], []];
        yield 'a format of the closed list with no type' => [['format' => 'uuid'], []];
        yield 'additionalProperties false' => [['type' => 'object', 'additionalProperties' => false], []];
        yield 'only the required keys of a part of oneOf' => [
            ['type' => 'object', 'properties' => ['a' => $string, 'b' => $string], 'oneOf' => [['required' => ['a']], ['required' => ['b']]]],
            ['data.a', 'data.b'],
        ];
        yield 'a type that is not a text' => [['type' => 'integer', 'maximum' => 5], []];
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $texts
     */
    #[DataProvider('schemasWithNoType')]
    public function testASchemaWithNoTypeIsAPossibleTextAndIsReported(array $schema, array $texts): void
    {
        $documents = $this->documents();
        $documents[1] = self::withComponent($documents[1], 'schemas', 'Text', ['type' => 'string']);
        $documents[1] = self::withBodyProperty($documents[1], '/api/v1/admin/promotions', 'post', 'data', $schema);

        self::assertSame(
            array_map(static fn (string $text): string => 'POST /api/v1/admin/promotions, '.$text.self::NO_ROW, $texts),
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    public function testABodyWithNoSchemaTypeAtAllIsReported(): void
    {
        $documents = $this->documents();
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/notes', ['put' => ['requestBody' => ['content' => ['application/json' => ['schema' => []]]]]]);

        self::assertSame(
            ['PUT /api/v1/admin/notes, (body)'.self::NO_ROW],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}> the parameter `q`
     */
    public static function phrasesWithNoSchemaType(): iterable
    {
        yield 'with no schema' => [['name' => 'q', 'in' => 'query']];
        yield 'with an empty schema' => [['name' => 'q', 'in' => 'query', 'schema' => []]];
        yield 'with a schema of a maximum length' => [['name' => 'q', 'in' => 'query', 'schema' => ['maxLength' => 100]]];
    }

    /**
     * @param array<string, mixed> $parameter
     */
    #[DataProvider('phrasesWithNoSchemaType')]
    public function testAPhraseWhoseSchemaSaysNoTypeIsReported(array $parameter): void
    {
        $documents = $this->documents();
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/new-list', ['get' => ['parameters' => [$parameter]]]);

        self::assertSame(['GET /api/v1/admin/new-list, query q'.self::NO_ROW], ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()));
    }

    public function testEveryContentTypeOfTheBodyIsRead(): void
    {
        $object = static fn (string $property): array => ['schema' => ['type' => 'object', 'properties' => [$property => ['type' => 'string']]]];
        $documents = $this->documents();
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/new-things', ['patch' => ['requestBody' => ['content' => [
            'application/json' => $object('json'),
            'multipart/form-data' => $object('form'),
            'application/x-www-form-urlencoded' => $object('urlencoded'),
        ]]]]);

        self::assertSame(
            [
                'PATCH /api/v1/admin/new-things, json'.self::NO_ROW,
                'PATCH /api/v1/admin/new-things, form'.self::NO_ROW,
                'PATCH /api/v1/admin/new-things, urlencoded'.self::NO_ROW,
            ],
            ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()),
        );
    }

    public function testAReferenceToNothingIsLeftOutAndOneThatCirclesEnds(): void
    {
        $documents = $this->documents();
        $documents[1] = self::withComponent($documents[1], 'requestBodies', 'One', ['$ref' => '#/components/requestBodies/Two']);
        $documents[1] = self::withComponent($documents[1], 'requestBodies', 'Two', ['$ref' => '#/components/requestBodies/One']);
        $documents[1] = self::withPathItem($documents[1], '/api/v1/admin/new-things', [
            'parameters' => [['$ref' => '#/components/parameters/Missing']],
            'post' => ['requestBody' => ['$ref' => '#/components/requestBodies/One']],
            'put' => ['requestBody' => ['$ref' => '#/components/requestBodies/Missing']],
        ]);

        self::assertSame([], ClosedTextFieldList::violations($documents, TextRows::all(), TextFieldExceptions::all()));
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
        $exceptions = array_map(static fn (array $exception): array => match ($exception['field']) {
            'slug' => ['reason' => ''] + $exception,
            'trackingUrlTemplate' => ['reason' => " \u{A0}\t"] + $exception,
            default => $exception,
        }, TextFieldExceptions::all());

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function reasonsEmptyByTheRuleOfTexts(): iterable
    {
        yield 'U+200B' => ["\u{200B}"];
        yield 'U+3164' => ["\u{3164}"];
        yield 'U+2060, U+FEFF and U+00AD' => ["\u{2060}\u{FEFF}\u{AD}"];
        yield 'invisible characters among spaces' => [" \u{200B}\u{A0}\u{3164}\t"];
    }

    /**
     * The reason counts by the rule of texts (Text::normalize), not by white space alone: a reason made only of
     * invisible characters is no reason.
     */
    #[DataProvider('reasonsEmptyByTheRuleOfTexts')]
    public function testAnExceptionWhoseReasonIsOnlyInvisibleCharactersIsReported(string $reason): void
    {
        $exceptions = array_map(static fn (array $exception): array => 'code' === $exception['field'] ? ['reason' => $reason] + $exception : $exception, TextFieldExceptions::all());

        self::assertSame(
            ['POST /api/v1/admin/discount-codes, code: an exception without a reason'],
            ClosedTextFieldList::violations($this->documents(), TextRows::all(), $exceptions),
        );
    }

    public function testAnExceptionWithAReasonWithAVisibleCharacterIsNotReported(): void
    {
        $exceptions = array_map(static fn (array $exception): array => 'code' === $exception['field'] ? ['reason' => "\u{200B}format\u{200B}"] + $exception : $exception, TextFieldExceptions::all());

        self::assertSame([], ClosedTextFieldList::violations($this->documents(), TextRows::all(), $exceptions));
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
     * A copy of the document with one more entry in `components/<group>`.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $component
     *
     * @return array<string, mixed>
     */
    private static function withComponent(array $document, string $group, string $name, array $component): array
    {
        $components = \is_array($document['components'] ?? null) ? $document['components'] : [];
        $entries = \is_array($components[$group] ?? null) ? $components[$group] : [];
        $entries[$name] = $component;
        $components[$group] = $entries;
        $document['components'] = $components;

        return $document;
    }

    /**
     * A copy of the document with one more path item.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function withPathItem(array $document, string $path, array $item): array
    {
        $paths = $document['paths'];
        self::assertIsArray($paths);
        $paths[$path] = $item;
        $document['paths'] = $paths;

        return $document;
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
        /** @var array<string, mixed> $operation */

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
