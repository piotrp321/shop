<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Validation;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidProductWriteValidator extends ConstraintValidator
{
    private const int MAX_NAME_LENGTH = 200;
    private const int MAX_SLUG_LENGTH = 200;
    private const int MAX_SHORT_DESCRIPTION_LENGTH = 500;
    private const int MAX_ADDITIONAL_CATEGORIES = 10;
    private const int MAX_IMAGES = 10;
    private const int MAX_ALT_LENGTH = 200;

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidProductWrite) {
            throw new UnexpectedTypeException($constraint, ValidProductWrite::class);
        }
        if (!\is_object($value)) {
            throw new UnexpectedTypeException($value, 'object');
        }
        $body = get_object_vars($value);
        $context = $this->context;

        if (\array_key_exists('version', $body)) {
            $version = $body['version'];
            if (!\is_int($version) || $version < 1) {
                self::add($context, 'version', 'Podaj wersję produktu.');
            }
        }

        $this->name($context, $body['name'] ?? null);
        $this->slug($context, $body['slug'] ?? null);
        $this->shortDescription($context, $body['shortDescription'] ?? null);
        $this->categories($context, $body['mainCategoryId'] ?? null, $body['additionalCategoryIds'] ?? null);
        if (!\is_string($body['taxClass'] ?? null) || null === TaxClassCode::tryFrom($body['taxClass'])) {
            self::add($context, 'taxClass', 'Wybierz klasę podatkową.');
        }
        $this->images($context, $body['images'] ?? null);
        $axes = $this->axes($context, $body['variantAxes'] ?? null);
        $this->variants($context, $body['variants'] ?? null, $axes);
    }

    private function name(ExecutionContextInterface $context, mixed $name): void
    {
        if (!\is_string($name) || '' === trim($name)) {
            self::add($context, 'name', 'Podaj nazwę produktu.');
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            self::add($context, 'name', 'Nazwa może mieć najwyżej 200 znaków.');
        }
    }

    private function slug(ExecutionContextInterface $context, mixed $slug): void
    {
        if (!\is_string($slug)) {
            self::add($context, 'slug', 'Slug musi być tekstem.');
        } elseif (mb_strlen($slug) > self::MAX_SLUG_LENGTH) {
            self::add($context, 'slug', 'Slug może mieć najwyżej 200 znaków.');
        } elseif ('' !== $slug && !self::isSlug($slug)) {
            self::add($context, 'slug', 'Slug może zawierać tylko małe litery bez polskich znaków, cyfry i pojedyncze myślniki.');
        }
    }

    private function shortDescription(ExecutionContextInterface $context, mixed $description): void
    {
        if (!\is_string($description)) {
            self::add($context, 'shortDescription', 'Krótki opis musi być tekstem.');
        } elseif (mb_strlen($description) > self::MAX_SHORT_DESCRIPTION_LENGTH) {
            self::add($context, 'shortDescription', 'Krótki opis może mieć najwyżej 500 znaków.');
        }
    }

    private function categories(ExecutionContextInterface $context, mixed $main, mixed $additional): void
    {
        $mainId = \is_string($main) ? self::categoryId($main) : null;
        if (null === $mainId) {
            self::add($context, 'mainCategoryId', 'Wybierz kategorię główną.');
        }

        if (!\is_array($additional) || !array_is_list($additional)) {
            self::add($context, 'additionalCategoryIds', 'Kategorie dodatkowe muszą być listą.');

            return;
        }
        if (\count($additional) > self::MAX_ADDITIONAL_CATEGORIES) {
            self::add($context, 'additionalCategoryIds', 'Produkt może mieć najwyżej 10 kategorii dodatkowych.');

            return;
        }
        $seen = [];
        foreach ($additional as $index => $category) {
            $id = \is_string($category) ? self::categoryId($category) : null;
            if (null === $id) {
                self::add($context, \sprintf('additionalCategoryIds[%d]', $index), 'Wybierz poprawną kategorię.');
            } elseif ($id === $mainId) {
                self::add($context, \sprintf('additionalCategoryIds[%d]', $index), 'Kategoria główna nie może być też dodatkowa.');
            } elseif (isset($seen[$id])) {
                self::add($context, \sprintf('additionalCategoryIds[%d]', $index), 'Ta kategoria już jest wybrana.');
            }
            if (null !== $id) {
                $seen[$id] = true;
            }
        }
    }

    private function images(ExecutionContextInterface $context, mixed $images): void
    {
        if (!\is_array($images) || !array_is_list($images)) {
            self::add($context, 'images', 'Zdjęcia muszą być listą.');

            return;
        }
        if (\count($images) > self::MAX_IMAGES) {
            self::add($context, 'images', 'Produkt może mieć najwyżej 10 zdjęć.');

            return;
        }
        $seen = [];
        foreach ($images as $index => $image) {
            if (!\is_array($image) || array_is_list($image) && [] !== $image) {
                self::add($context, \sprintf('images[%d]', $index), 'Zdjęcie jest nieprawidłowe.');

                continue;
            }
            $key = $image['key'] ?? null;
            if (!\is_string($key) || !self::isImageKey($key)) {
                self::add($context, \sprintf('images[%d].key', $index), 'Klucz zdjęcia jest nieprawidłowy.');
            } elseif (isset($seen[$key])) {
                self::add($context, \sprintf('images[%d].key', $index), 'To zdjęcie już jest w produkcie.');
            } else {
                $seen[$key] = true;
            }

            $alt = $image['alt'] ?? null;
            if (!\is_string($alt) || '' === trim($alt)) {
                self::add($context, \sprintf('images[%d].alt', $index), 'Podaj tekst alternatywny zdjęcia.');
            } elseif (mb_strlen($alt) > self::MAX_ALT_LENGTH) {
                self::add($context, \sprintf('images[%d].alt', $index), 'Tekst alternatywny może mieć najwyżej 200 znaków.');
            }
        }
    }

    /**
     * @return int|null the number of axes when the axes are a valid list, null when they are not (the rows are then
     *                  checked only for their own shape)
     */
    private function axes(ExecutionContextInterface $context, mixed $axes): ?int
    {
        if (!\is_array($axes) || !array_is_list($axes)) {
            self::add($context, 'variantAxes', 'Osie wariantów muszą być listą.');

            return null;
        }
        if (\count($axes) > VariantAxes::MAX_AXES) {
            self::add($context, 'variantAxes', 'Produkt może mieć najwyżej 2 osie wariantów.');

            return null;
        }
        $valid = true;
        $seen = [];
        foreach ($axes as $index => $name) {
            $path = \sprintf('variantAxes[%d]', $index);
            $trimmed = \is_string($name) ? trim($name) : null;
            if (null === $trimmed || '' === $trimmed || mb_strlen($trimmed) > VariantAxes::MAX_NAME_LENGTH) {
                self::add($context, $path, 'Nazwa osi musi mieć od 1 do 30 znaków.');
                $valid = false;
            } elseif (str_contains($trimmed, ':')) {
                self::add($context, $path, 'Nazwa osi nie może zawierać dwukropka.');
                $valid = false;
            } elseif (isset($seen[mb_strtolower($trimmed)])) {
                self::add($context, $path, 'Nazwa osi już jest użyta.');
                $valid = false;
            } else {
                $seen[mb_strtolower($trimmed)] = true;
            }
        }

        return $valid ? \count($axes) : null;
    }

    private function variants(ExecutionContextInterface $context, mixed $variants, ?int $axisCount): void
    {
        if (!\is_array($variants) || !array_is_list($variants)) {
            self::add($context, 'variants', 'Warianty muszą być listą.');

            return;
        }
        if (\count($variants) < 1 || \count($variants) > ProductVariants::MAX_VARIANTS) {
            self::add($context, 'variants', 'Produkt musi mieć od 1 do 100 wariantów.');

            return;
        }
        if (0 === $axisCount && \count($variants) > 1) {
            self::add($context, 'variants', 'Produkt bez osi ma tylko jeden wariant.');
        }

        $ids = [];
        $combinations = [];
        foreach ($variants as $index => $variant) {
            if (!\is_array($variant) || array_is_list($variant) && [] !== $variant) {
                self::add($context, \sprintf('variants[%d]', $index), 'Wariant jest nieprawidłowy.');

                continue;
            }

            $idPath = \sprintf('variants[%d].variantId', $index);
            $id = $variant['variantId'] ?? null;
            if (null !== $id) {
                $normalized = \is_string($id) ? self::variantId($id) : null;
                if (null === $normalized) {
                    self::add($context, $idPath, 'Identyfikator wariantu jest nieprawidłowy.');
                } elseif (isset($ids[$normalized])) {
                    self::add($context, $idPath, 'Ten wariant jest powtórzony.');
                } else {
                    $ids[$normalized] = true;
                }
            } elseif (!\array_key_exists('variantId', $variant)) {
                self::add($context, $idPath, 'Identyfikator wariantu jest nieprawidłowy.');
            }

            $this->values($context, $index, $variant['values'] ?? null, $axisCount, $combinations);
        }
    }

    /**
     * @param array<string, true> $combinations the combinations of the rows before, by their comparison key
     */
    private function values(ExecutionContextInterface $context, int $index, mixed $values, ?int $axisCount, array &$combinations): void
    {
        $path = \sprintf('variants[%d].values', $index);
        if (!\is_array($values) || !array_is_list($values)) {
            self::add($context, $path, 'Wartości wariantu muszą być listą.');

            return;
        }
        if (null !== $axisCount && \count($values) !== $axisCount) {
            self::add($context, $path, 'Wariant musi mieć jedną wartość dla każdej osi.');

            return;
        }

        $valid = true;
        foreach ($values as $position => $value) {
            $trimmed = \is_string($value) ? trim($value) : null;
            if (null === $trimmed || '' === $trimmed || mb_strlen($trimmed) > VariantValues::MAX_VALUE_LENGTH) {
                self::add($context, \sprintf('%s[%d]', $path, $position), 'Wartość musi mieć od 1 do 40 znaków.');
                $valid = false;
            }
        }
        // A product without axes has one row, so there is nothing to compare.
        if (!$valid || 0 === $axisCount || [] === $values) {
            return;
        }

        /** @var list<string> $values */
        $combination = json_encode(VariantValues::fromList($values)->comparisonKey(), \JSON_THROW_ON_ERROR);
        if (isset($combinations[$combination])) {
            self::add($context, $path, 'Taki wariant już istnieje.');
        }
        $combinations[$combination] = true;
    }

    private static function add(ExecutionContextInterface $context, string $path, string $message): void
    {
        $context->buildViolation($message)->atPath($path)->addViolation();
    }

    private static function isSlug(string $slug): bool
    {
        try {
            new Slug($slug);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function isImageKey(string $key): bool
    {
        try {
            ImageKey::fromString($key);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function categoryId(string $id): ?string
    {
        try {
            return CategoryId::fromString($id)->toString();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function variantId(string $id): ?string
    {
        try {
            return VariantId::fromString($id)->toString();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
