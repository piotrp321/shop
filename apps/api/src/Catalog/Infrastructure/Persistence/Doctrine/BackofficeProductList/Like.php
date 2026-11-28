<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList;

/**
 * Text for the patterns of LIKE and ILIKE.
 */
final class Like
{
    /**
     * Makes `%`, `_` and the escape character `\` (PostgreSQL's default) match themselves.
     */
    public static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /**
     * The pattern of a name that contains the text, whatever its case when used with ILIKE.
     */
    public static function contains(string $text): string
    {
        return '%'.self::escape($text).'%';
    }
}
