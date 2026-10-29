<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use App\Tests\Support\Search\ResponseDroppingHttpClient;
use PHPUnit\Framework\TestCase;

final class SearchIndexMaintenanceTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private string $current;

    protected function setUp(): void
    {
        $this->maintenance()->setup();
        $this->current = $this->maintenance()->activeIndex();
    }

    public function testActivateSwitchesTheAliasToTheNewIndexAndKeepsThePreviousOne(): void
    {
        $new = $this->maintenance()->createIndex();

        self::assertSame(ActivationResult::Activated, $this->maintenance()->activate($new, $this->current));

        self::assertSame([$new], $this->aliasTargets());
        self::assertTrue($this->indexExists($this->current));
    }

    public function testActivateWhenTheAliasPointsElsewhereIsNotActivatedAndChangesNothing(): void
    {
        $other = $this->maintenance()->createIndex();
        $new = $this->maintenance()->createIndex();

        self::assertSame(ActivationResult::NotActivated, $this->maintenance()->activate($new, $other));

        self::assertSame([$this->current], $this->aliasTargets());
    }

    public function testDroppedResponseOfAnExecutedSwitchIsActivatedAfterReadingTheState(): void
    {
        $new = $this->maintenance()->createIndex();
        $http = ResponseDroppingHttpClient::aliasSwitches(self::clientFactory()->httpClient(2.0));

        self::assertSame(ActivationResult::Activated, $this->maintenance($http)->activate($new, $this->current));

        self::assertSame(1, $http->dropped);
        self::assertSame([$new], $this->aliasTargets());
        self::assertTrue($this->indexExists($new));
    }

    public function testUnreadableStateAfterADroppedResponseIsUnknown(): void
    {
        $new = $this->maintenance()->createIndex();
        $http = ResponseDroppingHttpClient::aliasSwitches(self::clientFactory()->httpClient(2.0), dropEverythingAfter: true);

        self::assertSame(ActivationResult::Unknown, $this->maintenance($http)->activate($new, $this->current));

        self::assertTrue($this->indexExists($new));
        self::assertTrue($this->indexExists($this->current));
    }

    public function testDeleteIndexRefusesTheAliasTarget(): void
    {
        try {
            $this->maintenance()->deleteIndex($this->current);
            self::fail('The alias target was deleted.');
        } catch (SearchIndexError) {
        }

        self::assertSame([$this->current], $this->aliasTargets());
    }

    public function testDeleteIndexRefusesAnIndexOfAnotherAlias(): void
    {
        $this->expectException(SearchIndexError::class);

        $this->maintenance()->deleteIndex('products');
    }

    public function testDeleteIndexDeletesAnIndexTheAliasDoesNotPointTo(): void
    {
        $orphan = $this->maintenance()->createIndex();

        $this->maintenance()->deleteIndex($orphan);

        self::assertFalse($this->indexExists($orphan));
    }

    public function testVerifyAcceptsTheExpectedState(): void
    {
        $new = $this->maintenance()->createIndex();
        $this->maintenance()->write($new, [self::document('0198a1f0-0000-7000-8000-000000000001')]);

        $this->maintenance()->verify($new, 1);

        $this->addToAssertionCount(1);
    }

    public function testVerifyRejectsAnotherDocumentCount(): void
    {
        $new = $this->maintenance()->createIndex();
        $this->maintenance()->write($new, [self::document('0198a1f0-0000-7000-8000-000000000001')]);

        $this->expectException(SearchIndexError::class);
        $this->expectExceptionMessage('documents');

        $this->maintenance()->verify($new, 2);
    }

    public function testVerifyRejectsAnotherDefinitionHash(): void
    {
        $new = $this->maintenance()->createIndex();
        self::esClient()->indices()->putMapping(['index' => $new, 'body' => ['_meta' => ['definition_hash' => 'another']]]);

        $this->expectException(SearchIndexError::class);
        $this->expectExceptionMessage('definition');

        $this->maintenance()->verify($new, 0);
    }

    public function testVerifyRejectsAMissingIndex(): void
    {
        $this->expectException(SearchIndexError::class);

        $this->maintenance()->verify($this->alias().'_missing', 0);
    }

    public function testVerifyAcceptsAnIndexWhosePrimariesAreAssigned(): void
    {
        // Healthy means every primary shard is assigned (yellow or green). A replica cannot be assigned on this
        // single node, so this index stays yellow; a red index needs a lost primary, which a test cannot cause.
        $definition = new ProductIndexDefinition(1, 1);
        $new = $this->maintenance(definition: $definition)->createIndex();

        $this->maintenance(definition: $definition)->verify($new, 0);

        $this->addToAssertionCount(1);
    }

    private static function document(string $id): ProductDocument
    {
        return new ProductDocument($id, 1, 'Name', 'slug-'.$id, '', 'c', ['c'], ['c'], null);
    }
}
