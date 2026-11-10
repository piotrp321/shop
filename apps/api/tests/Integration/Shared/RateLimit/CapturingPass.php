<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Copies every definition, with the env placeholders of its arguments written back as `%env(NAME)%`.
 */
final class CapturingPass implements CompilerPassInterface
{
    /** @var array<string, Definition> */
    public array $definitions = [];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $copy = clone $definition;
            $arguments = $container->resolveEnvPlaceholders($definition->getArguments(), '%%env(%s)%%');
            \assert(\is_array($arguments));
            $copy->setArguments($arguments);
            $this->definitions[$id] = $copy;
        }
    }
}
