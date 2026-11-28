<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The refresh interval of a product index comes from SEARCH_REFRESH_INTERVAL of the environment (spec:
 * docs/features/search/spec.md, M4 and "Infrastruktura"): 1s without the variable, a number with ms or s otherwise, and
 * a change reaches the index that exists with catalog:search-index:setup, without a rebuild.
 */
final class SearchRefreshIntervalConfigurationTest extends KernelTestCase
{
    private const string VARIABLE = 'SEARCH_REFRESH_INTERVAL';

    private string|false $before = false;

    protected function setUp(): void
    {
        $this->before = getenv(self::VARIABLE);
        $this->useVariable(null);
        $this->maintenance()->recreate();
    }

    protected function tearDown(): void
    {
        $this->useVariable(null);
        $this->maintenance()->recreate();
        false === $this->before ? putenv(self::VARIABLE) : putenv(self::VARIABLE.'='.$this->before);

        parent::tearDown();
    }

    public function testTheIntervalIsOneSecondWithoutTheVariable(): void
    {
        self::assertSame('1s', self::getContainer()->getParameter('app.search.refresh_interval'));
    }

    public function testTheVariableSetsTheInterval(): void
    {
        $this->useVariable('5s');

        self::assertSame('5s', self::getContainer()->getParameter('app.search.refresh_interval'));
    }

    public function testAChangeOfTheVariableReachesTheIndexThatExistsWithSetupAndWithoutARebuild(): void
    {
        $index = $this->maintenance()->activeIndex();
        self::assertSame('1s', $this->refreshInterval($index));

        $this->useVariable('5s');
        $changed = $this->maintenance();
        $changed->setup();

        self::assertSame($index, $changed->activeIndex(), 'The index was rebuilt.');
        self::assertSame('5s', $this->refreshInterval($index));
    }

    #[DataProvider('valuesOutsideTheFormat')]
    public function testTheContainerRefusesAValueOutsideTheFormat(string $value): void
    {
        $this->useVariable($value);

        try {
            self::getContainer()->get(SearchIndexMaintenance::class);
            self::fail('The container accepted '.var_export($value, true).'.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(self::VARIABLE, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesOutsideTheFormat(): iterable
    {
        yield 'empty' => [''];
        yield 'no unit' => ['5'];
        yield 'disabled' => ['-1'];
        yield 'minutes' => ['1m'];
        yield 'fraction' => ['1.5s'];
        yield 'text' => ['often'];
    }

    /**
     * A new kernel, so the container reads the variable as it is now.
     */
    private function maintenance(): SearchIndexMaintenance
    {
        self::ensureKernelShutdown();
        $maintenance = self::getContainer()->get(SearchIndexMaintenance::class);
        self::assertInstanceOf(SearchIndexMaintenance::class, $maintenance);

        return $maintenance;
    }

    private function useVariable(?string $value): void
    {
        self::ensureKernelShutdown();
        if (null === $value) {
            unset($_SERVER[self::VARIABLE], $_ENV[self::VARIABLE]);
            putenv(self::VARIABLE);

            return;
        }
        $_SERVER[self::VARIABLE] = $value;
    }

    private function refreshInterval(string $index): ?string
    {
        $url = $_SERVER['ELASTICSEARCH_URL'] ?? getenv('ELASTICSEARCH_URL');
        \assert(\is_string($url));
        $response = new ElasticsearchClientFactory($url, 1.0)->create(5.0)->indices()->getSettings(['index' => $index]);
        \assert($response instanceof Elasticsearch);
        $settings = $response->asArray();
        \assert(\is_array($settings[$index]) && \is_array($settings[$index]['settings']) && \is_array($settings[$index]['settings']['index']));
        $value = $settings[$index]['settings']['index']['refresh_interval'] ?? null;
        \assert(null === $value || \is_string($value));

        return $value;
    }
}
