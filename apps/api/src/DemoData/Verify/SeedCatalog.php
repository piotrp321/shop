<?php

declare(strict_types=1);

namespace App\DemoData\Verify;

use App\Catalog\Domain\Product\Slug;
use App\DemoData\Identity\DemoUuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The products of fixtures/catalog.yaml (the seed catalog) by slug and id: the names the seed gives them
 * (`<type> <brand> <model>`) through the same Catalog slug rule, the ids as UUID v5 `product:<slug>` of the namespace of
 * the file. The generator keeps its own slugs apart from them; the verification checks that.
 */
final class SeedCatalog
{
    /** @var array<string, string>|null id by slug */
    private ?array $products = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/catalog.yaml')]
        private readonly string $file,
    ) {
    }

    /**
     * @return array<string, string> the id of every seed product by its slug
     */
    public function products(): array
    {
        return $this->products ??= $this->read();
    }

    /**
     * @return array<string, string>
     */
    private function read(): array
    {
        $data = Yaml::parseFile($this->file);
        if (!\is_array($data) || !\is_string($data['namespace'] ?? null) || !\is_array($data['templates'] ?? null)) {
            throw new \RuntimeException(\sprintf('The seed catalog "%s" has no namespace or templates.', $this->file));
        }
        $namespace = $data['namespace'];

        $products = [];
        foreach ($data['templates'] as $template) {
            if (!\is_array($template) || !\is_string($template['productType'] ?? null) || !\is_array($template['brands'] ?? null) || !\is_array($template['models'] ?? null)) {
                throw new \RuntimeException('A template of the seed catalog is malformed.');
            }
            foreach ($template['brands'] as $brand) {
                foreach ($template['models'] as $model) {
                    if (!\is_string($brand) || !\is_array($model) || !\is_string($model['name'] ?? null)) {
                        throw new \RuntimeException('A brand or model of the seed catalog is malformed.');
                    }
                    $slug = Slug::slugify($template['productType'].' '.$brand.' '.$model['name']);
                    $products[$slug] = DemoUuid::v5($namespace, 'product:'.$slug);
                }
            }
        }

        return $products;
    }
}
