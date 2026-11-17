<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

/**
 * Tar archives made by hand for the tests of the photo pool, including the ones no honest tool writes (a symlink, a
 * broken checksum, a cut-off file).
 */
final class TarBuilder
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $entries the output of entry()
     */
    public static function archive(array $entries, bool $terminated = true): string
    {
        return implode('', $entries).($terminated ? str_repeat("\0", 1024) : '');
    }

    public static function entry(string $name, string $contents, string $type = '0', bool $validChecksum = true, ?int $declaredSize = null): string
    {
        $size = $declaredSize ?? \strlen($contents);
        $header = str_pad($name, 100, "\0")
            .'0000644'."\0".'0000000'."\0".'0000000'."\0"
            .\sprintf('%011o', $size)."\0"
            .'00000000000'."\0"
            .'        '
            .$type
            .str_pad('', 100, "\0")
            ."ustar\0".'00'
            .str_pad('', 32, "\0").str_pad('', 32, "\0").str_pad('', 8, "\0").str_pad('', 8, "\0")
            .str_pad('', 155, "\0").str_pad('', 12, "\0");
        $checksum = $validChecksum ? array_sum(array_map('ord', str_split($header))) : 1;
        $header = substr_replace($header, \sprintf('%06o', $checksum)."\0 ", 148, 8);

        return $header.$contents.str_repeat("\0", (512 - \strlen($contents) % 512) % 512);
    }
}
