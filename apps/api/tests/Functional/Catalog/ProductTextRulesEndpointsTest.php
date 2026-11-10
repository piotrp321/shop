<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The text rules of POST and PUT /api/v1/admin/products: no control characters (and no U+0085, U+2028, U+2029, nor
 * invalid UTF-8) in any text, a line feed only in the short description, Unicode whitespace trimmed and an empty text
 * after the trim treated as an empty one.
 */
final class ProductTextRulesEndpointsTest extends WebTestCase
{
    use Factories;
    use ProductAdminEndpointTestTrait;

    private const string PRODUCTS = '/api/v1/admin/products';
    private const string PLACEHOLDER = 'TEXT_UNDER_TEST';

    /**
     * @return array<string, array{path: string, forbidden: string, required: ?string}>
     */
    private static function fields(): array
    {
        return [
            'name' => ['path' => 'name', 'forbidden' => 'Nazwa produktu zawiera niedozwolone znaki.', 'required' => 'Podaj nazwę produktu.'],
            'shortDescription' => ['path' => 'shortDescription', 'forbidden' => 'Krótki opis zawiera niedozwolone znaki.', 'required' => null],
            'alt' => ['path' => 'images[0].alt', 'forbidden' => 'Tekst alternatywny zawiera niedozwolone znaki.', 'required' => 'Podaj tekst alternatywny zdjęcia.'],
            'axis' => ['path' => 'variantAxes[0]', 'forbidden' => 'Nazwa osi zawiera niedozwolone znaki.', 'required' => 'Nazwa osi musi mieć od 1 do 30 znaków.'],
            'value' => ['path' => 'variants[0].values[0]', 'forbidden' => 'Wartość wariantu zawiera niedozwolone znaki.', 'required' => 'Wartość musi mieć od 1 do 40 znaków.'],
        ];
    }

    /**
     * @return array<string, mixed> the changes of a valid body that put the text in the field
     */
    private function changesWith(string $field, string $text): array
    {
        return match ($field) {
            'name' => ['name' => $text],
            'shortDescription' => ['shortDescription' => $text],
            'alt' => ['images' => [['key' => $this->originalKey(), 'alt' => $text]]],
            'axis' => ['variantAxes' => [$text]],
            'value' => ['variants' => [['variantId' => null, 'values' => [$text]], ['variantId' => null, 'values' => ['L']]]],
            default => throw new \LogicException(\sprintf('Unknown field %s.', $field)),
        };
    }

