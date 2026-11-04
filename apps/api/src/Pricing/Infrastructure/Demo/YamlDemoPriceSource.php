<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Demo;

use App\Pricing\Application\Demo\DemoPriceList;
use App\Pricing\Application\Port\DemoPriceSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The demo prices from apps/api/fixtures/prices.yaml: a mapping of variant key pattern to gross price in grosze.
 */
final readonly class YamlDemoPriceSource implements DemoPriceSource
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/prices.yaml')]
        private string $path,
    ) {
    }

    public function load(): DemoPriceList
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \InvalidArgumentException(\sprintf('The demo prices file "%s" does not exist or is not readable.', $this->path));
        }

        try {
            $definition = Yaml::parseFile($this->path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The demo prices file is not valid YAML: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($definition) || [] === $definition) {
            throw new \InvalidArgumentException('The demo prices file must be a mapping of variant key patterns to gross prices in grosze.');
        }

        $rules = [];
        foreach ($definition as $pattern => $gross) {
            if (!\is_string($pattern)) {
                throw new \InvalidArgumentException('The demo prices file must be a mapping of variant key patterns to gross prices in grosze.');
            }
            if (!\is_int($gross)) {
                throw new \InvalidArgumentException(\sprintf('The demo price of "%s" must be an integer amount in grosze.', $pattern));
            }
            $rules[] = ['pattern' => $pattern, 'gross' => $gross];
        }

        return new DemoPriceList($rules);
    }
}
