<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

/**
 * The manifest of the photo pool breaks its schema. The violations name the place and the rule, never the value (the
 * file might hold something that must not be repeated).
 */
final class InvalidPhotoManifest extends \RuntimeException
{
    /**
     * @param non-empty-list<string> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(\sprintf("The photo manifest is not valid:\n- %s", implode("\n- ", $violations)));
    }

    public static function because(string $violation): self
    {
        return new self([$violation]);
    }
}
