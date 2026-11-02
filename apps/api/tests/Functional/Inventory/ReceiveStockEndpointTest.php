<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineStockItemRepository;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/admin/products/{productId}/stock/receipts on PostgreSQL (shop_test, rolled back after each test).
 */
final class ReceiveStockEndpointTest extends WebTestCase
{
    use Factories;
    use StockEndpointTestTrait;

    private const string UNKNOWN_PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string KEY = '6f1b0e4a-3c1d-4c1e-9a7b-2d5e8f9a0b1c';

    public function testTheFirstReceiptCreatesTheStockAndAnswersWithIt(): void
    {
        $productId = $this->product();

        $stock = $this->receive($productId, ['quantity' => 12, 'note' => 'Dostawa od Biegam']);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['productId' => $productId, 'physical' => 12, 'reserved' => 0, 'available' => 12, 'level' => 'in_stock', 'version' => 1],
            array_diff_key($stock, ['updatedAt' => 1]),
        );
        self::assertUtcTimestamp($stock);
        $this->assertKeysMatchSchema($stock, 'StockStateResponse');
        $this->assertPrivateAndNotStored();
        self::assertSame(['physical' => 12, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
        self::assertSame(
            [['type' => 'receipt', 'physical_delta' => 12, 'physical_after' => 12, 'reason' => null, 'note' => 'Dostawa od Biegam', 'reference' => self::KEY, 'source' => 'staff', 'actor_id' => TestTokenFactory::STAFF_ID, 'actor_name' => 'admin']],
            $this->movements($productId),
        );
    }

    public function testAReceiptAddsToTheStoredStock(): void
    {
        $productId = $this->product();
        StockFixture::stock($this->connection, 3, 2, 4, $productId);

        $stock = $this->receive($productId, ['quantity' => 2, 'note' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([5, 2, 3, 'low_stock', 5], [$stock['physical'], $stock['reserved'], $stock['available'], $stock['level'], $stock['version']]);
        self::assertSame(['physical' => 5, 'reserved' => 2, 'version' => 5], $this->storedStock($productId));
    }

    public function testTheSameKeyAndContentAgainIsAnOkWithTheCurrentStockAndAddsNothing(): void
    {
        $productId = $this->product();
        $this->receive($productId, ['quantity' => 12, 'note' => 'Dostawa']);
        self::assertResponseStatusCodeSame(201);
        // Something else changed the stock since.
        $this->connection->executeStatement('UPDATE inventory_stock_item SET reserved = 4, version = 2 WHERE product_id = ?', [$productId]);

        $stock = $this->receive($productId, ['quantity' => 12, 'note' => ' Dostawa ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([12, 4, 8, 2], [$stock['physical'], $stock['reserved'], $stock['available'], $stock['version']]);
        $this->assertKeysMatchSchema($stock, 'StockStateResponse');
        $this->assertPrivateAndNotStored();
        self::assertSame(['physical' => 12, 'reserved' => 4, 'version' => 2], $this->storedStock($productId));
        self::assertCount(1, $this->movements($productId));
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
        $productId = $this->product();
        $this->receive($productId, ['quantity' => 12, 'note' => 'Dostawa']);

        $this->receive($productId, $body);

        $problem = $this->assertProblem(409);
        self::assertSame('Klucz przyjęcia został już użyty z inną treścią albo dla innego produktu.', $problem['detail']);
        self::assertSame(['physical' => 12, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
        self::assertCount(1, $this->movements($productId));
    }

    public function testTheSameKeyForAnotherProductIsAConflictAndCreatesNothing(): void
    {
        $first = $this->product();
        $second = $this->product();
        $this->receive($first, ['quantity' => 12, 'note' => null]);

        $this->receive($second, ['quantity' => 12, 'note' => null]);

        $this->assertProblem(409);
        self::assertNull($this->storedStock($second));
        self::assertSame([], $this->movements($second));
    }

    public function testAKeyTakenByAnotherProductBetweenTheLookupAndTheWriteIsAConflictNotAnError(): void
    {
        $first = $this->product();
        $second = $this->product();
        StockFixture::stock($this->connection, 1, 0, 1, $second);
        // The receipt of the first product with the key, as a committed receipt leaves it.
        StockFixture::stock($this->connection, 12, 0, 1, $first);
        $this->connection->executeStatement('UPDATE inventory_stock_movement SET reference = ? WHERE product_id = ?', [self::KEY, $first]);
        $this->client->disableReboot();
        // The lookup of the key misses the other product's receipt, as when that receipt commits after the lookup:
        // only the unique index of receipt keys refuses the write.
        self::getContainer()->set(StockItemRepository::class, new class(new DoctrineStockItemRepository($this->connection)) implements StockItemRepository {
            public function __construct(private readonly StockItemRepository $inner)
            {
            }

            public function lockMany(array $ids): array
            {
                return $this->inner->lockMany($ids);
            }

            public function lockOrCreate(\App\Inventory\Domain\Stock\ProductId $id): \App\Inventory\Domain\Stock\StockItem
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
        $productId = $this->product();

        $this->post($this->path($productId), '{"quantity": 5, "note": null}', TestTokenFactory::staff(['admin']), $headers);

        self::assertSame(['Idempotency-Key'], $this->violationPaths(400));
        self::assertNull($this->storedStock($productId));
    }

    public function testAKeyOfOneHundredAllowedCharactersIsAccepted(): void
    {
        $productId = $this->product();

        $this->receive($productId, ['quantity' => 5, 'note' => null], key: 'Az09._:-'.str_repeat('k', 92));

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
        $productId = $this->product();

        $this->receive($productId, ['quantity' => 5, 'note' => null], TestTokenFactory::staff($roles));

        if (403 === $status) {
            $this->assertProblem(403);
            self::assertNull($this->storedStock($productId));
        } else {
            self::assertResponseStatusCodeSame($status);
        }
    }

    public function testAuthorizationIsCheckedBeforeTheBodyTheKeyAndTheProduct(): void
    {
        $this->post($this->path(self::UNKNOWN_PRODUCT), '{"quantity": "garbage"', TestTokenFactory::staff(['support']));

        $this->assertProblem(403);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $productId = $this->product();

        $this->post($this->path($productId), '{"quantity": 5, "note": null}', null, ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        $this->assertUnauthorized();
        self::assertNull($this->storedStock($productId));
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $productId = $this->product();

        $this->receive($productId, ['quantity' => 5, 'note' => null], TestTokenFactory::customer());

        $this->assertUnauthorized();
        self::assertNull($this->storedStock($productId));
    }

    public function testAnUnknownProductIsNotFoundAndCreatesNothing(): void
    {
        $this->receive(self::UNKNOWN_PRODUCT, ['quantity' => 5, 'note' => null]);

        $problem = $this->assertProblem(404);
        self::assertSame('Product not found.', $problem['detail']);
        self::assertNull($this->storedStock(self::UNKNOWN_PRODUCT));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsThatAreNotUuids(): iterable
    {
        yield 'a word' => ['shoes'];
        yield 'a uuid with a suffix' => [self::UNKNOWN_PRODUCT.'x'];
        yield 'a uuid without dashes' => [str_replace('-', '', self::UNKNOWN_PRODUCT)];
    }

    #[DataProvider('idsThatAreNotUuids')]
    public function testAProductIdThatIsNotAUuidIsNotFound(string $productId): void
    {
        $this->receive($productId, ['quantity' => 5, 'note' => null]);

        $this->assertProblem(404);
    }

    public function testAnUppercaseUuidNamesTheSameProduct(): void
    {
        $productId = $this->product();

        $stock = $this->receive(strtoupper($productId), ['quantity' => 5, 'note' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($productId, $stock['productId']);
        self::assertSame(['physical' => 5, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
    }

    public function testTheActorComesFromTheTokenNeverFromTheBody(): void
    {
        $productId = $this->product();

        $this->receive(
            $productId,
            ['quantity' => 5, 'note' => null, 'actorId' => 'forged-id', 'actorName' => 'Forged Name', 'source' => 'seed'],
            TestTokenFactory::staff(['warehouse'], ['preferred_username' => 'wanda.magazyn']),
        );

        self::assertResponseStatusCodeSame(201);
        $movement = $this->movements($productId)[0];
        self::assertSame(['staff', TestTokenFactory::STAFF_ID, 'wanda.magazyn'], [$movement['source'], $movement['actor_id'], $movement['actor_name']]);
    }

    public function testAReceiptBeyondOneMillionUnitsIsUnprocessableAndAddsNothing(): void
    {
        $productId = $this->product();
        StockFixture::stock($this->connection, 999_999, 0, 3, $productId);

        $this->receive($productId, ['quantity' => 2, 'note' => null]);

        $problem = $this->assertProblem(422);
        self::assertSame('Stan fizyczny nie może przekroczyć 1 000 000 szt.', $problem['detail']);
        self::assertSame(['physical' => 999_999, 'reserved' => 0, 'version' => 3], $this->storedStock($productId));
    }

    public function testTheLimitsOfTheQuantityAndTheNoteAreAccepted(): void
    {
        $productId = $this->product();

        $this->receive($productId, ['quantity' => 1, 'note' => str_repeat('ż', 500)], key: 'a');
        self::assertResponseStatusCodeSame(201);
        $this->receive($productId, ['quantity' => 100_000, 'note' => '  '.str_repeat('n', 500).'  '], key: 'b');
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['physical' => 100_001, 'reserved' => 0, 'version' => 2], $this->storedStock($productId));
    }

    public function testANoteOfWhiteSpaceIsNoNote(): void
    {
        $productId = $this->product();

        $this->receive($productId, ['quantity' => 1, 'note' => "  \n "]);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->movements($productId)[0]['note']);
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
        $productId = $this->product();

        $this->post($this->path($productId), $body, TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        self::assertEqualsCanonicalizing($paths, $this->violationPaths());
        self::assertNull($this->storedStock($productId));
    }

    public function testTheViolationsOfTheRangesAreInPolish(): void
    {
        $productId = $this->product();

        $this->receive($productId, ['quantity' => 0, 'note' => str_repeat('n', 501)]);

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
        $productId = $this->product();

        $this->receive($productId, ['quantity' => 5, 'note' => $note]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(['note' => 'Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).'], array_column($problem['violations'], 'title', 'propertyPath'));
        self::assertNull($this->storedStock($productId));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $productId = $this->product();

        $this->post($this->path($productId), '{"quantity": 5,', TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        $this->assertProblem(400);
        self::assertNull($this->storedStock($productId));
    }

    public function testAFormBodyIsNotAccepted(): void
    {
        $productId = $this->product();

        $this->post($this->path($productId), 'quantity=5', TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => self::KEY], 'application/x-www-form-urlencoded');

        $this->assertProblem(415);
        self::assertNull($this->storedStock($productId));
    }

    private function path(string $productId): string
    {
        return '/api/v1/admin/products/'.$productId.'/stock/receipts';
    }

    /**
     * Receives stock with the key as a staff member (an administrator unless the token says otherwise).
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function receive(string $productId, array $body, ?string $token = null, string $key = self::KEY): array
    {
        return $this->post($this->path($productId), json_encode($body, \JSON_THROW_ON_ERROR), $token ?? TestTokenFactory::staff(['admin']), ['HTTP_IDEMPOTENCY_KEY' => $key]);
    }
}
