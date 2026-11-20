<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Demo;

use App\Delivery\Application\Demo\DemoDeliveryMethod;
use App\Delivery\Infrastructure\Demo\YamlDemoDeliveryMethodSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The file of the demo delivery methods: the real one is valid, and a broken one is refused with a message before the
 * seed changes anything.
 */
final class YamlDemoDeliveryMethodSourceTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testTheRealFileHasTheTwoCouriersOfTheSpecification(): void
    {
        $methods = new YamlDemoDeliveryMethodSource(\dirname(__DIR__, 4).'/fixtures/delivery-methods.yaml')->load()->methods();

        self::assertEquals(
            [
                new DemoDeliveryMethod('Kurier standardowy', 'Dostawa w 2–3 dni robocze', 1999, 29_900, 10, 'https://tracking.example.com/{numer}'),
                new DemoDeliveryMethod('Kurier ekspresowy', 'Dostawa następnego dnia roboczego', 3999, 99_900, 20, null),
            ],
            $methods,
        );
    }

    public function testOnlyTheNameThePriceAndThePositionAreRequired(): void
    {
        self::assertEquals([new DemoDeliveryMethod('Kurier', null, 0, null, 0, null)], $this->load("- name: Kurier\n  priceGross: 0\n  position: 0\n")->methods());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'not YAML' => ["- name: [Kurier\n", 'not valid YAML'];
        yield 'empty' => ['', 'must be a list'];
        yield 'a mapping' => ["name: Kurier\n", 'must be a list'];
        yield 'a list of texts' => ["- Kurier\n", 'must be a list'];
        yield 'an unknown key' => ["- name: Kurier\n  priceGross: 1\n  position: 1\n  active: false\n", 'unknown key "active"'];
        yield 'no name' => ["- priceGross: 1\n  position: 1\n", 'needs a text name'];
        yield 'a name that is a number' => ["- name: 12\n  priceGross: 1\n  position: 1\n", 'needs a text name'];
        yield 'no price' => ["- name: Kurier\n  position: 1\n", 'priceGross of the demo delivery method "Kurier" must be an integer'];
        yield 'a price in a text' => ["- name: Kurier\n  priceGross: \"1999\"\n  position: 1\n", 'priceGross of the demo delivery method "Kurier" must be an integer'];
        yield 'a fractional threshold' => ["- name: Kurier\n  priceGross: 1\n  freeFromGross: 1.5\n  position: 1\n", 'freeFromGross of the demo delivery method "Kurier" must be an integer'];
        yield 'a description that is a number' => ["- name: Kurier\n  description: 5\n  priceGross: 1\n  position: 1\n", 'description of the demo delivery method "Kurier" must be a text'];
        yield 'a price out of its range' => ["- name: Kurier\n  priceGross: -1\n  position: 1\n", 'is invalid at priceGross'];
        yield 'an http template' => ["- name: Kurier\n  priceGross: 1\n  position: 1\n  trackingUrlTemplate: \"http://x.pl/{numer}\"\n", 'is invalid at trackingUrlTemplate'];
        yield 'a name repeated in another case' => ["- name: Kurier\n  priceGross: 1\n  position: 1\n- name: KURIER\n  priceGross: 1\n  position: 2\n", 'listed twice'];
        yield 'an empty list' => ['[]', 'must be a list'];
        yield 'twenty methods are enough' => [self::many(21), 'at most 20'];
    }

    #[DataProvider('brokenFiles')]
    public function testABrokenFileIsRefusedWithItsReason(string $yaml, string $reason): void
    {
        try {
            $this->load($yaml);
            self::fail('The file must be refused.');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString($reason, $refused->getMessage());
        }
    }

    public function testTwentyMethodsAreAccepted(): void
    {
        self::assertCount(20, $this->load(self::many(20))->methods());
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        new YamlDemoDeliveryMethodSource('/nonexistent/delivery-methods.yaml')->load();
    }

    private static function many(int $count): string
    {
        $yaml = '';
        for ($i = 1; $i <= $count; ++$i) {
            $yaml .= \sprintf("- name: Metoda %d\n  priceGross: 1\n  position: %d\n", $i, $i);
        }

        return $yaml;
    }

    private function load(string $yaml): \App\Delivery\Application\Demo\DemoDeliveryMethodList
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'delivery-methods');
        file_put_contents($this->file, $yaml);

        return new YamlDemoDeliveryMethodSource($this->file)->load();
    }
}
