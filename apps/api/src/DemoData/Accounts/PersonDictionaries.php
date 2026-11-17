<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

use App\DemoData\Identity\StaffRoles;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The dictionaries of the people in fixtures/demo-data: first names and surnames by sex with their weights (the format
 * of the GUS lists of the PESEL register, see CREDITS.md), cities with their weights and postal codes, and street
 * names. Each file is read and checked on first use; a missing or malformed file is reported by its name. Names written
 * in capitals, as in the register, are normalised (`NOWAK-KOWALSKA` is `Nowak-Kowalska`).
 *
 * The fingerprint is a hash of the content of the three files (not of their comments), of the rule of the staff roles
 * and of the version of the way a person is drawn: the id of a generated account carries it, so an id always means the
 * same person and role.
 */
final class PersonDictionaries
{
    /** Bump it when the way a person or an account is drawn from the dictionaries changes. */
    private const string GENERATOR_VERSION = 'people-1';

    /** @var array{male: WeightedNames, female: WeightedNames}|null */
    private ?array $firstNames = null;
    /** @var array{male: WeightedNames, female: WeightedNames}|null */
    private ?array $surnames = null;
    /** @var array{cities: non-empty-list<array{name: string, weight: int, postalCodes: non-empty-list<string>}>, streets: non-empty-list<string>}|null */
    private ?array $places = null;
    private ?WeightedNames $cityWeights = null;
    private ?string $fingerprint = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/demo-data')]
        private readonly string $directory,
    ) {
    }

    public function firstNames(bool $female): WeightedNames
    {
        $this->firstNames ??= $this->loadNames('first-names.yaml');

        return $this->firstNames[$female ? 'female' : 'male'];
    }

    public function surnames(bool $female): WeightedNames
    {
        $this->surnames ??= $this->loadNames('surnames.yaml');

        return $this->surnames[$female ? 'female' : 'male'];
    }

    /**
     * The share of women: the weight of the female first names in the weight of all of them.
     */
    public function femaleShare(): float
    {
        $female = $this->firstNames(true)->total;

        return $female / ($female + $this->firstNames(false)->total);
    }

    /**
     * @return non-empty-list<array{name: string, weight: int, postalCodes: non-empty-list<string>}>
     */
    public function cities(): array
    {
        return $this->places()['cities'];
    }

    /**
     * The cities as weighted names, in the order of cities().
     */
    public function cityWeights(): WeightedNames
    {
        if (null === $this->cityWeights) {
            $cities = $this->cities();
            $this->cityWeights = new WeightedNames(array_column($cities, 'name'), array_column($cities, 'weight'));
        }

        return $this->cityWeights;
    }

    /**
     * @return non-empty-list<string>
     */
    public function streets(): array
    {
        return $this->places()['streets'];
    }

    /**
     * 16 hexadecimal characters.
     */
    public function fingerprint(): string
    {
        if (null === $this->fingerprint) {
            $content = [
                'version' => self::GENERATOR_VERSION,
                'roles' => StaffRoles::rule(),
                'firstNames' => [$this->firstNames(false)->weighted(), $this->firstNames(true)->weighted()],
                'surnames' => [$this->surnames(false)->weighted(), $this->surnames(true)->weighted()],
                'places' => $this->places(),
            ];
            $this->fingerprint = substr(hash('sha256', json_encode($content, \JSON_THROW_ON_ERROR)), 0, 16);
        }

        return $this->fingerprint;
    }

    /**
     * @return array{male: WeightedNames, female: WeightedNames}
     */
    private function loadNames(string $file): array
    {
        $data = $this->parse($file);
        $lists = [];
        foreach (['male', 'female'] as $sex) {
            $rows = $data[$sex] ?? null;
            if (!\is_array($rows) || [] === $rows) {
                throw new \InvalidArgumentException(\sprintf('%s needs a non-empty map "%s" of names to weights.', $file, $sex));
            }
            $names = [];
            $weights = [];
            foreach ($rows as $name => $weight) {
                $name = mb_convert_case(trim((string) $name), \MB_CASE_TITLE, 'UTF-8');
                if (1 !== preg_match('/^\p{L}+(?:[ \'-]\p{L}+)*$/Du', $name) || '' === Transliteration::login($name)) {
                    throw new \InvalidArgumentException(\sprintf('%s: "%s" is not a name with a letter of a login.', $file, $name));
                }
                if (!\is_int($weight) || $weight < 1) {
                    throw new \InvalidArgumentException(\sprintf('%s: the weight of "%s" has to be a positive whole number.', $file, $name));
                }
                $names[] = $name;
                $weights[] = $weight;
            }
            $lists[$sex] = new WeightedNames($names, $weights);
        }

        return $lists;
    }

    /**
     * @return array{cities: non-empty-list<array{name: string, weight: int, postalCodes: non-empty-list<string>}>, streets: non-empty-list<string>}
     */
    private function places(): array
    {
        if (null !== $this->places) {
            return $this->places;
        }
        $data = $this->parse('places.yaml');

        $rows = $data['cities'] ?? null;
        if (!\is_array($rows) || [] === $rows) {
            throw new \InvalidArgumentException('places.yaml needs a non-empty map "cities".');
        }
        $cities = [];
        foreach ($rows as $name => $row) {
            $name = (string) $name;
            $weight = \is_array($row) ? ($row['weight'] ?? null) : null;
            $codes = \is_array($row) ? ($row['postalCodes'] ?? null) : null;
            if (!\is_int($weight) || $weight < 1 || !\is_array($codes) || [] === $codes) {
                throw new \InvalidArgumentException(\sprintf('places.yaml: the city "%s" needs a positive "weight" and postal codes.', $name));
            }
            $postalCodes = [];
            foreach ($codes as $code) {
                if (!\is_string($code) || 1 !== preg_match('/^\d{2}-\d{3}$/D', $code)) {
                    throw new \InvalidArgumentException(\sprintf('places.yaml: a postal code of the city "%s" is not NN-NNN.', $name));
                }
                $postalCodes[] = $code;
            }
            $cities[] = ['name' => $name, 'weight' => $weight, 'postalCodes' => $postalCodes];
        }

        $streets = $data['streets'] ?? null;
        if (!\is_array($streets) || [] === $streets || !array_is_list($streets)) {
            throw new \InvalidArgumentException('places.yaml needs a non-empty list "streets".');
        }
        $names = [];
        foreach ($streets as $street) {
            if (!\is_string($street) && !\is_int($street)) {
                throw new \InvalidArgumentException('places.yaml: every street is a name.');
            }
            $names[] = (string) $street;
        }

        return $this->places = ['cities' => $cities, 'streets' => $names];
    }

    /**
     * @return array<mixed>
     */
    private function parse(string $file): array
    {
        $path = $this->directory.'/'.$file;
        if (!is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('The dictionary "%s" does not exist.', $file));
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The dictionary "%s" is not valid YAML: %s', $file, $e->getMessage()), 0, $e);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(\sprintf('The dictionary "%s" has to be a map.', $file));
        }

        return $data;
    }
}
