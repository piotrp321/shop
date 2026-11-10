<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Validation;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductText;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
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
        if (!\is_string($name)) {
            self::add($context, 'name', 'Podaj nazwę produktu.');
        } elseif (ProductText::hasForbiddenCharacters($name)) {
            self::add($context, 'name', 'Nazwa produktu zawiera niedozwolone znaki.');
        } elseif ('' === ProductText::trim($name)) {
            self::add($context, 'name', 'Podaj nazwę produktu.');
        } elseif (ProductText::length($name) > ProductName::MAX_LENGTH) {
            self::add($context, 'name', \sprintf('Nazwa może mieć najwyżej %d znaków.', ProductName::MAX_LENGTH));
        }
    }

    private function slug(ExecutionContextInterface $context, mixed $slug): void
    {
        if (!\is_string($slug)) {
            self::add($context, 'slug', 'Slug musi być tekstem.');
        } elseif (mb_strlen($slug) > Slug::MAX_LENGTH) {
            self::add($context, 'slug', \sprintf('Slug może mieć najwyżej %d znaków.', Slug::MAX_LENGTH));
        } elseif ('' !== $slug && !self::isSlug($slug)) {
            self::add($context, 'slug', 'Slug może zawierać tylko małe litery bez polskich znaków, cyfry i pojedyncze myślniki.');
        }
    }

    private function shortDescription(ExecutionContextInterface $context, mixed $description): void
    {
        if (!\is_string($description)) {
            self::add($context, 'shortDescription', 'Krótki opis musi być tekstem.');
        } elseif (ProductText::hasForbiddenCharacters($description, allowLineFeed: true)) {
            self::add($context, 'shortDescription', 'Krótki opis zawiera niedozwolone znaki.');
        } elseif (ProductText::length($description) > ShortDescription::MAX_LENGTH) {
            self::add($context, 'shortDescription', \sprintf('Krótki opis może mieć najwyżej %d znaków.', ShortDescription::MAX_LENGTH));
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
        if (\count($additional) > ProductCategories::MAX_ADDITIONAL) {
            self::add($context, 'additionalCategoryIds', \sprintf('Produkt może mieć najwyżej %d kategorii dodatkowych.', ProductCategories::MAX_ADDITIONAL));

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
        if (\count($images) > ProductImages::MAX_IMAGES) {
            self::add($context, 'images', \sprintf('Produkt może mieć najwyżej %d zdjęć.', ProductImages::MAX_IMAGES));

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
            $altPath = \sprintf('images[%d].alt', $index);
            if (!\is_string($alt)) {
                self::add($context, $altPath, 'Podaj tekst alternatywny zdjęcia.');
            } elseif (ProductText::hasForbiddenCharacters($alt)) {
                self::add($context, $altPath, 'Tekst alternatywny zawiera niedozwolone znaki.');
            } elseif ('' === ProductText::trim($alt)) {
                self::add($context, $altPath, 'Podaj tekst alternatywny zdjęcia.');
            } elseif (ProductText::length($alt) > ProductImage::MAX_ALT_LENGTH) {
                self::add($context, $altPath, \sprintf('Tekst alternatywny może mieć najwyżej %d znaków.', ProductImage::MAX_ALT_LENGTH));
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
            self::add($context, 'variantAxes', \sprintf('Produkt może mieć najwyżej %d osie wariantów.', VariantAxes::MAX_AXES));

            return null;
        }
        $valid = true;
        $seen = [];
        foreach ($axes as $index => $name) {
            $path = \sprintf('variantAxes[%d]', $index);
            if (\is_string($name) && ProductText::hasForbiddenCharacters($name)) {
                self::add($context, $path, 'Nazwa osi zawiera niedozwolone znaki.');
                $valid = false;

                continue;
            }
            $trimmed = \is_string($name) ? ProductText::trim($name) : null;
            if (null === $trimmed || '' === $trimmed || mb_strlen($trimmed) > VariantAxes::MAX_NAME_LENGTH) {
                self::add($context, $path, \sprintf('Nazwa osi musi mieć od 1 do %d znaków.', VariantAxes::MAX_NAME_LENGTH));
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
            self::add($context, 'variants', \sprintf('Produkt musi mieć od 1 do %d wariantów.', ProductVariants::MAX_VARIANTS));

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
            $valuePath = \sprintf('%s[%d]', $path, $position);
            if (\is_string($value) && ProductText::hasForbiddenCharacters($value)) {
                self::add($context, $valuePath, 'Wartość wariantu zawiera niedozwolone znaki.');
                $valid = false;

                continue;
            }
            $trimmed = \is_string($value) ? ProductText::trim($value) : null;
            if (null === $trimmed || '' === $trimmed || mb_strlen($trimmed) > VariantValues::MAX_VALUE_LENGTH) {
                self::add($context, $valuePath, \sprintf('Wartość musi mieć od 1 do %d znaków.', VariantValues::MAX_VALUE_LENGTH));
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
