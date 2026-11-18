<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

/**
 * Checks a directory of photos against the manifest: exactly the files of the manifest (none missing, none extra), the
 * SHA-256 of each, and the rules of the upload of Catalog. Every problem is reported, one violation per file and rule.
 * A file is read one at a time, so the pool is never held in memory.
 */
final readonly class PhotoPoolVerifier
{
    public function __construct(private PhotoRules $rules)
    {
    }

    /**
     * @throws InvalidPhotoPool
     */
    public function verify(string $directory, PhotoManifest $manifest): void
    {
        if (!is_dir($directory)) {
            throw InvalidPhotoPool::because('The directory of the photos is not a directory or cannot be read.');
        }

        $violations = [];
        $present = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        // The names are keys, so a lookup does not scan the list for each of the thousands of files.
        $inManifest = array_flip($manifest->fileNames());
        $inDirectory = array_flip($present);
        foreach ($present as $name) {
            if (!isset($inManifest[$name])) {
                $violations[] = InvalidPhotoPool::nameOf($name).': not in the manifest';
            }
        }
        foreach ($manifest->files as $entry) {
            $path = rtrim($directory, '/').'/'.$entry->file;
            if (!isset($inDirectory[$entry->file])) {
                $violations[] = $entry->file.': missing';
                continue;
            }
            if (!is_file($path)) {
                $violations[] = $entry->file.': not a regular file';
                continue;
            }
            if (hash_file('sha256', $path) !== $entry->sha256) {
                $violations[] = $entry->file.': the SHA-256 does not match the manifest';
                continue;
            }
            $violation = $this->rules->violationOfFile($path);
            if (null !== $violation) {
                $violations[] = $entry->file.': '.$violation;
            }
        }

        if ([] !== $violations) {
            throw new InvalidPhotoPool($violations);
        }
    }
}
