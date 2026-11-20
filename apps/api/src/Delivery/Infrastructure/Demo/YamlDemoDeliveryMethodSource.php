<?php

declare(strict_types=1);

namespace App\Delivery\Infrastructure\Demo;

use App\Delivery\Application\Demo\DemoDeliveryMethod;
use App\Delivery\Application\Demo\DemoDeliveryMethodList;
use App\Delivery\Application\Port\DemoDeliveryMethodSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The demo delivery methods from apps/api/fixtures/delivery-methods.yaml: a list of mappings with `name`,
 * `description` (optional), `priceGross`, `freeFromGross` (optional), `position` and `trackingUrlTemplate` (optional).
 */
final readonly class YamlDemoDeliveryMethodSource implements DemoDeliveryMethodSource
{
    private const string SHAPE = 'The demo delivery methods file must be a list of methods, each a mapping of name, description, priceGross, freeFromGross, position and trackingUrlTemplate.';
    private const array KEYS = ['name', 'description', 'priceGross', 'freeFromGross', 'position', 'trackingUrlTemplate'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/delivery-methods.yaml')]
        private string $path,
    ) {
    }

    public function load(): DemoDeliveryMethodList
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \InvalidArgumentException(\sprintf('The demo delivery methods file "%s" does not exist or is not readable.', $this->path));
        }

        try {
            $definition = Yaml::parseFile($this->path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The demo delivery methods file is not valid YAML: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($definition) || [] === $definition || !array_is_list($definition)) {
            throw new \InvalidArgumentException(self::SHAPE);
        }

        $methods = [];
        foreach ($definition as $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(self::SHAPE);
            }
            $unknown = array_diff(array_keys($entry), self::KEYS);
            if ([] !== $unknown) {
                throw new \InvalidArgumentException(\sprintf('The demo delivery method has an unknown key "%s".', implode('", "', array_map(strval(...), $unknown))));
            }
            $name = $entry['name'] ?? null;
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('Every demo delivery method needs a text name.');
            }

            $methods[] = new DemoDeliveryMethod(
                $name,
                self::optionalText($entry, 'description', $name),
                self::integer($entry, 'priceGross', $name),
                \array_key_exists('freeFromGross', $entry) ? self::integer($entry, 'freeFromGross', $name) : null,
                self::integer($entry, 'position', $name),
                self::optionalText($entry, 'trackingUrlTemplate', $name),
            );
        }

        return new DemoDeliveryMethodList($methods);
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function integer(array $entry, string $key, string $name): int
    {
        $value = $entry[$key] ?? null;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException(\sprintf('The %s of the demo delivery method "%s" must be an integer.', $key, $name));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function optionalText(array $entry, string $key, string $name): ?string
    {
        $value = $entry[$key] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('The %s of the demo delivery method "%s" must be a text.', $key, $name));
        }

        return $value;
    }
}
