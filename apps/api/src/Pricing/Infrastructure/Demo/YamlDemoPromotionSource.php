<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Demo;

use App\Pricing\Application\Demo\DemoPromotionList;
use App\Pricing\Application\Port\DemoPromotionSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The demo promotions from apps/api/fixtures/promotions.yaml: a list of mappings with `name`, `percentOff`, `days`
 * (the length from the moment of the seed) and `variants` (patterns of variant keys, as in fixtures/prices.yaml).
 */
final readonly class YamlDemoPromotionSource implements DemoPromotionSource
{
    private const string SHAPE = 'The demo promotions file must be a list of promotions, each a mapping of name, percentOff, days and variants.';
    private const array KEYS = ['name', 'percentOff', 'days', 'variants'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/promotions.yaml')]
        private string $path,
    ) {
    }

    public function load(): DemoPromotionList
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \InvalidArgumentException(\sprintf('The demo promotions file "%s" does not exist or is not readable.', $this->path));
        }

        try {
            $definition = Yaml::parseFile($this->path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The demo promotions file is not valid YAML: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($definition) || [] === $definition || !array_is_list($definition)) {
            throw new \InvalidArgumentException(self::SHAPE);
        }

        $promotions = [];
        foreach ($definition as $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(self::SHAPE);
            }
            $unknown = array_diff(array_keys($entry), self::KEYS);
            if ([] !== $unknown) {
                throw new \InvalidArgumentException(\sprintf('The demo promotion has an unknown key "%s".', implode('", "', array_map(strval(...), $unknown))));
            }
            $name = $entry['name'] ?? null;
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('Every demo promotion needs a text name.');
            }
            $percentOff = $entry['percentOff'] ?? null;
            if (!\is_int($percentOff)) {
                throw new \InvalidArgumentException(\sprintf('The percentOff of the demo promotion "%s" must be an integer.', $name));
            }
            $days = $entry['days'] ?? null;
            if (!\is_int($days)) {
                throw new \InvalidArgumentException(\sprintf('The days of the demo promotion "%s" must be an integer.', $name));
            }
            $patterns = $entry['variants'] ?? null;
            $strings = [];
            if (\is_array($patterns) && array_is_list($patterns)) {
                foreach ($patterns as $pattern) {
                    if (\is_string($pattern)) {
                        $strings[] = $pattern;
                    }
                }
            }
            if (!\is_array($patterns) || \count($strings) !== \count($patterns)) {
                throw new \InvalidArgumentException(\sprintf('The variants of the demo promotion "%s" must be a list of variant key patterns.', $name));
            }

            $promotions[] = ['name' => $name, 'percentOff' => $percentOff, 'days' => $days, 'patterns' => $strings];
        }

        return new DemoPromotionList($promotions);
    }
}
