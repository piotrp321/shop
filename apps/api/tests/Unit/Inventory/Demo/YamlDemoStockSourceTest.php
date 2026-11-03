<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Demo;

use App\Inventory\Infrastructure\Demo\YamlDemoStockSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YamlDemoStockSourceTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testLoadsPatternsInFileOrder(): void
    {
        $list = new YamlDemoStockSource($this->write(<<<'YAML'
            # comment
            "przewodnik-*-tatry-zachodnie-*": 0
            "przewodnik-*": 35
            YAML))->load();

        self::assertSame(0, $list->quantityFor('przewodnik-norvane-atlas-tatry-zachodnie-oprawa-miekka'));
        self::assertSame(35, $list->quantityFor('przewodnik-norvane-atlas-bieszczady-oprawa-miekka'));
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsRejected(string $contents, string $message): void
    {
        $source = new YamlDemoStockSource($this->write($contents));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $source->load();
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'not YAML' => ["\"przewodnik-*\": [35\n", 'not valid YAML'];
        yield 'empty' => ['', 'mapping'];
        yield 'a list' => ["- 35\n", 'mapping'];
        yield 'quantity as a fraction' => ["\"przewodnik-*\": 3.5\n", 'integer'];
        yield 'quantity as text' => ["\"przewodnik-*\": \"35\"\n", 'integer'];
        yield 'duplicate pattern' => ["\"przewodnik-*\": 35\n\"przewodnik-*\": 40\n", 'not valid YAML'];
        yield 'invalid pattern' => ["\"*\": 35\n", 'letter or digit'];
        yield 'negative quantity' => ["\"przewodnik-*\": -1\n", 'przewodnik-*'];
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        new YamlDemoStockSource('/nonexistent/stock.yaml')->load();
    }

    private function write(string $contents): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'stock');
        file_put_contents($this->file, $contents);

        return $this->file;
    }
}
