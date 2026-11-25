<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

use App\Catalog\Domain\Product\TaxClassCode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The dictionaries of the generator in fixtures/demo-data (categories, product types, parameters, brands, models,
 * descriptions, photo groups), each read and checked on first use; a missing or malformed file is reported by its name.
 */
final class Dictionaries
{
    /** @var list<array{name: string, slug: string, groups: list<array{name: string, slug: string, leaves: list<array{name: string, slug: string}>}>}>|null */
    private ?array $departments = null;
    /** @var list<ProductType>|null */
    private ?array $types = null;
    /** @var array<string, non-empty-list<string>>|null */
    private ?array $brands = null;
    /** @var array{words: non-empty-list<string>, numbers: non-empty-list<int>, suffixes: non-empty-list<array{text: string, weight: int}>}|null */
    private ?array $models = null;
    /** @var array<string, non-empty-list<string>>|null */
    private ?array $closings = null;
    /** @var array<string, non-empty-list<string>>|null */
    private ?array $sentences = null;
    /** @var array<string, non-empty-list<TypeParameter>>|null */
    private ?array $parameterSets = null;
    /** @var array<string, non-empty-list<string>>|null */
    private ?array $photoGroups = null;
    private ?float $defaultSingle = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/demo-data')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<array{name: string, slug: string, groups: list<array{name: string, slug: string, leaves: list<array{name: string, slug: string}>}>}>
     */
    public function departments(): array
    {
        return $this->departments ??= $this->loadDepartments();
    }

    /**
     * @return list<string>
     */
    public function departmentSlugs(): array
    {
        return array_map(static fn (array $department): string => $department['slug'], $this->departments());
    }

    /**
     * @return list<string> the slugs of the groups of a department
     */
    public function groupSlugs(string $department): array
    {
        foreach ($this->departments() as $candidate) {
            if ($candidate['slug'] === $department) {
                return array_map(static fn (array $group): string => $group['slug'], $candidate['groups']);
            }
        }

        throw new \InvalidArgumentException(\sprintf('Unknown department "%s".', $department));
    }

    /**
     * @return list<string> `department/group/leaf` of every leaf category, in the order of the file
     */
    public function leafPaths(): array
    {
        $paths = [];
        foreach ($this->departments() as $department) {
            foreach ($department['groups'] as $group) {
                foreach ($group['leaves'] as $leaf) {
                    $paths[] = $department['slug'].'/'.$group['slug'].'/'.$leaf['slug'];
                }
            }
        }

        return $paths;
    }

    /**
     * @return list<array{name: string, slug: string}> every category of the tree (departments, groups, leaves)
     */
    public function categoryDefinitions(): array
    {
        $definitions = [];
        foreach ($this->departments() as $department) {
            $definitions[] = ['name' => $department['name'], 'slug' => $department['slug']];
            foreach ($department['groups'] as $group) {
                $definitions[] = ['name' => $group['name'], 'slug' => $group['slug']];
                foreach ($group['leaves'] as $leaf) {
                    $definitions[] = $leaf;
                }
            }
        }

        return $definitions;
    }

    /**
     * @return list<ProductType>
     */
    public function types(): array
    {
        return $this->types ??= $this->loadTypes();
    }

    /**
     * @return non-empty-list<string>
     */
    public function brands(string $department): array
    {
        $this->brands ??= $this->loadBrands();
        $brands = $this->brands[$department] ?? [];
        if ([] === $brands) {
            throw new \InvalidArgumentException(\sprintf('No brands for the department "%s".', $department));
        }

        return $brands;
    }

    /**
     * @return non-empty-list<string>
     */
    public function modelWords(): array
    {
        return $this->models()['words'];
    }

    /**
     * @return non-empty-list<int>
     */
    public function modelNumbers(): array
    {
        return $this->models()['numbers'];
    }

    /**
     * @return non-empty-list<array{text: string, weight: int}>
     */
    public function modelSuffixes(): array
    {
        return $this->models()['suffixes'];
    }

    /**
     * @return non-empty-list<string>
     */
    public function closings(string $department): array
    {
        $this->closings ??= $this->loadClosings();
        $closings = $this->closings[$department] ?? [];
        if ([] === $closings) {
            throw new \InvalidArgumentException(\sprintf('No closings for the department "%s".', $department));
        }

        return $closings;
    }

    /**
     * @return non-empty-list<string> the sentences the long descriptions of the department are built from
     */
    public function sentences(string $department): array
    {
        $this->sentences ??= $this->loadSentences();
        $sentences = $this->sentences[$department] ?? [];
        if ([] === $sentences) {
            throw new \InvalidArgumentException(\sprintf('No description sentences for the department "%s".', $department));
        }

        return $sentences;
    }

    /**
     * @return list<string>
     */
    public function photoGroupNames(): array
    {
        return array_keys($this->photoGroups());
    }

    /**
     * @return non-empty-list<string> the files of fixtures/images that serve the group in the profile small
     */
    public function photoFiles(string $group): array
    {
        $files = $this->photoGroups()[$group] ?? [];
        if ([] === $files) {
            throw new \InvalidArgumentException(\sprintf('Unknown photo group "%s".', $group));
        }

        return $files;
    }

