<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Command\PlanPromotion;
use App\Pricing\Application\Command\SetVariantPrice;
use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Application\Demo\DemoPromotionSeeder;
use App\Pricing\Infrastructure\Demo\YamlDemoPriceSource;
use App\Pricing\Infrastructure\Demo\YamlDemoPromotionSource;
use App\Pricing\UI\Console\SeedDemoPricesCommand;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryVariantsForPricing;
use App\Tests\Unit\Pricing\SpyPricingReset;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDemoPricesCommandTest extends TestCase
{
    private const array PRICED = [
        '00000000-0000-4000-8000-000000000001' => 'namiot-turystyczny-norvane-biwak-2-zielony/standard',
        '00000000-0000-4000-8000-000000000002' => 'mapa-turystyczna-tarvik-press-gorce-skladana/standard',
    ];

    private RecordingMessageBus $bus;
    private SpyPricingReset $reset;
    private const string PROMOTIONS = "- name: Namioty\n  percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n";

    private ?string $file = null;
    private ?string $promotionsFile = null;

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->promotionsFile] as $file) {
            if (null !== $file && is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testResetsAndSetsAPriceForEveryVariant(): void
    {
        $tester = $this->tester(self::PRICED);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
        self::assertCount(3, $this->bus->dispatched);
        self::assertStringContainsString('prices: 2', $tester->getDisplay());
        self::assertStringContainsString('promotions: 1', $tester->getDisplay());
    }

    public function testThePromotionsAreSeededAfterAllThePrices(): void
    {
        $tester = $this->tester(self::PRICED);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        self::assertSame(
            [SetVariantPrice::class, SetVariantPrice::class, PlanPromotion::class],
            array_map(static fn (object $command): string => $command::class, $this->bus->dispatched),
        );
    }

    public function testAPromotionPatternWithoutAVariantFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(self::PRICED, promotions: self::PROMOTIONS."- name: Kajaki\n  percentOff: 10\n  days: 30\n  variants: [\"kajak-*\"]\n");

        $exitCode = $tester->execute([]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Kajaki', $display);
        self::assertStringContainsString('Nothing was changed', $display);
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testInvalidPromotionFileFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(self::PRICED, promotions: "- name: Namioty\n  percentOff: 0\n  days: 30\n  variants: [\"namiot-*\"]\n");

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Nothing was changed', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testVariantWithoutPatternFailsBeforeAnyChange(): void
    {
        $tester = $this->tester([
            ...self::PRICED,
            '00000000-0000-4000-8000-000000000003' => 'kajak-turystyczny-vilmar-rzeka-1-zielony/standard',
        ]);

        $exitCode = $tester->execute([]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka-1-zielony/standard', $display);
        self::assertStringContainsString('Nothing was changed', $display);
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testManyVariantsWithoutPatternAreSummarised(): void
    {
        $variants = self::PRICED;
        for ($i = 10; $i < 25; ++$i) {
            $variants[\sprintf('00000000-0000-4000-8000-%012d', $i)] = 'kajak-'.$i.'/standard';
        }
        $tester = $this->tester($variants);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('15 demo variants', $display);
        self::assertStringContainsString('kajak-19/standard', $display);
        self::assertStringNotContainsString('kajak-20/standard', $display);
    }

    public function testInvalidPriceFileFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(self::PRICED, "\"namiot-*\": 0\n");

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Nothing was changed', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testProdWithoutForceResetFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(self::PRICED, environment: 'prod');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testProdWithForceResetSeeds(): void
    {
        $tester = $this->tester(self::PRICED, environment: 'prod');

        self::assertSame(Command::SUCCESS, $tester->execute(['--force-reset' => true]), $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
        self::assertCount(3, $this->bus->dispatched);
    }

    /**
     * @param array<string, string> $variants variant id => key
     */
    private function tester(array $variants, string $prices = "\"namiot-*\": 89900\n\"mapa-*\": 2490\n", string $environment = 'test', string $promotions = self::PROMOTIONS): CommandTester
    {
        $this->file = tempnam(sys_get_temp_dir(), 'prices');
        file_put_contents($this->file, $prices);
        $this->promotionsFile = tempnam(sys_get_temp_dir(), 'promotions');
        file_put_contents($this->promotionsFile, $promotions);
        $this->bus = new RecordingMessageBus();
        $this->reset = new SpyPricingReset();
        $application = new Application();
        $application->addCommand(new SeedDemoPricesCommand(
            new YamlDemoPriceSource($this->file),
            new DemoPriceSeeder(new InMemoryVariantsForPricing($variants), $this->bus),
            new YamlDemoPromotionSource($this->promotionsFile),
            new DemoPromotionSeeder(new InMemoryVariantsForPricing($variants), $this->bus, new FixedPricingClock('2026-11-10T12:00:00Z')),
            $this->reset,
            $environment,
        ));

        return new CommandTester($application->find('pricing:seed-demo'));
    }
}
