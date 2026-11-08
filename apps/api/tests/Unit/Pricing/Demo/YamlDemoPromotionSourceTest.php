<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Infrastructure\Demo\YamlDemoPromotionSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YamlDemoPromotionSourceTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testLoadsThePromotionsInFileOrder(): void
    {
        $list = new YamlDemoPromotionSource($this->write(<<<'YAML'
            # comment
            - name: "Namioty"
              percentOff: 20
              days: 30
              variants: ["namiot-*"]
            - name: "Żele"
              percentOff: 15
              days: 14
              variants:
                - "zel-energetyczny-*-start/*"
                - "zel-energetyczny-*-dystans/*"
            YAML))->load();

        [$tents, $gels] = $list->promotions();
        self::assertSame(['Namioty', 20, 30], [$tents->name, $tents->percentOff, $tents->days]);
        self::assertSame(['Żele', 15, 14], [$gels->name, $gels->percentOff, $gels->days]);
        self::assertTrue($gels->matches('zel-energetyczny-pyrko-dystans/standard'));
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsRejected(string $contents, string $message): void
    {
        $source = new YamlDemoPromotionSource($this->write($contents));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $source->load();
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'not YAML' => ["- name: [Namioty\n", 'not valid YAML'];
        yield 'empty' => ['', 'list'];
        yield 'a mapping' => ["namiot-*: 20\n", 'list'];
        yield 'an entry that is no mapping' => ["- namiot\n", 'mapping'];
        yield 'missing name' => ["- percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n", 'name'];
        yield 'percent as text' => ["- name: N\n  percentOff: \"20\"\n  days: 30\n  variants: [\"namiot-*\"]\n", 'percentOff'];
        yield 'days as text' => ["- name: N\n  percentOff: 20\n  days: soon\n  variants: [\"namiot-*\"]\n", 'days'];
        yield 'variants as text' => ["- name: N\n  percentOff: 20\n  days: 30\n  variants: \"namiot-*\"\n", 'variants'];
        yield 'a pattern that is no text' => ["- name: N\n  percentOff: 20\n  days: 30\n  variants: [5]\n", 'variants'];
        yield 'unknown key' => ["- name: N\n  percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n  colour: red\n", 'colour'];
        yield 'invalid pattern' => ["- name: N\n  percentOff: 20\n  days: 30\n  variants: [\"*\"]\n", 'letter or digit'];
        yield 'invalid percent' => ["- name: Namioty\n  percentOff: 95\n  days: 30\n  variants: [\"namiot-*\"]\n", 'Namioty'];
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        new YamlDemoPromotionSource(sys_get_temp_dir().'/no-such-promotions.yaml')->load();
    }

    private function write(string $contents): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'promotions') ?: throw new \RuntimeException('No temporary file.');
        file_put_contents($this->file, $contents);

        return $this->file;
    }
}