    /**
     * @return list<string> every file of a group, once, in the order of the file
     */
    public function allPhotoFiles(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->photoGroups()))));
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    private function photoGroups(): array
    {
        return $this->photoGroups ??= $this->loadPhotoGroups();
    }

    /**
     * @return array{words: non-empty-list<string>, numbers: non-empty-list<int>, suffixes: non-empty-list<array{text: string, weight: int}>}
     */
    private function models(): array
    {
        return $this->models ??= $this->loadModels();
    }

    /**
     * @return list<array{name: string, slug: string, groups: list<array{name: string, slug: string, leaves: list<array{name: string, slug: string}>}>}>
     */
    private function loadDepartments(): array
    {
        $file = 'categories.yaml';
        $departments = [];
        foreach (self::items(self::read($this->directory, $file)['departments'] ?? null, $file, 'departments') as $department) {
            $department = self::map($department, $file, 'a department');
            $groups = [];
            foreach (self::items($department['groups'] ?? null, $file, 'groups') as $group) {
                $group = self::map($group, $file, 'a group');
                $leaves = [];
                foreach (self::items($group['leaves'] ?? null, $file, 'leaves') as $leaf) {
                    $leaf = self::map($leaf, $file, 'a leaf');
                    $leaves[] = ['name' => self::string($leaf, 'name', $file), 'slug' => self::string($leaf, 'slug', $file)];
                }
                $groups[] = ['name' => self::string($group, 'name', $file), 'slug' => self::string($group, 'slug', $file), 'leaves' => $leaves];
            }
            $departments[] = ['name' => self::string($department, 'name', $file), 'slug' => self::string($department, 'slug', $file), 'groups' => $groups];
        }

        return $departments;
    }

    /**
     * @return list<ProductType>
     */
    private function loadTypes(): array
    {
        $file = 'product-types.yaml';
        $data = self::read($this->directory, $file);
        $defaultSingle = $data['defaultSingle'] ?? null;
        if (!\is_int($defaultSingle) && !\is_float($defaultSingle)) {
            throw self::invalid($file, 'defaultSingle has to be a number');
        }
        $this->defaultSingle = (float) $defaultSingle;

        $named = [];
        foreach (self::map($data['axes'] ?? null, $file, 'axes') as $id => $axis) {
            $named[(string) $id] = self::axis($axis, $file);
        }

        $types = [];
        foreach (self::items($data['types'] ?? null, $file, 'types') as $row) {
            $row = self::map($row, $file, 'a type');
            $name = self::string($row, 'name', $file);
            $axes = [];
            foreach (self::items($row['axes'] ?? null, $file, $name.': axes') as $axis) {
                $axes[] = \is_string($axis)
                    ? ($named[$axis] ?? throw self::invalid($file, \sprintf('%s: unknown axis "%s"', $name, $axis)))
                    : self::axis($axis, $file);
            }
            $taxClass = TaxClassCode::tryFrom(self::string($row, 'tax', $file)) ?? throw self::invalid($file, $name.': unknown tax class');
            $price = self::items($row['price'] ?? null, $file, $name.': price');
            if (2 !== \count($price) || !\is_int($price[0]) || !\is_int($price[1])) {
                throw self::invalid($file, $name.': price has to be [min, max] in whole zlotys');
            }
            $weight = $row['weight'] ?? null;
            if (!\is_int($weight) || $weight < 1) {
                throw self::invalid($file, $name.': weight has to be a positive integer');
            }
            $single = $row['single'] ?? $this->defaultSingle;
            if (!\is_int($single) && !\is_float($single)) {
                throw self::invalid($file, $name.': single has to be a number');
            }
            $features = [];
            foreach (self::items($row['features'] ?? null, $file, $name.': features') as $feature) {
                if (!\is_string($feature) || '' === $feature) {
                    throw self::invalid($file, $name.': a feature has to be a text');
                }
                $features[] = $feature;
            }
            $setName = self::string($row, 'parameters', $file);
            $parameters = $this->parameterSets()[$setName] ?? throw self::invalid($file, \sprintf('%s: unknown parameter set "%s"', $name, $setName));
            $axisNames = array_map(static fn (TypeAxis $axis): string => mb_strtolower($axis->name), $axes);
            foreach ($parameters as $parameter) {
                if (\in_array(mb_strtolower($parameter->name), $axisNames, true)) {
                    throw self::invalid($file, \sprintf('%s: the parameter "%s" of the set "%s" is an axis of the variants of the type', $name, $parameter->name, $setName));
                }
            }
            $types[] = new ProductType(
                $name,
                self::string($row, 'leaf', $file),
                $taxClass,
                $axes,
                [] === $axes ? 1.0 : (float) $single,
                $price[0],
                $price[1],
                self::string($row, 'photos', $file),
                $weight,
                $features,
                $parameters,
            );
        }

        return $types;
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    private function loadBrands(): array
    {
        $file = 'brands.yaml';
        $brands = [];
        foreach (self::map(self::read($this->directory, $file)['departments'] ?? null, $file, 'departments') as $department => $names) {
            $brands[(string) $department] = self::strings($names, $file, 'brands of '.$department);
        }

        return $brands;
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    private function loadClosings(): array
    {
        $file = 'descriptions.yaml';
        $closings = [];
        foreach (self::map(self::read($this->directory, $file)['closings'] ?? null, $file, 'closings') as $department => $texts) {
            $closings[(string) $department] = self::strings($texts, $file, 'closings of '.$department);
        }

        return $closings;
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    private function loadSentences(): array
    {
        $file = 'descriptions.yaml';
        $sentences = [];
        foreach (self::map(self::read($this->directory, $file)['sentences'] ?? null, $file, 'sentences') as $department => $texts) {
            $sentences[(string) $department] = self::strings($texts, $file, 'sentences of '.$department);
        }

        return $sentences;
    }

    /**
     * @return array<string, non-empty-list<TypeParameter>>
     */
    private function parameterSets(): array
    {
        return $this->parameterSets ??= $this->loadParameterSets();
    }

    /**
     * @return array<string, non-empty-list<TypeParameter>>
     */
    private function loadParameterSets(): array
    {
        $file = 'parameters.yaml';
        $sets = [];
        foreach (self::map(self::read($this->directory, $file)['sets'] ?? null, $file, 'sets') as $set => $parameters) {
            $list = [];
            foreach (self::map($parameters, $file, 'the set '.$set) as $name => $values) {
                $list[] = new TypeParameter((string) $name, self::strings($values, $file, 'values of '.$name.' in '.$set));
            }
            $sets[(string) $set] = [] === $list ? throw self::invalid($file, 'the set '.$set.' must not be empty') : $list;
        }

        return $sets;
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    private function loadPhotoGroups(): array
    {
        $file = 'photo-groups.yaml';
        $groups = [];
        foreach (self::map(self::read($this->directory, $file)['groups'] ?? null, $file, 'groups') as $group => $files) {
            $groups[(string) $group] = self::strings($files, $file, 'files of '.$group);
        }

        return $groups;
    }

    /**
     * @return array{words: non-empty-list<string>, numbers: non-empty-list<int>, suffixes: non-empty-list<array{text: string, weight: int}>}
     */
    private function loadModels(): array
    {
        $file = 'models.yaml';
        $data = self::read($this->directory, $file);
        $numbers = [];
        foreach (self::items($data['numbers'] ?? null, $file, 'numbers') as $number) {
            if (!\is_int($number)) {
                throw self::invalid($file, 'a number has to be an integer');
            }
            $numbers[] = $number;
        }
        $suffixes = [];
        foreach (self::items($data['suffixes'] ?? null, $file, 'suffixes') as $suffix) {
            $suffix = self::map($suffix, $file, 'a suffix');
            $text = $suffix['text'] ?? null;
            $weight = $suffix['weight'] ?? null;
            if (!\is_string($text) || !\is_int($weight) || $weight < 1) {
                throw self::invalid($file, 'a suffix needs a text and a positive weight');
            }
            $suffixes[] = ['text' => $text, 'weight' => $weight];
        }
        if ([] === $numbers || [] === $suffixes) {
            throw self::invalid($file, 'numbers and suffixes must not be empty');
        }

        return ['words' => self::strings($data['words'] ?? null, $file, 'words'), 'numbers' => $numbers, 'suffixes' => $suffixes];
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $directory, string $file): array
    {
        $path = rtrim($directory, '/').'/'.$file;
        if (!is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('The dictionary %s does not exist (looked in %s).', $file, $directory));
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The dictionary %s is not valid YAML: %s', $file, $e->getMessage()), 0, $e);
        }

        return \is_array($data) ? $data : throw self::invalid($file, 'it has to be a map');
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value, string $file, string $what): array
    {
        return \is_array($value) ? $value : throw self::invalid($file, $what.' has to be a map');
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $value, string $file, string $what): array
    {
        return \is_array($value) && array_is_list($value) ? $value : throw self::invalid($file, $what.' has to be a list');
    }

    /**
     * @param array<mixed> $row
     */
    private static function string(array $row, string $key, string $file): string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : throw self::invalid($file, $key.' has to be a non-empty text');
    }

    /**
     * @return non-empty-list<string>
     */
    private static function strings(mixed $value, string $file, string $what): array
    {
        $texts = [];
        foreach (self::items($value, $file, $what) as $text) {
            if (!\is_string($text) || '' === $text) {
                throw self::invalid($file, $what.' has to be a list of texts');
            }
            $texts[] = $text;
        }

        return [] === $texts ? throw self::invalid($file, $what.' must not be empty') : $texts;
    }

    private static function axis(mixed $axis, string $file): TypeAxis
    {
        $axis = self::map($axis, $file, 'an axis');

        return new TypeAxis(self::string($axis, 'name', $file), self::strings($axis['values'] ?? null, $file, 'values of an axis'));
    }

    private static function invalid(string $file, string $problem): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('The dictionary %s is invalid: %s.', $file, $problem));
    }
}
