<?php

declare(strict_types=1);

namespace App\DemoData\Profile;

use App\DemoData\Time\Anchor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the context of a step from the options of its command: the profile (counts and seed of profiles.yaml, which
 * the options may override) and the anchor (by default the start of the current UTC day).
 */
final readonly class RunContextFactory
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/demo-data/profiles.yaml')]
        private string $profilesFile,
    ) {
    }

    /**
     * @throws \InvalidArgumentException for an unknown profile, a seed that is not a UUID, a count below 1 or an anchor that is not a date
     */
    public function create(string $profile, ?string $seed, ?int $products, ?string $anchor): RunContext
    {
        $profiles = Profiles::fromFile($this->profilesFile);
        if (null !== $seed) {
            $profiles = $profiles->withNamespace($seed);
        }
        $selected = $profiles->get($profile);
        if (null !== $products) {
            if ($products < 1) {
                throw new \InvalidArgumentException('--products has to be at least 1.');
            }
            $selected = $selected->withProducts($products);
        }

        return new RunContext($selected, $profiles->namespace, $profiles->partSize, Anchor::resolve($anchor, new \DateTimeImmutable()));
    }
}
