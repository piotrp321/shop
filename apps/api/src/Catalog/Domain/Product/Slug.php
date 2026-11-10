<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class Slug
{
    public const int MAX_LENGTH = 200;
    private const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';
    private const array TRANSLITERATION = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
    ];

    public function __construct(private string $value)
    {
        if (\strlen($value) > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('Slug is invalid.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The text in the form of a slug: lower case, Polish letters without diacritics, every other run of characters
     * that are not letters or digits as one dash, no dash at either end. Empty when the text has no letter or digit.
     */
    public static function slugify(string $text): string
    {
        $ascii = strtr(mb_strtolower($text), self::TRANSLITERATION);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }
}
