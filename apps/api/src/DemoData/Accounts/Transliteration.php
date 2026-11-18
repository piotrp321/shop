<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * The part of a login made of a name: lower case, the Polish letters without their marks, and only the letters a-z and
 * single hyphens inside (a double surname keeps its hyphen; spaces, apostrophes and anything else are dropped), so a
 * login and an e-mail address never carry a Polish character.
 */
final class Transliteration
{
    private const array POLISH = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

    private function __construct()
    {
    }

    public static function login(string $name): string
    {
        $ascii = preg_replace('/[^a-z-]+/', '', strtr(mb_strtolower($name, 'UTF-8'), self::POLISH)) ?? '';

        return trim(preg_replace('/-{2,}/', '-', $ascii) ?? '', '-');
    }
}
