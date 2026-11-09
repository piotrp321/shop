<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Demo;

use App\Pricing\Application\Demo\DemoDiscountCodeList;
use App\Pricing\Application\Port\DemoDiscountCodeSource;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The demo discount codes from apps/api/fixtures/discount-codes.yaml: a list of mappings with `code`, exactly one of
 * `percentOff` and `amountOff` (grosze), `minimumOrder` (grosze, optional), `startsInDays` and `endsInDays` (optional):
 * the days from the moment of the seed, negative in the past.
 */
final readonly class YamlDemoDiscountCodeSource implements DemoDiscountCodeSource
{
    private const string SHAPE = 'The demo discount codes file must be a list of codes, each a mapping of code, percentOff or amountOff, minimumOrder, startsInDays and endsInDays.';
    private const array KEYS = ['code', 'percentOff', 'amountOff', 'minimumOrder', 'startsInDays', 'endsInDays'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/discount-codes.yaml')]
        private string $path,
    ) {
    }

    public function load(): DemoDiscountCodeList
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \InvalidArgumentException(\sprintf('The demo discount codes file "%s" does not exist or is not readable.', $this->path));
        }

        try {
            $definition = Yaml::parseFile($this->path);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('The demo discount codes file is not valid YAML: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($definition) || [] === $definition || !array_is_list($definition)) {
            throw new \InvalidArgumentException(self::SHAPE);
        }

        $codes = [];
        foreach ($definition as $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(self::SHAPE);
            }
            $unknown = array_diff(array_keys($entry), self::KEYS);
            if ([] !== $unknown) {
                throw new \InvalidArgumentException(\sprintf('The demo discount code has an unknown key "%s".', implode('", "', array_map(strval(...), $unknown))));
            }
            $text = $entry['code'] ?? null;
            if (!\is_string($text)) {
                throw new \InvalidArgumentException('Every demo discount code needs a text code.');
            }

            if (\array_key_exists('percentOff', $entry) === \array_key_exists('amountOff', $entry)) {
                throw new \InvalidArgumentException(\sprintf('The demo discount code "%s" needs exactly one of percentOff and amountOff.', $text));
            }
            $kind = \array_key_exists('percentOff', $entry) ? DiscountKind::Percent : DiscountKind::Amount;
            $value = self::integer($entry, DiscountKind::Percent === $kind ? 'percentOff' : 'amountOff', $text);

            $codes[] = [
                'code' => $text,
                'kind' => $kind,
                'value' => $value,
                'minimumOrderGross' => \array_key_exists('minimumOrder', $entry) ? self::integer($entry, 'minimumOrder', $text) : null,
                'startsInDays' => \array_key_exists('startsInDays', $entry) ? self::integer($entry, 'startsInDays', $text) : throw new \InvalidArgumentException(\sprintf('The demo discount code "%s" needs startsInDays.', $text)),
                'endsInDays' => \array_key_exists('endsInDays', $entry) ? self::integer($entry, 'endsInDays', $text) : null,
            ];
        }

        return new DemoDiscountCodeList($codes);
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function integer(array $entry, string $key, string $code): int
    {
        $value = $entry[$key];
        if (!\is_int($value)) {
            throw new \InvalidArgumentException(\sprintf('The %s of the demo discount code "%s" must be an integer.', $key, $code));
        }

        return $value;
    }
}