    /**
     * Sends the write as the given method: POST creates, PUT edits a product created first.
     *
     * @param array<string, mixed> $changes
     */
    private function write(string $method, array $changes, ?string $rawInstead = null): void
    {
        $path = self::PRODUCTS;
        $body = $this->body($changes);
        if ('PUT' === $method) {
            $view = $this->createProduct(['slug' => 'istniejacy']);
            $path .= '/'.self::string($view['id']);
            $body = $this->editBody($view, $changes);
        }
        $json = json_encode($body, \JSON_THROW_ON_ERROR);
        if (null !== $rawInstead) {
            $json = str_replace(self::PLACEHOLDER, $rawInstead, $json);
        }
        $this->client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin']),
        ], content: $json);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function forbiddenTexts(): iterable
    {
        $texts = ['NUL' => "a\x00b", 'BEL' => "a\x07b", 'line separator' => "a\u{2028}b", 'NUL at the end' => "ab\x00"];
        foreach (['POST', 'PUT'] as $method) {
            foreach (array_keys(self::fields()) as $field) {
                foreach ($texts as $name => $text) {
                    yield "$method $field with $name" => [$method, $field, $text];
                }
            }
        }
    }

    #[DataProvider('forbiddenTexts')]
    public function testForbiddenCharactersAreRejectedAtTheirField(string $method, string $field, string $text): void
    {
        $this->write($method, $this->changesWith($field, $text));

        self::assertSame(
            [['propertyPath' => self::fields()[$field]['path'], 'title' => self::fields()[$field]['forbidden']]],
            $this->violations(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function methodsAndSingleLineFields(): iterable
    {
        foreach (['POST', 'PUT'] as $method) {
            foreach (array_keys(self::fields()) as $field) {
                if ('shortDescription' !== $field) {
                    yield "$method $field" => [$method, $field];
                }
            }
        }
    }

    #[DataProvider('methodsAndSingleLineFields')]
    public function testALineFeedIsRejectedInEveryTextButTheShortDescription(string $method, string $field): void
    {
        $this->write($method, $this->changesWith($field, "a\nb"));

        self::assertSame(
            [['propertyPath' => self::fields()[$field]['path'], 'title' => self::fields()[$field]['forbidden']]],
            $this->violations(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function methodsAndFields(): iterable
    {
        foreach (['POST', 'PUT'] as $method) {
            foreach (array_keys(self::fields()) as $field) {
                yield "$method $field" => [$method, $field];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidUtf8(): iterable
    {
        foreach (['POST', 'PUT'] as $method) {
            foreach (array_keys(self::fields()) as $field) {
                yield "$method $field with a malformed byte" => [$method, $field, "a\xC3\x28b"];
                yield "$method $field with a lone low surrogate" => [$method, $field, 'a\udc80b'];
                yield "$method $field with a lone high surrogate" => [$method, $field, 'a\ud800b'];
            }
        }
    }

    /**
     * Invalid UTF-8 never reaches the validator of a write: the JSON decoder refuses a body with a malformed byte or a
     * lone surrogate escape first, so the answer is 400 and nothing is saved or changed. The rule for such a text in
     * the validator and in the value objects is covered by ProductWriteValidationTest and ProductTextTest.
     */
    #[DataProvider('invalidUtf8')]
    public function testInvalidUtf8CannotBeDeliveredToAFieldAndIsABadRequestNotAServerError(string $method, string $field, string $json): void
    {
        $this->write($method, $this->changesWith($field, self::PLACEHOLDER), $json);

        $this->assertProblem(400);
        self::assertSame('PUT' === $method ? 1 : 0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        if ('PUT' === $method) {
            self::assertSame(1, $this->connection->fetchOne('SELECT version FROM catalog_product'));
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function whitespaceOnlyTexts(): iterable
    {
        $texts = ['non-breaking spaces' => "\u{00A0}\u{00A0}", 'ideographic space' => "\u{3000}", 'mixed' => " \u{2003}\u{00A0}"];
        foreach (['POST', 'PUT'] as $method) {
            foreach (array_keys(self::fields()) as $field) {
                if ('shortDescription' === $field) {
                    continue;
                }
                foreach ($texts as $name => $text) {
                    yield "$method $field of $name" => [$method, $field, $text];
                }
            }
        }
    }

    #[DataProvider('whitespaceOnlyTexts')]
    public function testOnlyUnicodeWhitespaceIsTheSameAsAnEmptyRequiredText(string $method, string $field, string $text): void
    {
        $this->write($method, $this->changesWith($field, $text));

        self::assertSame(
            [['propertyPath' => self::fields()[$field]['path'], 'title' => self::fields()[$field]['required'] ?? '']],
            $this->violations(),
        );
    }

    public function testAProductNameOfOnlyUnicodeWhitespaceIsAViolationOfTheNameNotOfTheDerivedSlug(): void
    {
        $this->write('POST', ['name' => "\u{00A0}\u{00A0}", 'slug' => '']);

        self::assertSame([['propertyPath' => 'name', 'title' => 'Podaj nazwę produktu.']], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
    }

    #[DataProvider('methods')]
    public function testAShortDescriptionOfOnlyUnicodeWhitespaceIsSavedEmpty(string $method): void
    {
        $this->write($method, ['shortDescription' => "\u{00A0}\n\u{3000}"]);

        self::assertResponseIsSuccessful();
        $view = $this->decoded();
        self::assertSame('', $view['shortDescription']);
        self::assertSame('', $this->connection->fetchOne('SELECT short_description FROM catalog_product WHERE id = ?', [$view['id']]));
    }

    #[DataProvider('methods')]
    public function testALineFeedInTheShortDescriptionIsSavedAndUnicodeWhitespaceAtItsEndsIsTrimmed(string $method): void
    {
        $this->write($method, ['shortDescription' => "\u{00A0}Pierwsza linia\nDruga linia\u{00A0}\n"]);

        self::assertResponseIsSuccessful();
        $view = $this->decoded();
        self::assertSame("Pierwsza linia\nDruga linia", $view['shortDescription']);
        self::assertSame("Pierwsza linia\nDruga linia", $this->connection->fetchOne('SELECT short_description FROM catalog_product WHERE id = ?', [$view['id']]));
    }

    #[DataProvider('methods')]
    public function testTextsAreSavedTrimmedOfUnicodeWhitespace(string $method): void
    {
        $this->write($method, [
            'name' => "\u{00A0}Buty biegowe\u{3000}",
            'images' => [['key' => $this->originalKey(), 'alt' => "\u{00A0}Buty z boku\u{00A0}"]],
            'variantAxes' => ["\u{00A0}Rozmiar\u{00A0}"],
            'variants' => [['variantId' => null, 'values' => ["\u{3000}M"]], ['variantId' => null, 'values' => ["L\u{00A0}"]]],
        ]);

        self::assertResponseIsSuccessful();
        $view = $this->decoded();
        self::assertSame('Buty biegowe', $view['name']);
        self::assertSame('Buty z boku', self::list($view['images'])[0]['alt']);
        self::assertSame(['Rozmiar'], $view['variantAxes']);
        self::assertSame([['M'], ['L']], array_column(self::list($view['variants']), 'values'));
    }

    public function testTheLimitsAreCountedOnTheTrimmedText(): void
    {
        $this->write('POST', ['name' => "\u{00A0}".str_repeat('a', 200)."\u{00A0}"]);

        self::assertResponseStatusCodeSame(201);
    }
}
