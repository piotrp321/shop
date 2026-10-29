<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The demo catalog as written in apps/api/fixtures/catalog.yaml: a category tree and product templates.
 * The expansion into concrete products is DemoCatalogExpander's job.
 */
final readonly class DemoCatalogDefinition
{
    /**
     * @param list<DemoCategoryNode>    $categories
     * @param list<DemoProductTemplate> $templates
     */
    public function __construct(
        public string $namespace,
        public int $imagelessEvery,
        public array $categories,
        public array $templates,
    ) {
        if (!Uuid::isValid($namespace)) {
            throw new \InvalidArgumentException('"namespace" must be a UUID.');
        }
        if ($imagelessEvery < 1) {
            throw new \InvalidArgumentException('"imagelessEvery" must be a positive integer.');
        }
    }

    /**
     * @throws \InvalidArgumentException when the file is missing, is not YAML or does not match the format
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(\sprintf('The demo catalog definition "%s" does not exist or is not readable.', $path));
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The demo catalog definition "%s" is not valid YAML: %s', $path, $e->getMessage()), 0, $e);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(\sprintf('The demo catalog definition "%s" must be a map.', $path));
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $imagelessEvery = $data['imagelessEvery'] ?? null;
        if (!\is_int($imagelessEvery)) {
            throw new \InvalidArgumentException('"imagelessEvery" must be a positive integer.');
        }

        $categories = [];
        foreach (self::list($data, 'categories', 'the definition') as $category) {
            if (!\is_array($category)) {
                throw new \InvalidArgumentException('A category must be a map.');
            }
            $categories[] = DemoCategoryNode::fromArray($category, 'category');
        }
        $templates = [];
        foreach (self::list($data, 'templates', 'the definition') as $position => $template) {
            if (!\is_array($template)) {
                throw new \InvalidArgumentException(\sprintf('Template %s must be a map.', (string) $position));
            }
            $templates[] = DemoProductTemplate::fromArray($template, \sprintf('template %s', (string) $position));
        }

        return new self(self::string($data, 'namespace', 'the definition'), $imagelessEvery, $categories, $templates);
    }

    /**
     * @param array<mixed> $data
     *
     * @internal helper of the definition classes
     */
    public static function string(array $data, string $key, string $where): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" of %s must be a non-empty string.', $key, $where));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     *
     * @internal helper of the definition classes
     */
    public static function list(array $data, string $key, string $where, bool $required = true): array
    {
        $value = $data[$key] ?? null;
        if (null === $value && !$required) {
            return [];
        }
        if (!\is_array($value) || (([] === $value) && $required)) {
            throw new \InvalidArgumentException(\sprintf('"%s" of %s must be a non-empty list.', $key, $where));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return non-empty-list<string>
     *
     * @internal helper of the definition classes
     */
    public static function strings(array $data, string $key, string $where): array
    {
        $strings = [];
        foreach (self::list($data, $key, $where) as $value) {
            if (!\is_string($value) || '' === $value) {
                throw new \InvalidArgumentException(\sprintf('"%s" of %s must hold non-empty strings.', $key, $where));
            }
            $strings[] = $value;
        }
        \assert([] !== $strings);

        return $strings;
    }
}
