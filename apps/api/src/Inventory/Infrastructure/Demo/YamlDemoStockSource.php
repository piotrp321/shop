<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Demo;

use App\Inventory\Application\Demo\DemoStockList;
use App\Inventory\Application\Port\DemoStockSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The demo stock from apps/api/fixtures/stock.yaml: a mapping of slug pattern to the number of units on the shelf.
 */
final readonly class YamlDemoStockSource implements DemoStockSource
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/stock.yaml')]
        private string $path,
    ) {
    }

    public function load(): DemoStockList
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \InvalidArgumentException(\sprintf('The demo stock file "%s" does not exist or is not readable.', $this->path));
        }

        try {
            $definition = Yaml::parseFile($this->path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The demo stock file is not valid YAML: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($definition) || [] === $definition) {
            throw new \InvalidArgumentException('The demo stock file must be a mapping of slug patterns to numbers of units.');
        }

        $rules = [];
        foreach ($definition as $pattern => $quantity) {
            if (!\is_string($pattern)) {
                throw new \InvalidArgumentException('The demo stock file must be a mapping of slug patterns to numbers of units.');
            }
            if (!\is_int($quantity)) {
                throw new \InvalidArgumentException(\sprintf('The demo stock of "%s" must be an integer number of units.', $pattern));
            }
            $rules[] = ['pattern' => $pattern, 'quantity' => $quantity];
        }

        return new DemoStockList($rules);
    }
}
