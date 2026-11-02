<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/admin/products/{productId}/stock/corrections on PostgreSQL (shop_test, rolled back after each test).
 */
final class CorrectStockEndpointTest extends WebTestCase
{
    use Factories;
    use StockEndpointTestTrait;

    private const string UNKNOWN_PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testSetsTheCountedStockAndAnswersWithIt(): void
    {
        $productId = $this->stockedProduct(10, 3, 4);

        $stock = $this->correct($productId, ['physical' => 7, 'version' => 4, 'reason' => 'damage', 'note' => ' Zalane ']);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['productId' => $productId, 'physical' => 7, 'reserved' => 3, 'available' => 4, 'level' => 'low_stock', 'version' => 5],
            array_diff_key($stock, ['updatedAt' => 1]),
        );
        self::assertUtcTimestamp($stock);
        $this->assertKeysMatchSchema($stock, 'StockStateResponse');
        $this->assertPrivateAndNotStored();
        self::assertSame(['physical' => 7, 'reserved' => 3, 'version' => 5], $this->storedStock($productId));
        self::assertSame(
            [['type' => 'correction', 'physical_delta' => -3, 'physical_after' => 7, 'reason' => 'damage', 'note' => 'Zalane', 'reference' => null, 'source' => 'staff', 'actor_id' => TestTokenFactory::STAFF_ID, 'actor_name' => 'admin']],
            $this->movements($productId),
        );
    }

    public function testTheFirstCorrectionOfAProductWithoutStockCreatesIt(): void
    {
        $productId = $this->product();

        $stock = $this->correct($productId, ['physical' => 4, 'version' => null, 'reason' => 'inventory_count', 'note' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([4, 0, 4, 'low_stock', 1], [$stock['physical'], $stock['reserved'], $stock['available'], $stock['level'], $stock['version']]);
        self::assertSame(['physical' => 4, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
        self::assertCount(1, $this->movements($productId));
    }

    public function testTheCurrentValueChangesNeitherTheVersionNorTheHistory(): void
    {
        $productId = $this->stockedProduct(10, 3, 4);

        $stock = $this->correct($productId, ['physical' => 10, 'version' => 4, 'reason' => 'inventory_count', 'note' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([10, 3, 7, 'in_stock', 4], [$stock['physical'], $stock['reserved'], $stock['available'], $stock['level'], $stock['version']]);
        self::assertUtcTimestamp($stock);
        self::assertSame(['physical' => 10, 'reserved' => 3, 'version' => 4], $this->storedStock($productId));
        self::assertSame([], $this->movements($productId));
    }

    public function testAnEmptyStoredStockKeepsItsVersionWhenCountedAtZeroAndIsThenCorrectedFromIt(): void
    {
        $productId = $this->product();
        // The record that CreateEmptyStock stores: 0/0 at version 0, without a history entry.
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (product_id, physical, reserved, version, updated_at) VALUES (?, 0, 0, 0, now())',
            [$productId],
        );

        $stock = $this->correct($productId, ['physical' => 0, 'version' => 0, 'reason' => 'loss', 'note' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([0, 0, 0, 'out_of_stock', 0], [$stock['physical'], $stock['reserved'], $stock['available'], $stock['level'], $stock['version']]);
        self::assertUtcTimestamp($stock);
        self::assertSame([], $this->movements($productId));

        $stock = $this->correct($productId, ['physical' => 5, 'version' => $stock['version'], 'reason' => 'inventory_count', 'note' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([5, 1], [$stock['physical'], $stock['version']]);
        self::assertSame(['physical' => 5, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
    }

    public function testCountingNothingForAProductWithoutStockStoresNothing(): void
    {
        $productId = $this->product();

        $stock = $this->correct($productId, ['physical' => 0, 'version' => null, 'reason' => 'loss', 'note' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['productId' => $productId, 'physical' => 0, 'reserved' => 0, 'available' => 0, 'level' => 'out_of_stock', 'version' => null, 'updatedAt' => null],
            $stock,
        );
        self::assertNull($this->storedStock($productId));
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function staleVersions(): iterable
    {
        yield 'null for a stored stock' => [null];
        yield 'an older version' => [3];
        yield 'a newer version' => [5];
    }

    #[DataProvider('staleVersions')]
    public function testAStaleVersionIsAConflictAndOverwritesNothing(?int $version): void
    {
        $productId = $this->stockedProduct(10, 0, 4);

        $this->correct($productId, ['physical' => 8, 'version' => $version, 'reason' => 'loss', 'note' => null]);

        $problem = $this->assertProblem(409);
        self::assertSame('The stock was modified concurrently. Reload it and try again.', $problem['detail']);
        self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 4], $this->storedStock($productId));
        self::assertSame([], $this->movements($productId));
    }

    public function testRepeatingASuccessfulCorrectionIsAConflict(): void
    {
        $productId = $this->stockedProduct(10, 0, 4);
        $body = ['physical' => 8, 'version' => 4, 'reason' => 'loss', 'note' => null];
        $this->correct($productId, $body);
        self::assertResponseStatusCodeSame(200);

        $this->correct($productId, $body);

        $this->assertProblem(409);
        self::assertSame(['physical' => 8, 'reserved' => 0, 'version' => 5], $this->storedStock($productId));
        self::assertCount(1, $this->movements($productId));
    }

    public function testAVersionForAProductWithoutStockIsAConflict(): void
    {
        $productId = $this->product();

        $this->correct($productId, ['physical' => 5, 'version' => 1, 'reason' => 'inventory_count', 'note' => null]);

        $this->assertProblem(409);
        self::assertNull($this->storedStock($productId));
    }

    public function testCountingLessThanIsReservedIsAConflictNamingTheReservedQuantity(): void
    {
        $productId = $this->stockedProduct(10, 6, 2);

        $this->correct($productId, ['physical' => 5, 'version' => 2, 'reason' => 'loss', 'note' => null]);

        $problem = $this->assertProblem(409);
        self::assertSame('Stan fizyczny nie może być niższy niż zarezerwowany (6 szt.).', $problem['detail']);
        self::assertSame(['physical' => 10, 'reserved' => 6, 'version' => 2], $this->storedStock($productId));
        self::assertSame([], $this->movements($productId));
    }

    public function testAnotherReasonWithANoteIsAccepted(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->correct($productId, ['physical' => 9, 'version' => 1, 'reason' => 'other', 'note' => 'Zwrot do dostawcy']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['other', 'Zwrot do dostawcy'], [$this->movements($productId)[0]['reason'], $this->movements($productId)[0]['note']]);
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function rolesAndStatuses(): iterable
    {
        yield 'admin' => [['admin'], 200];
        yield 'warehouse' => [['warehouse'], 200];
        yield 'support' => [['support'], 403];
        yield 'marketing' => [['marketing'], 403];
        yield 'support and marketing' => [['support', 'marketing'], 403];
        yield 'no staff role' => [[], 403];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesAndStatuses')]
    public function testOnlyAnAdministratorOrTheWarehouseCorrectsStock(array $roles, int $status): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->correct($productId, ['physical' => 8, 'version' => 1, 'reason' => 'loss', 'note' => null], TestTokenFactory::staff($roles));

        if (403 === $status) {
            $this->assertProblem(403);
            self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
        } else {
            self::assertResponseStatusCodeSame($status);
        }
    }

    public function testAuthorizationIsCheckedBeforeTheBodyAndTheProduct(): void
    {
        $this->post($this->path(self::UNKNOWN_PRODUCT), '{"physical": "garbage"}', TestTokenFactory::staff(['marketing']));

        $this->assertProblem(403);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->post($this->path($productId), '{"physical": 8, "version": 1, "reason": "loss", "note": null}', null);

        $this->assertUnauthorized();
        self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->correct($productId, ['physical' => 8, 'version' => 1, 'reason' => 'loss', 'note' => null], TestTokenFactory::customer());

        $this->assertUnauthorized();
        self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
    }

    public function testAnUnknownProductIsNotFound(): void
    {
        $this->correct(self::UNKNOWN_PRODUCT, ['physical' => 5, 'version' => null, 'reason' => 'inventory_count', 'note' => null]);

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
        $this->correct($productId, ['physical' => 5, 'version' => null, 'reason' => 'inventory_count', 'note' => null]);

        $this->assertProblem(404);
    }

    public function testTheActorComesFromTheTokenNeverFromTheBody(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->correct(
            $productId,
            ['physical' => 8, 'version' => 1, 'reason' => 'loss', 'note' => null, 'actorId' => 'forged-id', 'actorName' => 'Forged Name', 'source' => 'seed'],
            TestTokenFactory::staff(['warehouse'], ['preferred_username' => 'wanda.magazyn']),
        );

        self::assertResponseStatusCodeSame(200);
        $movement = $this->movements($productId)[0];
        self::assertSame(['staff', TestTokenFactory::STAFF_ID, 'wanda.magazyn'], [$movement['source'], $movement['actor_id'], $movement['actor_name']]);
    }

    public function testTheLimitsOfTheCountAreAccepted(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->correct($productId, ['physical' => 1_000_000, 'version' => 1, 'reason' => 'inventory_count', 'note' => null]);
        self::assertResponseStatusCodeSame(200);
        $this->correct($productId, ['physical' => 0, 'version' => 2, 'reason' => 'loss', 'note' => null]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['physical' => 0, 'reserved' => 0, 'version' => 3], $this->storedStock($productId));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'a negative count' => ['{"physical": -1, "version": 1, "reason": "loss", "note": null}', ['physical']];
        yield 'over one million' => ['{"physical": 1000001, "version": 1, "reason": "loss", "note": null}', ['physical']];
        yield 'a fractional count' => ['{"physical": 1.5, "version": 1, "reason": "loss", "note": null}', ['physical']];
        yield 'a whole count as a float' => ['{"physical": 5.0, "version": 1, "reason": "loss", "note": null}', ['physical']];
        yield 'a count in a string' => ['{"physical": "5", "version": 1, "reason": "loss", "note": null}', ['physical']];
        yield 'a null count' => ['{"physical": null, "version": 1, "reason": "loss", "note": null}', ['physical']];
        yield 'no count' => ['{"version": 1, "reason": "loss", "note": null}', ['physical']];
        yield 'a version in a string' => ['{"physical": 5, "version": "1", "reason": "loss", "note": null}', ['version']];
        yield 'a fractional version' => ['{"physical": 5, "version": 1.5, "reason": "loss", "note": null}', ['version']];
        yield 'a boolean version' => ['{"physical": 5, "version": false, "reason": "loss", "note": null}', ['version']];
        yield 'no version' => ['{"physical": 5, "reason": "loss", "note": null}', ['version']];
        yield 'an unknown reason' => ['{"physical": 5, "version": 1, "reason": "theft", "note": null}', ['reason']];
        yield 'a reason in another letter case' => ['{"physical": 5, "version": 1, "reason": "LOSS", "note": null}', ['reason']];
        yield 'a null reason' => ['{"physical": 5, "version": 1, "reason": null, "note": null}', ['reason']];
        yield 'a reason that is a number' => ['{"physical": 5, "version": 1, "reason": 1, "note": null}', ['reason']];
        yield 'no reason' => ['{"physical": 5, "version": 1, "note": null}', ['reason']];
        yield 'another reason without a note' => ['{"physical": 5, "version": 1, "reason": "other", "note": null}', ['note']];
        yield 'another reason with a blank note' => ['{"physical": 5, "version": 1, "reason": "other", "note": "   "}', ['note']];
        yield 'a note over 500 characters' => ['{"physical": 5, "version": 1, "reason": "loss", "note": "'.str_repeat('n', 501).'"}', ['note']];
        yield 'a note that is a number' => ['{"physical": 5, "version": 1, "reason": "loss", "note": 5}', ['note']];
        yield 'no note' => ['{"physical": 5, "version": 1, "reason": "loss"}', ['note']];
        yield 'an empty object' => ['{}', ['physical', 'version', 'reason', 'note']];
        yield 'an empty body' => ['', ['physical', 'version', 'reason', 'note']];
        yield 'a list' => ['[5, 1, "loss", null]', ['physical', 'version', 'reason', 'note']];
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('rejectedBodies')]
    public function testAnInvalidBodyIsUnprocessableAndChangesNothing(string $body, array $paths): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->post($this->path($productId), $body, TestTokenFactory::staff(['admin']));

        self::assertEqualsCanonicalizing($paths, $this->violationPaths());
        self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
        self::assertSame([], $this->movements($productId));
    }

    public function testTheViolationsShownAtTheFieldsAreInPolish(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->correct($productId, ['physical' => -1, 'version' => 1, 'reason' => 'theft', 'note' => str_repeat('n', 501)]);
        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(
            [
                'physical' => 'Stan policzony musi wynosić od 0 do 1 000 000 szt.',
                'reason' => 'Wybierz powód korekty: inwentaryzacja, uszkodzenie, zaginięcie albo inne.',
                'note' => 'Notatka może mieć najwyżej 500 znaków.',
            ],
            array_column($problem['violations'], 'title', 'propertyPath'),
        );

        $this->correct($productId, ['physical' => 5, 'version' => 1, 'reason' => 'other', 'note' => null]);
        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(['note' => 'Przy powodzie „inne” notatka jest wymagana.'], array_column($problem['violations'], 'title', 'propertyPath'));
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
        $productId = $this->stockedProduct(10, 0, 1);

        $this->correct($productId, ['physical' => 8, 'version' => 1, 'reason' => 'other', 'note' => $note]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(['note' => 'Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).'], array_column($problem['violations'], 'title', 'propertyPath'));
        self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
        self::assertSame([], $this->movements($productId));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->post($this->path($productId), '{"physical": 5,', TestTokenFactory::staff(['admin']));

        $this->assertProblem(400);
        self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
    }

    public function testAFormBodyIsNotAccepted(): void
    {
        $productId = $this->stockedProduct(10, 0, 1);

        $this->post($this->path($productId), 'physical=5&version=1&reason=loss', TestTokenFactory::staff(['admin']), contentType: 'application/x-www-form-urlencoded');

        $this->assertProblem(415);
        self::assertSame(['physical' => 10, 'reserved' => 0, 'version' => 1], $this->storedStock($productId));
    }

    private function stockedProduct(int $physical, int $reserved, int $version): string
    {
        return StockFixture::stock($this->connection, $physical, $reserved, $version, $this->product());
    }

    private function path(string $productId): string
    {
        return '/api/v1/admin/products/'.$productId.'/stock/corrections';
    }

    /**
     * Corrects the stock as a staff member (an administrator unless the token says otherwise).
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function correct(string $productId, array $body, ?string $token = null): array
    {
        return $this->post($this->path($productId), json_encode($body, \JSON_THROW_ON_ERROR), $token ?? TestTokenFactory::staff(['admin']));
    }
}
