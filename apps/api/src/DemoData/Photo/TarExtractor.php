<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

/**
 * Reads the archive of the pool (a flat tar of regular files, as PharData and `tar --format=ustar` write it) and writes
 * the files whose names are expected into a directory. It does not trust the archive: it does not follow names, so a
 * path, a link or an unknown kind of entry is a violation and nothing outside the directory is ever written; an entry
 * larger than the limit is skipped, and every file is copied in blocks, so the pool is never held in memory. Reading
 * the whole archive first and writing only the expected names is also why a bad archive leaves a directory that the
 * verifier then rejects.
 */
final readonly class TarExtractor
{
    private const int BLOCK = 512;

    public function __construct(private int $maxFileBytes)
    {
    }

    /**
     * @param list<string> $names the files to write
     *
     * @throws InvalidPhotoPool when the archive is not a tar of expected regular files
     */
    public function extract(string $archive, string $directory, array $names): void
    {
        $handle = is_file($archive) ? @fopen($archive, 'r') : false;
        if (false === $handle) {
            throw InvalidPhotoPool::because('The archive cannot be read.');
        }

        $violations = [];
        try {
            $seen = [];
            $first = true;
            while (true) {
                $header = $this->read($handle, self::BLOCK);
                if (self::BLOCK !== \strlen($header)) {
                    if ($first || '' !== $header) {
                        $violations[] = $first ? 'The archive is not a tar archive.' : 'The archive is cut off.';
                    }
                    break;
                }
                if (self::BLOCK === strspn($header, "\0")) {
                    break;
                }
                if ($first && 'ustar' !== substr($header, 257, 5)) {
                    $violations[] = 'The archive is not a tar archive.';
                    break;
                }
                $first = false;

                $entry = $this->parse($header);
                if (\is_string($entry)) {
                    $violations[] = $entry;
                    break;
                }
                [$name, $size, $type] = $entry;
                $label = InvalidPhotoPool::nameOf($name);
                $padded = (int) (ceil($size / self::BLOCK) * self::BLOCK);

                if ('5' === $type) {
                    continue;
                }
                if ('0' !== $type && "\0" !== $type) {
                    $violations[] = $label.': only regular files are allowed in the archive';
                    break;
                }
                $problem = match (true) {
                    !\in_array($name, $names, true) => 'not in the manifest',
                    isset($seen[$name]) => 'repeated in the archive',
                    $size > $this->maxFileBytes => \sprintf('larger than %d bytes', $this->maxFileBytes),
                    default => null,
                };
                $seen[$name] = true;
                if (null !== $problem) {
                    $violations[] = $label.': '.$problem;
                }

                if (null === $problem) {
                    $complete = $this->copy($handle, rtrim($directory, '/').'/'.$name, $size) && $this->skip($handle, $padded - $size);
                } else {
                    $complete = $this->skip($handle, $padded);
                }
                if (!$complete) {
                    $violations[] = $label.': the archive is cut off';
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if ([] !== $violations) {
            throw new InvalidPhotoPool(array_values(array_unique($violations)));
        }
    }

    /**
     * @param resource $handle
     */
    private function read($handle, int $length): string
    {
        $data = '';
        while ($length > \strlen($data) && !feof($handle)) {
            $chunk = fread($handle, max(1, $length - \strlen($data)));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @return array{string, int, string}|string the name, the size and the type of the entry, or the violation
     */
    private function parse(string $header): array|string
    {
        $sum = array_sum(array_map('ord', str_split(substr_replace($header, '        ', 148, 8))));
        $recorded = rtrim(substr($header, 148, 8), "\0 ");
        if (1 !== preg_match('/^[0-7]+$/D', $recorded) || octdec($recorded) !== $sum) {
            return 'A header of the archive has a wrong checksum: it is not a tar archive or it is damaged.';
        }
        $sizeField = rtrim(substr($header, 124, 12), "\0 ");
        if (1 !== preg_match('/^[0-7]{1,11}$/D', $sizeField)) {
            return 'A header of the archive has a size that is not supported.';
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");

        return [('' === $prefix ? '' : $prefix.'/').$name, (int) octdec($sizeField), substr($header, 156, 1)];
    }

    /**
     * @param resource $handle
     */
    private function copy($handle, string $path, int $size): bool
    {
        $target = fopen($path, 'w');
        if (false === $target) {
            throw InvalidPhotoPool::because('A file of the archive could not be written to the work directory.');
        }
        try {
            for ($left = $size; $left > 0;) {
                $chunk = $this->read($handle, min($left, 1 << 20));
                if ('' === $chunk) {
                    return false;
                }
                fwrite($target, $chunk);
                $left -= \strlen($chunk);
            }
        } finally {
            fclose($target);
        }

        return true;
    }

    /**
     * @param resource $handle
     */
    private function skip($handle, int $bytes): bool
    {
        if ($bytes <= 0) {
            return true;
        }
        $position = ftell($handle);
        if (false === $position || 0 !== fseek($handle, $bytes, \SEEK_CUR)) {
            return false;
        }

        // fseek beyond the end succeeds: the next read finds the truncation, except for the last entry, checked here.
        return $position + $bytes <= (int) (fstat($handle)['size'] ?? 0);
    }
}
