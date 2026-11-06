<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The repeated `option` key of GET /api/v1/products, each entry `<axis>:<value>` split on the first colon. PHP's own
 * query parsing keeps only the last value of a repeated key without `[]`, so the raw query string is read here, the way
 * `/api/v1/product-offers` reads `productId`. Collecting stops one entry past the limit, so a long query string is
 * neither validated nor handed on in full. The violation messages never contain the submitted value.
 */
final readonly class OptionFilter
{
    public const string PARAMETER = 'option';
    public const int MAX_ENTRIES = 50;
    public const int MAX_AXES = 5;
    public const int MAX_AXIS_LENGTH = 30;
    public const int MAX_VALUE_LENGTH = 40;

    /**
     * @param list<string> $entries     every `<axis>:<value>` entry sent, at most {@see MAX_ENTRIES} + 1
     * @param bool         $bracketForm whether a key of the `option[]` form was sent
     */
    private function __construct(private array $entries, private bool $bracketForm)
    {
    }

    public static function fromQueryString(string $queryString): self
    {
        $entries = [];
        $bracketForm = false;
        foreach (explode('&', $queryString) as $pair) {
            [$name, $value] = [...explode('=', $pair, 2), ''];
            $name = urldecode($name);
            if (str_starts_with($name, self::PARAMETER.'[')) {
                $bracketForm = true;
            } elseif (self::PARAMETER === $name && \count($entries) <= self::MAX_ENTRIES) {
                $entries[] = urldecode($value);
            }
        }

        return new self($entries, $bracketForm);
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The values of each axis in the order sent, without repeats. Only meaningful for a valid filter.
     *
     * @return array<int|string, list<string>> keyed by axis name (PHP turns a numeric name into an int key)
     */
    public function axes(): array
    {
        $axes = [];
        foreach ($this->entries as $entry) {
            [$axis, $value] = explode(':', $entry, 2) + [1 => ''];
            if (!\in_array($value, $axes[$axis] ?? [], true)) {
                $axes[$axis][] = $value;
            }
        }

        return $axes;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->bracketForm) {
            $context->buildViolation('Send the options as a repeated `option` key, not as `option[]`.')->atPath(self::PARAMETER)->addViolation();
        }
        if (\count($this->entries) > self::MAX_ENTRIES) {
            $context->buildViolation('At most 50 options are accepted.')->atPath(self::PARAMETER)->addViolation();

            return;
        }
        foreach ($this->entries as $entry) {
            if (!mb_check_encoding($entry, 'UTF-8')) {
                $context->buildViolation('An option must be valid UTF-8.')->atPath(self::PARAMETER)->addViolation();

                return;
            }
            if (!str_contains($entry, ':')) {
                $context->buildViolation('An option must have the form `<axis>:<value>`.')->atPath(self::PARAMETER)->addViolation();

                return;
            }
            [$axis, $value] = explode(':', $entry, 2);
            if ($this->lengthOutside($axis, self::MAX_AXIS_LENGTH) || $this->lengthOutside($value, self::MAX_VALUE_LENGTH)) {
                $context->buildViolation('The axis of an option has 1 to 30 characters and its value 1 to 40.')->atPath(self::PARAMETER)->addViolation();

                return;
            }
        }
        if (\count($this->axes()) > self::MAX_AXES) {
            $context->buildViolation('Options of at most 5 different axes are accepted.')->atPath(self::PARAMETER)->addViolation();
        }
    }

    private function lengthOutside(string $text, int $max): bool
    {
        $length = mb_strlen($text);

        return $length < 1 || $length > $max;
    }
}
