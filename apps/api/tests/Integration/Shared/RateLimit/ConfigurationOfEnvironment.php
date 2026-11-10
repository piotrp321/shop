<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use App\Kernel;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The container of an environment (e.g. `prod`) as it is built from config/, compiled in a temporary directory and
 * never dumped or booted. Definitions are captured before the private ones are inlined or removed, with every env
 * placeholder written back as `%env(NAME)%`.
 */
final class ConfigurationOfEnvironment extends Kernel
{
    private ?CapturingPass $capture = null;

    /**
     * @return array<string, Definition> every definition of the container before the removing passes, by id
     */
    public static function definitions(string $environment): array
    {
        $kernel = new self($environment, false);
        $kernel->initializeBundles();
        $container = $kernel->buildContainer();
        $container->compile();
        \assert(null !== $kernel->capture);

        return $kernel->capture->definitions;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/shop-configuration-'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->capture = new CapturingPass();
        $container->addCompilerPass($this->capture, PassConfig::TYPE_BEFORE_REMOVING, -1000);
    }
}
