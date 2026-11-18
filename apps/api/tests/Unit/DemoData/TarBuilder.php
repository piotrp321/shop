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
        return self::header($name, $declaredSize ?? \strlen($contents), $type, $validChecksum).$contents.self::padding(\strlen($contents));
    }

    /**
     * Appends the entry of a file to an open stream (an archive being written) without holding the contents in memory.
     *
     * @param resource $stream
     */
    public static function appendFile($stream, string $name, string $path): void
    {
        $size = filesize($path);
        $file = fopen($path, 'rb');
        if (false === $size || false === $file) {
            throw new \RuntimeException('The file cannot be read: '.$path);
        }
        fwrite($stream, self::header($name, $size));
        stream_copy_to_stream($file, $stream);
        fclose($file);
        fwrite($stream, self::padding($size));
    }

    /**
     * Writes the end of an archive (two empty blocks) to an open stream.
     *
     * @param resource $stream
     */
    public static function terminate($stream): void
    {
        fwrite($stream, str_repeat("\0", 1024));
    }

    private static function header(string $name, int $size, string $type = '0', bool $validChecksum = true): string
    {
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

        return substr_replace($header, \sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    private static function padding(int $length): string
    {
        return str_repeat("\0", (512 - $length % 512) % 512);
    }
}
