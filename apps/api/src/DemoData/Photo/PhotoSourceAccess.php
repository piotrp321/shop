<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The DEMO_PHOTOS_* variables of the read access to the private bucket of the photo pool, as far as the step needs to
 * know them: which are empty. The values are not kept at all (the S3 client of the pool takes them straight from the
 * environment), so no message, dump or stack trace of this object can repeat one.
 */
final readonly class PhotoSourceAccess
{
    /** @var list<string> */
    private array $missing;

    public function __construct(
        #[Autowire('%env(DEMO_PHOTOS_ENDPOINT)%'), \SensitiveParameter]
        string $endpoint,
        #[Autowire('%env(DEMO_PHOTOS_BUCKET)%'), \SensitiveParameter]
        string $bucket,
        #[Autowire('%env(DEMO_PHOTOS_ACCESS_KEY_ID)%'), \SensitiveParameter]
        string $accessKeyId,
        #[Autowire('%env(DEMO_PHOTOS_SECRET_ACCESS_KEY)%'), \SensitiveParameter]
        string $secretAccessKey,
    ) {
        $this->missing = array_keys(array_filter([
            'DEMO_PHOTOS_ENDPOINT' => $endpoint,
            'DEMO_PHOTOS_BUCKET' => $bucket,
            'DEMO_PHOTOS_ACCESS_KEY_ID' => $accessKeyId,
            'DEMO_PHOTOS_SECRET_ACCESS_KEY' => $secretAccessKey,
        ], static fn (string $value): bool => '' === trim($value)));
    }

    /**
     * @return list<string> the names of the variables that are empty (or only spaces)
     */
    public function missing(): array
    {
        return $this->missing;
    }
}
