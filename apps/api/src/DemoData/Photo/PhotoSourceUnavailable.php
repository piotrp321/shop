<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

/**
 * The archive of the pool could not be read from the private bucket. The message names the kind of the failure, never
 * the message of the exception of the client (it can hold the endpoint, the bucket or a key).
 */
final class PhotoSourceUnavailable extends \RuntimeException
{
    public static function because(\Throwable $failure, string $key): self
    {
        return new self(\sprintf(
            'The photo archive "%s" could not be read from the private bucket (%s). Check the DEMO_PHOTOS_* variables and that the archive named in the manifest was uploaded. Nothing was changed.',
            $key,
            new \ReflectionClass($failure)->getShortName(),
        ));
    }

    public static function missing(string $key): self
    {
        return new self(\sprintf('The photo archive "%s" is not in the private bucket: upload it with bin/upload-demo-photos. Nothing was changed.', $key));
    }
}
