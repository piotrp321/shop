<?php

declare(strict_types=1);

namespace App\DemoData\Profile;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The profiles of fixtures/demo-data/profiles.yaml, with the namespace of the seed and the size of a part shared by
 * all of them, so profiles with the same counts give the same rows. A part is a contiguous range of indexes of
 * `partSize` items (the last one may be shorter); the number of processes never changes the parts.
 */
final readonly class Profiles
{
    /**
     * @param array<string, DemoProfile> $profiles
     */
    private function __construct(
        public string $namespace,
        public int $partSize,
        private array $profiles,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the file is missing or malformed
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('The profiles file "%s" does not exist.', $path));
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The profiles file "%s" is not valid YAML: %s', $path, $e->getMessage()), 0, $e);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('The profiles file has to be a map.');
        }

        $namespace = $data['namespace'] ?? null;
        if (!\is_string($namespace) || !Uuid::isValid($namespace)) {
            throw new \InvalidArgumentException('The profiles file needs a "namespace" that is a UUID.');
        }
        $partSize = $data['partSize'] ?? null;
        if (!\is_int($partSize) || $partSize < 1) {
            throw new \InvalidArgumentException('The profiles file needs a positive integer "partSize".');
        }
        $rows = $data['profiles'] ?? null;
        if (!\is_array($rows) || [] === $rows) {
            throw new \InvalidArgumentException('The profiles file needs "profiles".');
        }

        $profiles = [];
        foreach ($rows as $name => $row) {
            if (!\is_string($name) || !\is_array($row)) {
                throw new \InvalidArgumentException('Every profile is a map under its name.');
            }
            $profiles[$name] = new DemoProfile(
                $name,
                self::count($row, $name, 'staff'),
                self::count($row, $name, 'customers'),
                self::count($row, $name, 'products'),
                self::count($row, $name, 'orders'),
                self::count($row, $name, 'diskPeakGb'),
            );
        }

        return new self(Uuid::fromString($namespace)->toRfc4122(), $partSize, $profiles);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * @throws \InvalidArgumentException for an unknown name
     */
    public function get(string $name): DemoProfile
    {
        return $this->profiles[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown profile "%s"; known: %s.', $name, implode(', ', $this->names())));
    }

    public function withNamespace(string $namespace): self
    {
        if (!Uuid::isValid($namespace)) {
            throw new \InvalidArgumentException(\sprintf('The seed "%s" is not a UUID.', $namespace));
        }

        return new self(Uuid::fromString($namespace)->toRfc4122(), $this->partSize, $this->profiles);
    }

    public function partCount(int $total): int
    {
        return intdiv($total + $this->partSize - 1, $this->partSize);
    }

    /**
     * @return array{int, int} the first index of the part and its length
     */
    public function partRange(int $part, int $total): array
    {
        $first = $part * $this->partSize;

        return [$first, max(0, min($this->partSize, $total - $first))];
    }

    /**
     * @param array<mixed> $row
     */
    private static function count(array $row, string $profile, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_int($value) || $value < 0) {
            throw new \InvalidArgumentException(\sprintf('The profile "%s" needs a non-negative integer "%s".', $profile, $key));
        }

        return $value;
    }
}
