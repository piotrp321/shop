<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Infrastructure\Demo\YamlDemoPriceSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YamlDemoPriceSourceTest extends TestCase
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
        $list = new YamlDemoPriceSource($this->write(<<<'YAML'
            # comment
            "przewodnik-*-tatry-zachodnie-*": 5490
            "przewodnik-*": 3990
            YAML))->load();

        self::assertSame(5490, $list->grossFor('przewodnik-norvane-atlas-tatry-zachodnie-oprawa-miekka'));
        self::assertSame(3990, $list->grossFor('przewodnik-norvane-atlas-bieszczady-oprawa-miekka'));
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsRejected(string $contents, string $message): void
    {
        $source = new YamlDemoPriceSource($this->write($contents));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $source->load();
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'not YAML' => ["\"przewodnik-*\": [5490\n", 'not valid YAML'];
        yield 'empty' => ['', 'mapping'];
        yield 'a list' => ["- 5490\n", 'mapping'];
        yield 'price in zloty' => ["\"przewodnik-*\": 54.90\n", 'grosze'];
        yield 'price as text' => ["\"przewodnik-*\": \"5490\"\n", 'grosze'];
        yield 'duplicate pattern' => ["\"przewodnik-*\": 5490\n\"przewodnik-*\": 3990\n", 'not valid YAML'];
        yield 'invalid pattern' => ["\"*\": 5490\n", 'letter or digit'];
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        new YamlDemoPriceSource('/nonexistent/prices.yaml')->load();
    }

    private function write(string $contents): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'prices');
        file_put_contents($this->file, $contents);

        return $this->file;
    }
}
