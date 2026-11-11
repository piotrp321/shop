<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineStockItemRepository;
use App\Tests\Functional\Shared\RequiredKeyViolationsTrait;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/admin/variants/{variantId}/stock/receipts on PostgreSQL (shop_test, rolled back after each test).
 */
final class ReceiveStockEndpointTest extends WebTestCase
{
    use Factories;
    use RequiredKeyViolationsTrait;
    use StockEndpointTestTrait;

    private const string UNKNOWN_VARIANT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string KEY = '6f1b0e4a-3c1d-4c1e-9a7b-2d5e8f9a0b1c';

    public function testTheFirstReceiptCreatesTheStockAndAnswersWithIt(): void
    {
        $variantId = $this->variant();

        $stock = $this->receive($variantId, ['quantity' => 12, 'note' => 'Dostawa od Biegam']);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['variantId' => $variantId, 'physical' => 12, 'reserved' => 0, 'available' => 12, 'level' => 'in_stock', 'version' => 1],
            array_diff_key($stock, ['updatedAt' => 1]),
        );
        self::assertUtcTimestamp($stock);
        $this->assertKeysMatchSchema($stock, 'StockStateResponse');
        $this->assertPrivateAndNotStored();
        self::assertSame(['physical' => 12, 'reserved' => 0, 'version' => 1], $this->storedStock($variantId));
        self::assertSame(
            [['type' => 'receipt', 'physical_delta' => 12, 'physical_after' => 12, 'reason' => null, 'note' => 'Dostawa od Biegam', 'reference' => self::KEY, 'source' => 'staff', 'actor_id' => TestTokenFactory::STAFF_ID, 'actor_name' => 'admin']],
            $this->movements($variantId),
        );
    }

    public function testAReceiptAddsToTheStoredStock(): void
    {
        $variantId = $this->variant();
        StockFixture::stock($this->connection, 3, 2, 4, $variantId);

        $stock = $this->receive($variantId, ['quantity' => 2, 'note' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([5, 2, 3, 'low_stock', 5], [$stock['physical'], $stock['reserved'], $stock['available'], $stock['level'], $stock['version']]);
        self::assertSame(['physical' => 5, 'reserved' => 2, 'version' => 5], $this->storedStock($variantId));
    }

    public function testTheSameKeyAndContentAgainIsAnOkWithTheCurrentStockAndAddsNothing(): void
    {
        $variantId = $this->variant();
        $this->receive($variantId, ['quantity' => 12, 'note' => 'Dostawa']);
        self::assertResponseStatusCodeSame(201);
        // Something else changed the stock since.
        $this->connection->executeStatement('UPDATE inventory_stock_item SET reserved = 4, version = 2 WHERE variant_id = ?', [$variantId]);

        $stock = $this->receive($variantId, ['quantity' => 12, 'note' => ' Dostawa ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([12, 4, 8, 2], [$stock['physical'], $stock['reserved'], $stock['available'], $stock['version']]);
        $this->assertKeysMatchSchema($stock, 'StockStateResponse');
        $this->assertPrivateAndNotStored();
        self::assertSame(['physical' => 12, 'reserved' => 4, 'version' => 2], $this->storedStock($variantId));
        self::assertCount(1, $this->movements($variantId));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function otherContents(): iterable
    {
        yield 'another quantity' => [['quantity' => 13, 'note' => 'Dostawa']];
        yield 'another note' => [['quantity' => 12, 'note' => 'Inna']];
        yield 'no note' => [['quantity' => 12, 'note' => null]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('otherContents')]
    public function testTheSameKeyWithOtherContentIsAConflictAndAddsNothing(array $body): void
    {
        $variantId = $this->variant();
        $this->receive($variantId, ['quantity' => 12, 'note' => 'Dostawa']);

        $this->receive($variantId, $body);

        $problem = $this->assertProblem(409);
        self::assertSame('Klucz przyjęcia został już użyty z inną treścią albo dla innego produktu.', $problem['detail']);
        self::assertSame(['physical' => 12, 'reserved' => 0, 'version' => 1], $this->storedStock($variantId));
        self::assertCount(1, $this->movements($variantId));
    }

    public function testTheSameKeyForAnotherVariantIsAConflictAndCreatesNothing(): void
    {
        $first = $this->variant();
        $second = $this->variant();
        $this->receive($first, ['quantity' => 12, 'note' => null]);

        $this->receive($second, ['quantity' => 12, 'note' => null]);

        $this->assertProblem(409);
        self::assertNull($this->storedStock($second));
        self::assertSame([], $this->movements($second));
    }

    public function testAKeyTakenByAnotherVariantBetweenTheLookupAndTheWriteIsAConflictNotAnError(): void
    {
        $first = $this->variant();
        $second = $this->variant();
        StockFixture::stock($this->connection, 1, 0, 1, $second);
        // The receipt of the first variant with the key, as a committed receipt leaves it.
        StockFixture::stock($this->connection, 12, 0, 1, $first);
        $this->connection->executeStatement('UPDATE inventory_stock_movement SET reference = ? WHERE variant_id = ?', [self::KEY, $first]);
        $this->client->disableReboot();
        // The lookup of the key misses the other variant's receipt, as when that receipt commits after the lookup:
        // only the unique index of receipt keys refuses the write.
        self::getContainer()->set(StockItemRepository::class, new class(new DoctrineStockItemRepository($this->connection, new RecordingDomainEventPublisher())) implements StockItemRepository {
            public function __construct(private readonly StockItemRepository $inner)
            {
            }

            public function lockMany(array $ids): array
            {
                return $this->inner->lockMany($ids);
            }

            public function lockOrCreate(\App\Inventory\Domain\Stock\VariantId $id): \App\Inventory\Domain\Stock\StockItem
            {
                return $this->inner->lockOrCreate($id);
            }

            public function findReceipt(ReceiptKey $key): ?StockMovement
            {
                return null;
            }

            public function findMany(array $ids): array
            {
                return $this->inner->findMany($ids);
            }

            public function save(\App\Inventory\Domain\Stock\StockItem $item): void
            {
                $this->inner->save($item);
            }
        });

        $this->receive($second, ['quantity' => 12, 'note' => null]);

        $problem = $this->assertProblem(409);
        self::assertSame('Klucz przyjęcia został już użyty z inną treścią albo dla innego produktu.', $problem['detail']);
        self::assertSame(['physical' => 1, 'reserved' => 0, 'version' => 1], $this->storedStock($second));
        self::assertSame([], $this->movements($second));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function missingOrMalformedKeys(): iterable
    {
        yield 'no header' => [[]];
        yield 'an empty key' => [['HTTP_IDEMPOTENCY_KEY' => '']];
        yield 'a key with a space' => [['HTTP_IDEMPOTENCY_KEY' => 'key 1']];
        yield 'a key with a slash' => [['HTTP_IDEMPOTENCY_KEY' => 'key/1']];
        yield 'a key of 101 characters' => [['HTTP_IDEMPOTENCY_KEY' => str_repeat('k', 101)]];
        yield 'a key with a letter outside ASCII' => [['HTTP_IDEMPOTENCY_KEY' => 'klucz-ż']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('missingOrMalformedKeys')]
    public function testAMissingOrMalformedKeyIsABadRequestAndAddsNothing(array $headers): void
    {
        $variantId = $this->variant();

        $this->post($this->path($variantId), '{"quantity": 5, "note": null}', TestTokenFactory::staff(['admin']), $headers);

        self::assertSame(['Idempotency-Key'], $this->violationPaths(400));
        self::assertNull($this->storedStock($variantId));
    }

    public function testAKeyOfOneHundredAllowedCharactersIsAccepted(): void
    {
        $variantId = $this->variant();

        $this->receive($variantId, ['quantity' => 5, 'note' => null], key: 'Az09._:-'.str_repeat('k', 92));

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function rolesAndStatuses(): iterable
    {
        yield 'admin' => [['admin'], 201];
        yield 'warehouse' => [['warehouse'], 201];
        yield 'support' => [['support'], 403];
        yield 'marketing' => [['marketing'], 403];
        yield 'support and marketing' => [['support', 'marketing'], 403];
        yield 'no staff role' => [[], 403];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesAndStatuses')]
    public function testOnlyAnAdministratorOrTheWarehouseReceivesStock(array $roles, int $status): void
    {
        $variantId = $this->variant();

        $this->receive($variantId, ['quantity' => 5, 'note' => null], TestTokenFactory::staff($roles));

        if (403 === $status) {
            $this->assertProblem(403);
            self::assertNull($this->storedStock($variantId));
        } else {
            self::assertResponseStatusCodeSame($status);
        }
    }

    public function testAuthorizationIsCheckedBeforeTheBodyTheKeyAndTheVariant(): void
    {
        $this->post($this->path(self::UNKNOWN_VARIANT), '{"quantity": "garbage"', TestTokenFactory::staff(['support']));

        $this->assertProblem(403);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $variantId = $this->variant();

        $this->post($this->path($variantId), '{"quantity": 5, "note": null}', null, ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        $this->assertUnauthorized();
        self::assertNull($this->storedStock($variantId));
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $variantId = $this->variant();

        $this->receive($variantId, ['quantity' => 5, 'note' => null], TestTokenFactory::customer());

        $this->assertUnauthorized();
        self::assertNull($this->storedStock($variantId));
    }

    public function testTheIdOfAProductIsNoVariantAndIsNotFound(): void
    {
        [$productId] = $this->productWithVariant();

        $this->receive($productId, ['quantity' => 5, 'note' => null]);

        $problem = $this->assertProblem(404);
        self::assertSame('Wariant produktu nie istnieje.', $problem['detail']);
        self::assertNull($this->storedStock($productId));
    }

    public function testAnUnknownVariantIsNotFoundAndCreatesNothing(): void
    {
        $this->receive(self::UNKNOWN_VARIANT, ['quantity' => 5, 'note' => null]);

        $problem = $this->assertProblem(404);
        self::assertSame('Wariant produktu nie istnieje.', $problem['detail']);
        self::assertNull($this->storedStock(self::UNKNOWN_VARIANT));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsThatAreNotUuids(): iterable
    {
        yield 'a word' => ['shoes'];
        yield 'a uuid with a suffix' => [self::UNKNOWN_VARIANT.'x'];
        yield 'a uuid without dashes' => [str_replace('-', '', self::UNKNOWN_VARIANT)];
    }

    #[DataProvider('idsThatAreNotUuids')]
    public function testAVariantIdThatIsNotAUuidIsNotFound(string $variantId): void
    {
        $this->receive($variantId, ['quantity' => 5, 'note' => null]);

        $this->assertProblem(404);
    }

    public function testAnUppercaseUuidNamesTheSameVariant(): void
    {
        $variantId = $this->variant();

        $stock = $this->receive(strtoupper($variantId), ['quantity' => 5, 'note' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($variantId, $stock['variantId']);
        self::assertSame(['physical' => 5, 'reserved' => 0, 'version' => 1], $this->storedStock($variantId));
    }

    public function testTheActorComesFromTheTokenNeverFromTheBody(): void
    {
        $variantId = $this->variant();

        $this->receive(
            $variantId,
            ['quantity' => 5, 'note' => null, 'actorId' => 'forged-id', 'actorName' => 'Forged Name', 'source' => 'seed'],
            TestTokenFactory::staff(['warehouse'], ['preferred_username' => 'wanda.magazyn']),
        );

        self::assertResponseStatusCodeSame(201);
        $movement = $this->movements($variantId)[0];
        self::assertSame(['staff', TestTokenFactory::STAFF_ID, 'wanda.magazyn'], [$movement['source'], $movement['actor_id'], $movement['actor_name']]);
    }

    public function testAReceiptBeyondOneMillionUnitsIsUnprocessableAndAddsNothing(): void
    {
        $variantId = $this->variant();
        StockFixture::stock($this->connection, 999_999, 0, 3, $variantId);

        $this->receive($variantId, ['quantity' => 2, 'note' => null]);

        $problem = $this->assertProblem(422);
        self::assertSame('Stan fizyczny nie może przekroczyć 1 000 000 szt.', $problem['detail']);
        self::assertSame(['physical' => 999_999, 'reserved' => 0, 'version' => 3], $this->storedStock($variantId));
    }

    public function testTheLimitsOfTheQuantityAndTheNoteAreAccepted(): void
    {
        $variantId = $this->variant();

        $this->receive($variantId, ['quantity' => 1, 'note' => str_repeat('ż', 500)], key: 'a');
        self::assertResponseStatusCodeSame(201);
        $this->receive($variantId, ['quantity' => 100_000, 'note' => '  '.str_repeat('n', 500).'  '], key: 'b');
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['physical' => 100_001, 'reserved' => 0, 'version' => 2], $this->storedStock($variantId));
    }

    public function testANoteOfWhiteSpaceIsNoNote(): void
    {
        $variantId = $this->variant();

        $this->receive($variantId, ['quantity' => 1, 'note' => "  \n "]);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->movements($variantId)[0]['note']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'zero' => ['{"quantity": 0, "note": null}', ['quantity']];
        yield 'negative' => ['{"quantity": -1, "note": null}', ['quantity']];
        yield 'over 100 000' => ['{"quantity": 100001, "note": null}', ['quantity']];
        yield 'beyond the int range' => ['{"quantity": 99999999999999999999, "note": null}', ['quantity']];
        yield 'a fraction' => ['{"quantity": 1.5, "note": null}', ['quantity']];
        yield 'a whole number as a float' => ['{"quantity": 5.0, "note": null}', ['quantity']];
        yield 'a number in a string' => ['{"quantity": "5", "note": null}', ['quantity']];
        yield 'a boolean' => ['{"quantity": true, "note": null}', ['quantity']];
        yield 'null quantity' => ['{"quantity": null, "note": null}', ['quantity']];
        yield 'an array' => ['{"quantity": [5], "note": null}', ['quantity']];
        yield 'no quantity' => ['{"note": null}', ['quantity']];
        yield 'no note' => ['{"quantity": 5}', ['note']];
        yield 'a note that is a number' => ['{"quantity": 5, "note": 12}', ['note']];
        yield 'a note that is an object' => ['{"quantity": 5, "note": {"text": "a"}}', ['note']];
        yield 'a note over 500 characters' => ['{"quantity": 5, "note": "'.str_repeat('n', 501).'"}', ['note']];
        yield 'an empty object' => ['{}', ['quantity', 'note']];
        yield 'an empty body' => ['', ['quantity', 'note']];
        yield 'a list' => ['[5, null]', ['quantity', 'note']];
        yield 'a bare number' => ['5', ['quantity', 'note']];
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('rejectedBodies')]
    public function testAnInvalidBodyIsUnprocessableAndAddsNothing(string $body, array $paths): void
    {
        $variantId = $this->variant();

        $this->post($this->path($variantId), $body, TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        self::assertEqualsCanonicalizing($paths, $this->violationPaths());
        self::assertNull($this->storedStock($variantId));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function omittedBodyKeys(): iterable
    {
        return self::omittedKeys(['quantity', 'note']);
    }

    /**
     * @param list<string> $omitted
     */
    #[DataProvider('omittedBodyKeys')]
    public function testAnOmittedKeyIsOnePolishRequiredViolationAtThatKey(array $omitted): void
    {
        $variantId = $this->variant();

        $problem = $this->post($this->path($variantId), json_encode(self::bodyWithout(['quantity' => 5, 'note' => null], $omitted), \JSON_THROW_ON_ERROR), TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        self::assertSame(422, $problem['status'] ?? null);
        self::assertRequiredAt($omitted, $problem['violations'] ?? null);
        self::assertNull($this->storedStock($variantId));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}> the changed keys, the key of the violation and its message
     */
    public static function nullsAndWrongTypes(): iterable
    {
        $quantity = 'Ilość musi być liczbą całkowitą.';
        $note = 'Notatka musi być tekstem.';

        yield 'a null quantity' => [['quantity' => null], 'quantity', 'Ilość jest wymagana.'];
        yield 'a quantity in a string' => [['quantity' => '5'], 'quantity', $quantity];
        yield 'a fractional quantity' => [['quantity' => 5.5], 'quantity', $quantity];
        yield 'a boolean quantity' => [['quantity' => true], 'quantity', $quantity];
        yield 'a quantity that is a list' => [['quantity' => [5]], 'quantity', $quantity];
        yield 'a quantity that is an object' => [['quantity' => new \stdClass()], 'quantity', $quantity];
        yield 'a note that is a number' => [['note' => 12], 'note', $note];
        yield 'a note that is a boolean' => [['note' => false], 'note', $note];
        yield 'a note that is a list' => [['note' => ['a']], 'note', $note];
        yield 'a note that is an object' => [['note' => new \stdClass()], 'note', $note];
    }

    /**
     * @param array<string, mixed> $changed
     */
    #[DataProvider('nullsAndWrongTypes')]
    public function testANullOrAWrongTypeIsExactlyOnePolishViolationAtItsKey(array $changed, string $key, string $message): void
    {
        $variantId = $this->variant();

        $problem = $this->receive($variantId, [...['quantity' => 5, 'note' => null], ...$changed]);

        self::assertSame(422, $problem['status'] ?? null);
        self::assertSame([[$key, $message]], self::pathsWithTitles($problem['violations'] ?? null));
        self::assertNull($this->storedStock($variantId));
    }

    public function testTheViolationsOfTheRangesAreInPolish(): void
    {
        $variantId = $this->variant();

        $this->receive($variantId, ['quantity' => 0, 'note' => str_repeat('n', 501)]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(
            ['quantity' => 'Ilość musi wynosić od 1 do 100 000 szt.', 'note' => 'Notatka może mieć najwyżej 500 znaków.'],
            array_column($problem['violations'], 'title', 'propertyPath'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notesWithControlCharacters(): iterable
    {
        yield 'NUL inside' => ["a\0b"];
        yield 'NUL at the end' => ["a\0"];
        yield 'escape' => ["a\x1Bb"];
        yield 'delete' => ["a\x7Fb"];
    }

    /**
     * PostgreSQL's driver would cut the note at NUL without a word, so the history would not keep what was sent.
     */
    #[DataProvider('notesWithControlCharacters')]
    public function testANoteWithAControlCharacterIsUnprocessableAndChangesNothing(string $note): void
    {
        $variantId = $this->variant();

        $this->receive($variantId, ['quantity' => 5, 'note' => $note]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(['note' => 'Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).'], array_column($problem['violations'], 'title', 'propertyPath'));
        self::assertNull($this->storedStock($variantId));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $variantId = $this->variant();

        $this->post($this->path($variantId), '{"quantity": 5,', TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        $this->assertProblem(400);
        self::assertNull($this->storedStock($variantId));
    }

    public function testAFormBodyIsNotAccepted(): void
    {
        $variantId = $this->variant();

        $this->post($this->path($variantId), 'quantity=5', TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => self::KEY], 'application/x-www-form-urlencoded');

        $this->assertProblem(415);
        self::assertNull($this->storedStock($variantId));
    }

    private function path(string $variantId): string
    {
        return '/api/v1/admin/variants/'.$variantId.'/stock/receipts';
    }

    /**
     * Receives stock with the key as a staff member (an administrator unless the token says otherwise).
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function receive(string $variantId, array $body, ?string $token = null, string $key = self::KEY): array
    {
        return $this->post($this->path($variantId), json_encode($body, \JSON_THROW_ON_ERROR), $token ?? TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => $key]);
    }
}
