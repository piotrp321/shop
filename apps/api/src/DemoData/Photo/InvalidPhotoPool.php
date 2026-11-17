<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

/**
 * The photos of the pool (the directory or the archive) do not match the manifest or break a rule. The violations
 * start with the name of the file and say what is wrong; they never hold the contents of a file or a credential.
 */
final class InvalidPhotoPool extends \RuntimeException
{
    /**
     * @param non-empty-list<string> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(\sprintf("The photo pool is not valid:\n- %s", implode("\n- ", $violations)));
    }

    public static function because(string $violation): self
    {
        return new self([$violation]);
    }

    /**
     * A name that came from outside, safe to put in a message: a plain file name as the manifest allows, else a note.
     */
    public static function nameOf(string $name): string
    {
        return 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $name) ? $name : '(an entry with an unusual name)';
    }
}
