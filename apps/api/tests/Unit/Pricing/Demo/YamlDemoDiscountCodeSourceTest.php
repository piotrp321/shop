<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Infrastructure\Demo\YamlDemoDiscountCodeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YamlDemoDiscountCodeSourceTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testLoadsThePercentAndTheAmountCodesInFileOrder(): void
    {
        $list = new YamlDemoDiscountCodeSource($this->write(<<<'YAML'
            # comment
            - code: "WITAJ10"
              percentOff: 10
              startsInDays: 0
            - code: "MINUS50"
              amountOff: 5000
              minimumOrder: 30000
              startsInDays: 0
            - code: "LATO15"
              percentOff: 15
              startsInDays: -60
              endsInDays: -30
            YAML))->load();

        [$welcome, $minus, $summer] = $list->codes();
        self::assertSame(['WITAJ10', DiscountKind::Percent, 10, null, 0, null], [$welcome->code, $welcome->kind, $welcome->value, $welcome->minimumOrderGross, $welcome->startsInDays, $welcome->endsInDays]);
        self::assertSame(['MINUS50', DiscountKind::Amount, 5000, 30000, 0, null], [$minus->code, $minus->kind, $minus->value, $minus->minimumOrderGross, $minus->startsInDays, $minus->endsInDays]);
        self::assertSame(['LATO15', -60, -30], [$summer->code, $summer->startsInDays, $summer->endsInDays]);
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsRejected(string $contents, string $message): void
    {
        $source = new YamlDemoDiscountCodeSource($this->write($contents));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $source->load();
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'not YAML' => ["- code: [WITAJ10\n", 'not valid YAML'];
        yield 'empty' => ['', 'list'];
        yield 'a mapping' => ["WITAJ10: 10\n", 'list'];
        yield 'an entry that is no mapping' => ["- WITAJ10\n", 'mapping'];
        yield 'missing code' => ["- percentOff: 10\n  startsInDays: 0\n", 'code'];
        yield 'code as a number' => ["- code: 12345\n  percentOff: 10\n  startsInDays: 0\n", 'code'];
        yield 'neither percent nor amount' => ["- code: WITAJ10\n  startsInDays: 0\n", 'percentOff'];
        yield 'both percent and amount' => ["- code: WITAJ10\n  percentOff: 10\n  amountOff: 5000\n  minimumOrder: 6000\n  startsInDays: 0\n", 'percentOff'];
        yield 'percent as text' => ["- code: WITAJ10\n  percentOff: \"10\"\n  startsInDays: 0\n", 'percentOff'];
        yield 'amount as text' => ["- code: MINUS50\n  amountOff: lots\n  minimumOrder: 30000\n  startsInDays: 0\n", 'amountOff'];
        yield 'minimum as text' => ["- code: MINUS50\n  amountOff: 5000\n  minimumOrder: big\n  startsInDays: 0\n", 'minimumOrder'];
        yield 'missing start' => ["- code: WITAJ10\n  percentOff: 10\n", 'startsInDays'];
        yield 'start as text' => ["- code: WITAJ10\n  percentOff: 10\n  startsInDays: now\n", 'startsInDays'];
        yield 'end as text' => ["- code: WITAJ10\n  percentOff: 10\n  startsInDays: 0\n  endsInDays: never\n", 'endsInDays'];
        yield 'unknown key' => ["- code: WITAJ10\n  percentOff: 10\n  startsInDays: 0\n  colour: red\n", 'colour'];
        yield 'invalid code' => ["- code: \"ZŁY KOD\"\n  percentOff: 10\n  startsInDays: 0\n", 'ZŁY KOD'];
        yield 'amount without a minimum' => ["- code: MINUS50\n  amountOff: 5000\n  startsInDays: 0\n", 'MINUS50'];
        yield 'end before start' => ["- code: WITAJ10\n  percentOff: 10\n  startsInDays: 0\n  endsInDays: -1\n", 'WITAJ10'];
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        new YamlDemoDiscountCodeSource(sys_get_temp_dir().'/no-such-discount-codes.yaml')->load();
    }

    private function write(string $contents): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'codes') ?: throw new \RuntimeException('No temporary file.');
        file_put_contents($this->file, $contents);

        return $this->file;
    }
}
