<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Tests\Support\InMemory\FixedCountProductIndexer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

final class SeedProdGuardTest extends SeedCommandTestCase
{
    public function testProdWithoutForceResetFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([10]), 'prod');

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
        self::assertSame(0, $this->statistics->refreshes);
    }

    public function testProdWithForceResetResetsAndSeeds(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([10]), 'prod');

        $exitCode = $tester->execute(['--force-reset' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
        self::assertNotSame([], $this->bus->dispatched);
    }

    public function testTheStatisticsOfTheProductsAreRefreshedOnceAfterTheLoad(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([10]));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(1, $this->statistics->refreshes);
    }

    public function testNonProdResetsWithoutTheFlag(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([10]), 'dev');

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string}> the key of the second template (so the first products are valid), its new value and what the message names
     */
    public static function wrongTextsOfAProduct(): iterable
    {
        yield 'repeated parameter name' => ['parameters', [['name' => 'Waga', 'value' => '1 kg'], ['name' => 'WAGA', 'value' => '2 kg']], 'ProductParameters must have different names'];
        yield 'parameter named like an axis' => ['parameters', [['name' => 'Kolor', 'value' => 'żółty']], 'The parameter "Kolor" is named like a variant axis'];
        yield 'over-long description' => ['description', [str_repeat('x', 5001)], 'ProductDescription must have at most 5000'];
    }

    /**
     * @param list<mixed> $value
     */
    #[DataProvider('wrongTextsOfAProduct')]
    public function testWrongTextOfAProductFailsBeforeAnyChangeAndNamesTheProduct(string $key, array $value, string $expected): void
    {
        $data = Yaml::parseFile(__DIR__.'/../../../Fixtures/demo/catalog.yaml');
        self::assertIsArray($data);
        $templates = $data['templates'] ?? null;
        self::assertIsArray($templates);
        self::assertIsArray($templates[1]);
        $templates[1][$key] = $value;
        $data['templates'] = $templates;
        $file = tempnam(sys_get_temp_dir(), 'demo-catalog-');
        self::assertIsString($file);
        file_put_contents($file, Yaml::dump($data, 8, 2));

        try {
            $tester = $this->tester(new FixedCountProductIndexer([10]), definition: $file);
            $exitCode = $tester->execute([]);
        } finally {
            @unlink($file);
        }

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('product "plecak-tarvik-zab-20" is invalid: '.$expected, $display);
        self::assertStringContainsString('Nothing was changed', $display);
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testInvalidDefinitionFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(
            new FixedCountProductIndexer([10]),
            definition: __DIR__.'/../../../Fixtures/demo/invalid-tax-class.yaml',
        );

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('reduced', $tester->getDisplay());
        self::assertStringContainsString('Nothing was changed', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }
}
